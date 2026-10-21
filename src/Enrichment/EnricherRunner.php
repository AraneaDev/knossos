<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Configuration\EnricherDefinition;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\ContributionParams;
use Knossos\Scanner\Worker\NdjsonRpcChannel;
use Knossos\Scanner\Worker\ScannerProtocolSession;
use Knossos\Scanner\Worker\WorkerErrorCode;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Scanner\Worker\WorkerProcessSupervisor;
use Throwable;

/**
 * Runs one installation-defined enricher over the worker protocol.
 *
 * The enricher is started like a language worker (no shell, the allow-listed
 * environment plus its own `env`, stdout for frames, stderr captured and
 * never passed through), must declare the enrichment capability, and gets one
 * scan request: the project root and the native nodes of the kinds it asked
 * for. Whatever goes wrong becomes a failed outcome; nothing it does throws
 * into the scan.
 *
 * The definition's `timeout_seconds` bounds the whole run, start to answer:
 * every protocol request gets what is left of one deadline, not a fresh one.
 */
final class EnricherRunner
{
    /** The longest part of a failure's own message an outcome keeps. */
    private const DETAIL_CHARACTERS = 200;

    /**
     * Start the enricher, send it the native nodes it declared, and decode what it contributes.
     *
     * @param (callable(): bool)|null $cancelled polled while waiting for the enricher; true stops it
     */
    public function run(EnricherDefinition $def, string $root, NativeNodeIndex $native, ?callable $cancelled = null): EnricherOutcome
    {
        // Each request is capped at the timeout, and the channel caps it again
        // at what is left of the run, so the whole run ends by the timeout.
        $milliseconds = $def->timeoutSeconds * 1000;
        $limits = new WorkerLimits(requestTimeoutMs: $milliseconds, maxRequestMs: $milliseconds);
        $process = new WorkerProcessSupervisor($def->command, array_replace(WorkerProcessSupervisor::defaultEnvironment(), $def->environment));
        $channel = new EnrichmentChannel(new NdjsonRpcChannel($process, $limits), hrtime(true) + $milliseconds * 1_000_000);
        $session = new ScannerProtocolSession($process, $channel);
        try {
            $session->requireCapabilities([Protocol::CAPABILITY_ENRICHMENT]);
            $request = ['root' => $root, 'native_nodes' => $native->nodesOfKinds($def->inputNodeKinds)];
            foreach ($session->scan($request, $cancelled) as $ignored) {
                // Every contribution is taken by the channel, so the session yields none.
            }
            $raw = self::decode($def->name, $channel->takeContributions());
            $session->shutdown();

            return EnricherOutcome::succeeded($def->name, $raw);
        } catch (WorkerException $error) {
            return EnricherOutcome::failed($def->name, self::reason($error), self::detail($error));
        } catch (Throwable $error) {
            return EnricherOutcome::failed($def->name, 'protocol_error', self::detail($error));
        } finally {
            $session->close(true);
        }
    }

    /** The outcome reason for a protocol failure, telling a clean exit 0 apart from a crash. */
    private static function reason(WorkerException $error): string
    {
        if ($error->exitCode === 0) {
            return 'exited_early';
        }

        // The worker-protocol failures that have a reason of their own; any
        // other is a `protocol_error`.
        return match (WorkerErrorCode::of($error)) {
            WorkerErrorCode::StartFailed => 'missing_command',
            WorkerErrorCode::CapabilityMismatch => 'no_enrichment_capability',
            WorkerErrorCode::Timeout => 'timeout',
            WorkerErrorCode::Exited => 'exit_nonzero',
            WorkerErrorCode::Cancelled => 'cancelled',
            default => 'protocol_error',
        };
    }

    /** The first line of a failure's message, capped for a diagnostic. */
    private static function detail(Throwable $error): string
    {
        return mb_substr(strtok($error->getMessage(), "\n") ?: get_class($error), 0, self::DETAIL_CHARACTERS);
    }

    /**
     * Decode every contribution the enricher sent into one, defaulting what the enricher may leave out.
     *
     * Strict: one malformed fact fails the run, so a broken enricher keeps its
     * previous facts rather than replacing them with nothing.
     *
     * @param list<mixed> $contributions raw notification params
     */
    private static function decode(string $name, array $contributions): ScanContribution
    {
        $nodes = [];
        $edges = [];
        $diagnostics = [];
        foreach ($contributions as $raw) {
            $params = ContributionParams::of($raw);
            foreach (['nodes', 'edges'] as $field) {
                if (is_array($params[$field] ?? null)) {
                    $params[$field] = array_map(self::withDefaults(...), $params[$field]);
                }
            }
            $decoded = ContributionDecoder::decode($params, false);
            array_push($nodes, ...$decoded->nodes);
            array_push($edges, ...$decoded->edges);
            array_push($diagnostics, ...$decoded->diagnostics);
        }

        return new ScanContribution('enricher:' . $name, $nodes, $edges, $diagnostics);
    }

    /**
     * A fact with a missing confidence set to `probable` and a missing origin to `enricher`.
     *
     * Both are overruled by validation anyway (origin always becomes
     * `enricher`, confidence never exceeds `probable`), so leaving them out is
     * no reason to refuse the fact.
     */
    private static function withDefaults(mixed $fact): mixed
    {
        if (!is_array($fact) || array_is_list($fact)) {
            return $fact;
        }
        if (!array_key_exists('confidence', $fact)) {
            $fact['confidence'] = 'probable';
        }
        if (!array_key_exists('origin', $fact)) {
            $fact['origin'] = 'enricher';
        }

        return $fact;
    }
}

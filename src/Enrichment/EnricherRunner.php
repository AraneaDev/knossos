<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Discovery\EnricherDefinition;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\ContributionDecoder;
use Knossos\Scanner\Worker\NdjsonRpcChannel;
use Knossos\Scanner\Worker\ScannerProtocolSession;
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
 */
final class EnricherRunner
{
    /** The worker-protocol failures that have a reason of their own; any other is a `protocol_error`. */
    private const REASONS = [
        'WORKER_START_FAILED' => 'missing_command',
        'WORKER_CAPABILITY_MISMATCH' => 'no_enrichment_capability',
        'WORKER_TIMEOUT' => 'timeout',
        'WORKER_EXITED' => 'exit_nonzero',
    ];

    /** Start the enricher, send it the native nodes it declared, and decode what it contributes. */
    public function run(EnricherDefinition $def, string $root, NativeNodeIndex $native): EnricherOutcome
    {
        // The timeout bounds both a silent enricher and a busy one: an enricher
        // that keeps sending heartbeats still stops at its configured time.
        $milliseconds = $def->timeoutSeconds * 1000;
        $limits = new WorkerLimits(requestTimeoutMs: $milliseconds, maxRequestMs: $milliseconds);
        $process = new WorkerProcessSupervisor($def->command, array_replace(WorkerProcessSupervisor::defaultEnvironment(), $def->environment));
        $channel = new EnrichmentChannel(new NdjsonRpcChannel($process, $limits));
        $session = new ScannerProtocolSession($process, $channel);
        try {
            $session->requireCapabilities([Protocol::CAPABILITY_ENRICHMENT]);
            $request = ['root' => $root, 'native_nodes' => $native->nodesOfKinds($def->inputNodeKinds)];
            foreach ($session->scan($request) as $ignored) {
                // Every contribution is taken by the channel, so the session yields none.
            }
            $raw = self::decode($def->name, $channel->takeContributions());
            $session->shutdown();

            return EnricherOutcome::succeeded($def->name, $raw);
        } catch (WorkerException $error) {
            return EnricherOutcome::failed($def->name, self::REASONS[$error->diagnosticCode] ?? 'protocol_error');
        } catch (Throwable) {
            return EnricherOutcome::failed($def->name, 'protocol_error');
        } finally {
            $session->close(true);
        }
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
        foreach ($contributions as $params) {
            if (!is_array($params) || array_is_list($params)) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Contribution params must be an object.');
            }
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

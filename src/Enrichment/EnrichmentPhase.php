<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Closure;
use Knossos\Cancellation\CancellationToken;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\ScanContribution;
use PDO;
use Throwable;

/**
 * The enrichment step of a scan: one contribution per enricher the project requests.
 *
 * Runs after the native workers and before reconcile. An explicit scan runs
 * each requested and installed enricher and validates what it returns; a
 * background scan, or a run that failed, carries the enricher's last facts
 * over instead. Nothing here throws into the scan: whatever goes wrong for
 * one enricher becomes that enricher's diagnostic. Every diagnostic goes into
 * the enricher's own contribution, never into the worker diagnostics that
 * make a rescan refuse to commit.
 *
 * An enricher the project no longer requests, or a clean `enrichers.json`
 * that does not define it, contributes no facts, so reconcile prunes what it
 * left. A broken `enrichers.json` is a failure instead, and keeps the facts.
 */
final class EnrichmentPhase
{
    /** The longest part of a failure's own message a diagnostic repeats. */
    private const DETAIL_CHARACTERS = 200;

    /** @var list<EnricherDefinition> */
    private array $active = [];

    /** @var array<string, EnricherDefinition> */
    private array $definitions = [];

    /** @var list<string> */
    private array $problems = [];

    /** The configuration {@see prepare()} last read the file for, so a scan reads it once. */
    private ?ProjectConfiguration $prepared = null;

    /** @param (Closure(): string)|null $clock the ISO-8601 time now, injected by a test */
    public function __construct(
        private PDO $pdo,
        private ?EnrichersFile $enrichers,
        private EnricherRunner $runner = new EnricherRunner(),
        private ?Closure $clock = null,
    ) {}

    /**
     * Read the installation's enrichers for this project's request, without starting or reading anything else.
     *
     * Enough for {@see active()}, so a scan can decide on its fast path before
     * paying for {@see contributions()}, which calls this itself when it was not.
     */
    public function prepare(ProjectConfiguration $config): void
    {
        $this->prepared = $config;
        $this->active = [];
        $this->definitions = [];
        $this->problems = [];
        if ($config->enrichers === []) {
            return;
        }
        try {
            [$this->definitions, $this->problems] = $this->enrichers?->load() ?? [[], []];
        } catch (Throwable $error) {
            $this->problems = [sprintf('enrichers.json could not be read: %s', self::detail($error))];
        }
        foreach ($config->enrichers as $name) {
            if (isset($this->definitions[$name])) {
                $this->active[] = $this->definitions[$name];
            }
        }
    }

    /**
     * The contributions the project's enrichers add to this scan.
     *
     * @param list<ScanContribution> $native the native workers' contributions, reused ones included
     * @param array<string, true> $discoveredPaths project-relative paths this scan discovered
     * @return list<ScanContribution>
     */
    public function contributions(
        ProjectConfiguration $config,
        string $projectId,
        string $scanId,
        string $root,
        array $native,
        array $discoveredPaths,
        bool $runEnrichers,
        ?CancellationToken $cancellation = null,
    ): array {
        if ($this->prepared !== $config) {
            $this->prepare($config);
        }
        $this->prepared = null;
        if ($config->enrichers === []) {
            return [];
        }
        try {
            $index = NativeNodeIndex::build($projectId, $native);
        } catch (Throwable $error) {
            $index = $error;
        }
        $carryOver = new EnricherCarryOver($this->pdo, $discoveredPaths);
        $contributions = [];
        if ($this->problems !== []) {
            $contributions[] = new ScanContribution('enricher:enrichers.json', [], [], array_map(
                static fn(string $problem): Diagnostic => new Diagnostic('warning', 'ENRICHER_CONFIG_INVALID', $problem),
                $this->problems,
            ));
        }
        foreach ($config->enrichers as $name) {
            try {
                if ($index instanceof Throwable) {
                    throw $index;
                }
                $contributions[] = $this->contribution($name, $projectId, $scanId, $root, $index, $discoveredPaths, $carryOver, $runEnrichers, $cancellation);
            } catch (Throwable $error) {
                $contributions[] = new ScanContribution('enricher:' . $name, [], [], [new Diagnostic('warning', 'ENRICHER_FAILED', sprintf(
                    'Enricher %s could not be handled (internal_error: %s); this scan has none of its facts.',
                    $name,
                    self::detail($error),
                ))]);
            }
        }

        return $contributions;
    }

    /**
     * The enrichers the last {@see prepare()} found requested and installed.
     *
     * They identify the scanner set whether or not they ran, so a scan that
     * runs them and one that carries their facts over hash it the same way.
     * One that a broken `enrichers.json` hides is left out: its hash changes
     * until the file is fixed, which only costs the fast path meanwhile.
     *
     * @return list<EnricherDefinition>
     */
    public function active(): array
    {
        return $this->active;
    }

    /**
     * One requested enricher's contribution: run, carried over, or none.
     *
     * @param array<string, true> $discoveredPaths
     */
    private function contribution(
        string $name,
        string $projectId,
        string $scanId,
        string $root,
        NativeNodeIndex $index,
        array $discoveredPaths,
        EnricherCarryOver $carryOver,
        bool $runEnrichers,
        ?CancellationToken $cancellation,
    ): ScanContribution {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null && $this->problems !== []) {
            // The file is broken, so it says nothing about whether the name is still defined.
            return self::carried($carryOver->previous($projectId, $name, $index), 'warning', 'ENRICHER_FAILED', sprintf(
                'Enricher %s failed (config_invalid): enrichers.json has problems, so the name may be undefined or rejected; its previous facts are kept.',
                $name,
            ));
        }
        if ($definition === null) {
            return new ScanContribution('enricher:' . $name, [], [], [new Diagnostic(
                'warning',
                'ENRICHER_NOT_INSTALLED',
                sprintf('Enricher %s is requested by the project but not defined in the installation\'s enrichers.json; it was skipped and its facts removed.', $name),
            )]);
        }
        if (!$runEnrichers) {
            return self::carried($carryOver->previous($projectId, $name, $index), 'info', 'ENRICHER_NOT_RUN', sprintf(
                'Enricher %s was not run: only an explicit scan runs enrichers, so its previous facts are kept.',
                $name,
            ));
        }
        $outcome = $this->runner->run($definition, $root, $index, $cancellation === null ? null : $cancellation->isCancelled(...));
        if (!$outcome->ok || $outcome->raw === null) {
            return self::carried($carryOver->previous($projectId, $name, $index), 'warning', 'ENRICHER_FAILED', sprintf(
                'Enricher %s failed (%s%s); its previous facts are kept.',
                $name,
                (string) $outcome->reason,
                $outcome->detail === null ? '' : ': ' . $outcome->detail,
            ));
        }
        $validated = (new EnrichmentValidator($index, $discoveredPaths))->validate($name, $scanId, $this->now(), $outcome->raw);

        return self::withDiagnostics($validated, []);
    }

    /**
     * A carried-over contribution with one diagnostic saying why, and when its facts were produced.
     *
     * The time is re-stated on every scan that carries the facts, because
     * each reconcile clears the diagnostics of the scan before.
     */
    private static function carried(ValidatedEnrichment $carried, string $severity, string $code, string $message): ScanContribution
    {
        $producedAt = null;
        foreach ([...$carried->contribution->nodes, ...$carried->contribution->edges] as $fact) {
            $producedAt = $fact->attributes['enricher_produced_at'] ?? null;
            if (is_string($producedAt) && $producedAt !== '') {
                break;
            }
        }
        $message .= is_string($producedAt) && $producedAt !== ''
            ? sprintf(' They were produced at %s.', $producedAt)
            : ' There are none from an earlier run.';

        return self::withDiagnostics($carried, [new Diagnostic($severity, $code, $message)]);
    }

    /**
     * The validated contribution with the warnings for its dropped facts and any further diagnostics added.
     *
     * @param list<Diagnostic> $extra
     */
    private static function withDiagnostics(ValidatedEnrichment $validated, array $extra): ScanContribution
    {
        $contribution = $validated->contribution;

        return new ScanContribution(
            $contribution->ownerKey,
            $contribution->nodes,
            $contribution->edges,
            [...$extra, ...$contribution->diagnostics, ...$validated->dropped],
        );
    }

    /** The first line of a failure's message, short enough to repeat in a diagnostic. */
    private static function detail(Throwable $error): string
    {
        return mb_substr(strtok($error->getMessage(), "\n") ?: get_class($error), 0, self::DETAIL_CHARACTERS);
    }

    /** The time now, as enriched facts record it. */
    private function now(): string
    {
        return $this->clock === null ? gmdate('Y-m-d\TH:i:s\Z') : ($this->clock)();
    }
}

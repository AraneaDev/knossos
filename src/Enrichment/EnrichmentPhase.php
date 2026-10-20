<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Closure;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\EnricherDefinition;
use Knossos\Discovery\EnrichersFile;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\ScanContribution;
use PDO;

/**
 * The enrichment step of a scan: one contribution per enricher the project requests.
 *
 * Runs after the native workers and before reconcile. An explicit scan runs
 * each requested and installed enricher and validates what it returns; a
 * background scan, or a run that failed, carries the enricher's last facts
 * over instead. Nothing here throws into the scan, and every diagnostic goes
 * into the enricher's own contribution, never into the worker diagnostics
 * that make a rescan refuse to commit. An enricher the project no longer
 * requests, or the installation no longer defines, contributes no facts, so
 * reconcile prunes what it left.
 */
final class EnrichmentPhase
{
    /** @var list<EnricherDefinition> */
    private array $active = [];

    /** @param (Closure(): string)|null $clock the ISO-8601 time now, injected by a test */
    public function __construct(
        private PDO $pdo,
        private ?EnrichersFile $enrichers,
        private EnricherRunner $runner = new EnricherRunner(),
        private ?Closure $clock = null,
    ) {}

    /**
     * The contributions the project's enrichers add to this scan.
     *
     * @param list<ScanContribution> $native the native workers' contributions, reused ones included
     * @param array<string, true> $discoveredPaths project-relative paths this scan discovered
     * @return list<ScanContribution>
     */
    public function contributions(ProjectConfiguration $config, string $projectId, string $scanId, string $root, array $native, array $discoveredPaths, bool $runEnrichers): array
    {
        $this->active = [];
        if ($config->enrichers === []) {
            return [];
        }
        [$definitions, $problems] = $this->enrichers?->load() ?? [[], []];
        $index = NativeNodeIndex::build($projectId, $native);
        $carryOver = new EnricherCarryOver($this->pdo, $discoveredPaths);
        $contributions = [];
        if ($problems !== []) {
            $contributions[] = new ScanContribution('enricher:enrichers.json', [], [], array_map(
                static fn(string $problem): Diagnostic => new Diagnostic('warning', 'ENRICHER_CONFIG_INVALID', $problem),
                $problems,
            ));
        }
        foreach ($config->enrichers as $name) {
            $definition = $definitions[$name] ?? null;
            if ($definition === null) {
                $contributions[] = new ScanContribution('enricher:' . $name, [], [], [new Diagnostic(
                    'warning',
                    'ENRICHER_NOT_INSTALLED',
                    sprintf('Enricher %s is requested by the project but not defined in the installation\'s enrichers.json; it was skipped and its facts removed.', $name),
                )]);
                continue;
            }
            $this->active[] = $definition;
            $contributions[] = $runEnrichers
                ? $this->run($definition, $projectId, $scanId, $root, $index, $discoveredPaths, $carryOver)
                : self::carried($carryOver->previous($projectId, $name, $index), 'info', 'ENRICHER_NOT_RUN', sprintf(
                    'Enricher %s was not run: only an explicit scan runs enrichers, so its previous facts are kept.',
                    $name,
                ));
        }

        return $contributions;
    }

    /**
     * The enrichers the last {@see contributions()} call found requested and installed.
     *
     * They identify the scanner set whether or not they ran, so a scan that
     * runs them and one that carries their facts over hash it the same way.
     *
     * @return list<EnricherDefinition>
     */
    public function active(): array
    {
        return $this->active;
    }

    /**
     * Run one enricher and validate its output, or carry its last facts over when the run fails.
     *
     * @param array<string, true> $discoveredPaths
     */
    private function run(EnricherDefinition $definition, string $projectId, string $scanId, string $root, NativeNodeIndex $index, array $discoveredPaths, EnricherCarryOver $carryOver): ScanContribution
    {
        $outcome = $this->runner->run($definition, $root, $index);
        if (!$outcome->ok || $outcome->raw === null) {
            return self::carried($carryOver->previous($projectId, $definition->name, $index), 'warning', 'ENRICHER_FAILED', sprintf(
                'Enricher %s failed (%s); its previous facts are kept.',
                $definition->name,
                (string) $outcome->reason,
            ));
        }
        $validated = (new EnrichmentValidator($index, $discoveredPaths))->validate($definition->name, $scanId, $this->now(), $outcome->raw);

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

    /** The time now, as enriched facts record it. */
    private function now(): string
    {
        return $this->clock === null ? gmdate('Y-m-d\TH:i:s\Z') : ($this->clock)();
    }
}

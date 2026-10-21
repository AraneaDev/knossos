<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;

/**
 * What one language's batches add up to, as they arrive.
 *
 * Owned by the caller of a language run rather than the run itself, so the
 * source-byte budget the batches settled on is still readable when the run
 * throws.
 */
final class LanguageRunAccumulator
{
    /** @var list<ScanContribution> */
    private array $scanned = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * What each received contribution read. A file retried in a later batch
     * is overwritten there, like its contribution.
     *
     * @var array<string, array{reads: array<string, ?string>, group: ?string, attributed: bool}>
     */
    private array $readsByOwner = [];

    /** @var array<string, array<string, ?string>> the shared read sets the owners name */
    private array $groups = [];

    /** @var array<string, string> the environment of every program the requests built */
    private array $environments = [];

    /**
     * Reads of anything discovery did not hash, checked across this
     * language's requests as they arrive and handed back for the pre-commit
     * re-read.
     */
    private UndiscoveredInputs $undiscovered;

    /**
     * @param int $sourceBytes the language's source-byte batch budget; the
     *        batch queue writes it back through a reference as it is halved
     */
    public function __construct(public int $sourceBytes)
    {
        $this->undiscovered = new UndiscoveredInputs();
    }

    /**
     * Keep one batch's contributions, after checking what they read.
     *
     * @param array<string, mixed> $batchResult the request's final result
     * @param array<string, object> $discoveredByPath every path discovery hashed, keyed by relative path
     * @param list<ScanContribution> $received what the worker answered for the batch
     * @param list<string> $requested the paths the batch named
     */
    public function absorb(array $batchResult, ScannerManifest $manifest, array $discoveredByPath, array $received, array $requested): void
    {
        // Before this batch's contributions are kept: facts resolved against
        // another file's bytes must match what discovery hashed for it too.
        $verified = ScanInputHashes::verifyAll($batchResult, $manifest, $discoveredByPath);
        $this->undiscovered->add($verified['undiscovered']);
        $requestReads = RequestReads::forRequest($batchResult, $manifest, $verified['all'], $received, $requested);
        $this->readsByOwner = $requestReads['owners'] + $this->readsByOwner;
        $this->groups += $requestReads['groups'];
        $this->environments = ProgramEnvironments::fromResult($batchResult, $manifest->id) + $this->environments;
        // Evidence for these checks only, not statistics: kept out of the
        // scanner metadata a scan report carries.
        unset($batchResult['input_hashes'], $batchResult['reads'], $batchResult[RequestReads::UNATTRIBUTED], $batchResult[ProgramEnvironments::FIELD]);
        foreach ($received as $contribution) {
            $this->scanned[] = $contribution;
        }
        $this->metadata = self::mergeScanResult($this->metadata, $batchResult);
    }

    /** @return list<ScanContribution> every contribution kept, in arrival order */
    public function scanned(): array
    {
        return $this->scanned;
    }

    /** @return array<string, mixed> the language's merged scanner metadata */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** @return array<string, array{reads: array<string, ?string>, group: ?string, attributed: bool}> */
    public function readsByOwner(): array
    {
        return $this->readsByOwner;
    }

    /** @return array<string, array<string, ?string>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<string, string> */
    public function environments(): array
    {
        return $this->environments;
    }

    /** @return array<string, string|null> */
    public function undiscovered(): array
    {
        return $this->undiscovered->all();
    }

    /**
     * Fold one batch's scan reply into the language's running scanner metadata.
     *
     * Every integer a worker reports is a count of what THAT request did —
     * files_scanned, and TypeScript's programs and programs_reused — so they are
     * summed. Keeping the last batch's value would report a fraction of the
     * language's work as if it were the total. Non-integers (a parser name, a
     * version) are not counts, so the newest batch's value wins.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $batchResult
     * @return array<string, mixed>
     */
    public static function mergeScanResult(array $metadata, array $batchResult): array
    {
        foreach ($batchResult as $field => $value) {
            $running = $metadata[$field] ?? null;
            $metadata[$field] = is_int($value) && is_int($running) ? $running + $value : $value;
        }

        return $metadata;
    }
}

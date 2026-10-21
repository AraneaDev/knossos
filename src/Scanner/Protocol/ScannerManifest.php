<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;
use Knossos\Scanner\Worker\WorkerException;

/**
 * What a worker says about itself when it starts: id, version, protocol.
 *
 * Checked before any work is sent. The id also namespaces the facts the worker
 * owns, so an unexpected identity is refused rather than silently attributed to
 * whichever scanner was expected.
 */
final readonly class ScannerManifest implements JsonSerializable
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $version
     * @param list<non-empty-string> $languages
     * @param list<non-empty-string> $fileExtensions
     * @param list<non-empty-string> $capabilities
     * @param ?string $sourceHash hash of the source a compiled worker was built from, see {@see \Knossos\Runtime\WorkerSourceHash}
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $protocolVersion,
        public string $outputSchemaVersion,
        public array $languages,
        public array $fileExtensions,
        public array $capabilities,
        public ?string $sourceHash = null,
    ) {
        if ($id === '' || $version === '' || $protocolVersion === '' || $outputSchemaVersion === '') {
            throw new InvalidArgumentException('Scanner identity and version fields must not be empty.');
        }

        if ($languages === []) {
            throw new InvalidArgumentException('A scanner must support at least one language.');
        }

        self::assertNonEmptyStrings($languages, 'languages');
        self::assertNonEmptyStrings($fileExtensions, 'fileExtensions');
        self::assertNonEmptyStrings($capabilities, 'capabilities');
        if ($sourceHash !== null && preg_match('/^[0-9a-f]{64}$/D', $sourceHash) !== 1) {
            throw new InvalidArgumentException('Manifest field "source_hash" must be a lowercase hex SHA-256.');
        }
    }

    /**
     * Validate a worker's self-description from untrusted output.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'version', 'protocol_version', 'output_schema_version'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must be a string.', $field));
            }
        }

        foreach (['languages', 'file_extensions', 'capabilities'] as $field) {
            if (!isset($data[$field]) || !is_array($data[$field]) || !array_is_list($data[$field])) {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must be a list.', $field));
            }
        }

        /** @var list<string> $languages */
        $languages = $data['languages'];
        /** @var list<string> $fileExtensions */
        $fileExtensions = $data['file_extensions'];
        /** @var list<string> $capabilities */
        $capabilities = $data['capabilities'];
        $sourceHash = $data['source_hash'] ?? null;
        if ($sourceHash !== null && !is_string($sourceHash)) {
            throw new InvalidArgumentException('Manifest field "source_hash" must be a string.');
        }

        return new self(
            $data['id'],
            $data['version'],
            $data['protocol_version'],
            $data['output_schema_version'],
            $languages,
            $fileExtensions,
            $capabilities,
            $sourceHash,
        );
    }

    /**
     * Refuse a manifest that does not describe the worker the core launched:
     * another id, other languages, a source extension the core routes to these
     * languages left undeclared, or an `added_files_affect_all` capability the
     * core's own record of the language disagrees with.
     *
     * @param list<string> $languages
     * @param list<string> $routedExtensions
     * @throws WorkerException WORKER_MANIFEST_INVALID
     */
    public function assertDescribes(string $scannerId, array $languages, array $routedExtensions, bool $addedFilesAffectAll): void
    {
        $sortedOwn = $this->languages;
        $sortedExpected = $languages;
        sort($sortedOwn);
        sort($sortedExpected);
        $problem = match (true) {
            $this->id !== $scannerId => sprintf('answers as %s', $this->id),
            $sortedOwn !== $sortedExpected => sprintf('declares languages %s, not %s', implode(', ', $sortedOwn), implode(', ', $sortedExpected)),
            ($missing = array_values(array_diff($routedExtensions, $this->fileExtensions))) !== [] => sprintf('does not declare the extensions %s', implode(', ', $missing)),
            in_array(Protocol::CAPABILITY_ADDED_FILES_AFFECT_ALL, $this->capabilities, true) !== $addedFilesAffectAll => sprintf(
                '%s the %s capability',
                $addedFilesAffectAll ? 'lacks' : 'declares',
                Protocol::CAPABILITY_ADDED_FILES_AFFECT_ALL,
            ),
            default => null,
        };
        if ($problem !== null) {
            throw new WorkerException('WORKER_MANIFEST_INVALID', sprintf('The worker launched as %s %s.', $scannerId, $problem));
        }
    }

    /**
     * The wire shape of the manifest.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'protocol_version' => $this->protocolVersion,
            'output_schema_version' => $this->outputSchemaVersion,
            'languages' => $this->languages,
            'file_extensions' => $this->fileExtensions,
            'capabilities' => $this->capabilities,
        ] + ($this->sourceHash === null ? [] : ['source_hash' => $this->sourceHash]);
    }

    /**
     * Reject blank entries in a declared list, which would otherwise become a meaningless capability.
     *
     * @param list<mixed> $values
     */
    private static function assertNonEmptyStrings(array $values, string $field): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('Manifest field "%s" must contain non-empty strings.', $field));
            }
        }
    }
}

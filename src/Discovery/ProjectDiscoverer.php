<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Cancellation\CancellationToken;

/**
 * Discovers a project: the files worth analysing and the units that configure
 * them, in a stable order and hashed.
 *
 * {@see TreeWalker} walks the tree and reads the units, {@see EntryPointResolver}
 * adds the entry points that take more than one unit to see, and this class
 * orders and hashes the result. The classification statics stay here as
 * delegates to {@see SourceClassifier}, so callers that ask discovery's
 * questions about a path keep one entry point.
 */
final readonly class ProjectDiscoverer
{
    private TreeWalker $walker;

    /** @var array<string, string> */
    private array $webRoots;

    /**
     * @param ?FileContentReader $contents how a file discovery uses twice is
     *        read. Production reads the filesystem; injected only so a test
     *        can answer a second read with different bytes, which is the one
     *        way to show that a manifest's hash and its metadata come from a
     *        single read rather than from two that can disagree.
     */
    public function __construct(DiscoveryConfig $config, ?FileContentReader $contents = null)
    {
        $this->walker = new TreeWalker($config, $contents ?? new FilesystemContentReader());
        $this->webRoots = $config->webRoots;
    }

    /** Walk the tree and select the files worth analysing, within the configured caps. */
    public function discover(string $requestedRoot, ?CancellationToken $cancellation = null): DiscoveryResult
    {
        return self::result($this->walker->walk($requestedRoot, $cancellation), $this->webRoots);
    }

    /**
     * The walk's files and units in a stable order, with the entry points
     * derived across units, and the input and configuration hashes over both.
     *
     * @param array{
     *     root: string,
     *     files: list<DiscoveredFile>,
     *     units: list<ProjectUnit>,
     *     diagnostics: list<DiscoveryDiagnostic>,
     *     unparsedManifestHashes: array<string, string>,
     *     manifestRoots: list<string>,
     *     directories: list<string>,
     * } $walk what {@see TreeWalker::walk()} read
     * @param array<string, string> $webRoots the URL prefixes `knossos.json` maps to project folders
     */
    private static function result(array $walk, array $webRoots): DiscoveryResult
    {
        $files = $walk['files'];
        $units = $walk['units'];
        $walked = $files;
        $files = SourceClassifier::withoutCompiledSiblings($files);
        // The `tsc` output just left out, by the sources it is built from: a
        // page that loads `main.js` loads what `main.ts` compiles to.
        $kept = [];
        foreach ($files as $file) {
            $kept[$file->relativePath] = true;
        }
        $compiled = [];
        foreach ($walked as $file) {
            if (!isset($kept[$file->relativePath])) {
                $compiled[$file->relativePath] = SourceClassifier::compiledFrom($file->relativePath);
            }
        }
        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);
        $diagnostics = $walk['diagnostics'];
        $units = EntryPointResolver::resolve($units, $files, $webRoots, $diagnostics, $compiled);

        $inputParts = array_map(
            static fn(DiscoveredFile $file): string => $file->relativePath . '=' . $file->contentHash,
            $files,
        );
        $configParts = array_map(
            static fn(ProjectUnit $unit): string => $unit->kind . ':' . $unit->configPath . '=' . $unit->contentHash,
            $units,
        );

        return new DiscoveryResult(
            $walk['root'],
            $files,
            $units,
            $diagnostics,
            hash('sha256', implode("\n", $inputParts)),
            hash('sha256', implode("\n", $configParts)),
            $walk['unparsedManifestHashes'],
            $walk['manifestRoots'],
            $walk['directories'],
        );
    }

    /** Whether a path names a package or build manifest; see {@see SourceClassifier::isManifest()}. */
    public static function isManifest(string $relativePath): bool
    {
        return SourceClassifier::isManifest($relativePath);
    }

    /**
     * Whether a JavaScript file is `tsc` output beside its TypeScript source;
     * see {@see SourceClassifier::isCompiledSibling()}.
     *
     * @param callable(string): bool $exists whether a project-relative path exists
     */
    public static function isCompiledSibling(string $relativePath, string $absolutePath, callable $exists): bool
    {
        return SourceClassifier::isCompiledSibling($relativePath, $absolutePath, $exists);
    }

    /** Whether a path is configuration discovery always reads; see {@see SourceClassifier::isConfigurationFile()}. */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return SourceClassifier::isConfigurationFile($relativePath);
    }

    /**
     * A path's language and unit kind at once; see {@see SourceClassifier::classify()}.
     *
     * @return array{language: ?string, unitKind: ?string}
     */
    public static function classify(string $relativePath, ?string $absolutePath = null): array
    {
        return SourceClassifier::classify($relativePath, $absolutePath);
    }

    /** Whether a path's name can be carried by a stable id; see {@see SourceClassifier::isSupportedPath()}. */
    public static function isSupportedPath(string $relative): bool
    {
        return SourceClassifier::isSupportedPath($relative);
    }
}

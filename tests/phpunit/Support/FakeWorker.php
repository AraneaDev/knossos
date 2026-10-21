<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use InvalidArgumentException;
use Knossos\Discovery\SourceClassifier;
use Knossos\Scan\LanguageDescriptor;

/**
 * The manifest tests/Fixtures/workers/fake-worker.php answers with when a scan
 * launches it under a language descriptor.
 *
 * A scan holds every worker to the descriptor it launched it for
 * ({@see \Knossos\Scanner\Protocol\ScannerManifest::assertDescribes()}), so the
 * fake worker standing in for one has to answer as that worker: its id, its
 * languages, the extensions the core routes to them and its added-files
 * capability. Without the argument it answers as `knossos.fake`.
 */
final class FakeWorker
{
    /** The fixture's path. */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/workers/fake-worker.php';
    }

    /** The `--manifest=` argument that makes the fake worker answer as `$descriptor`'s worker. */
    public static function manifestFor(LanguageDescriptor $descriptor): string
    {
        return '--manifest=' . json_encode([
            'id' => $descriptor->scannerId(),
            'languages' => $descriptor->languages,
            'file_extensions' => SourceClassifier::extensionsFor($descriptor->languages),
            'added_files_affect_all' => $descriptor->addedFilesAffectAll,
        ], JSON_THROW_ON_ERROR);
    }

    /** The `--manifest=` argument for the packaged descriptor of language `$key`. */
    public static function manifestForPackaged(string $key): string
    {
        foreach (LanguageDescriptor::defaults(dirname(__DIR__, 3)) as $descriptor) {
            if ($descriptor->key === $key) {
                return self::manifestFor($descriptor);
            }
        }

        throw new InvalidArgumentException('No packaged descriptor for ' . $key . '.');
    }
}

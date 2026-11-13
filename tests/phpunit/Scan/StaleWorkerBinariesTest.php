<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\StaleWorkerBinaries;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerSourceHash;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan that ran a worker binary built from other source than the checkout
 * holds says so. Pulling updates the source and not the binary, and a scan
 * that ran the old one is otherwise indistinguishable from a current one.
 */
#[Group('scan')]
final class StaleWorkerBinariesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/workers/rust/src', 0o777, true);
        file_put_contents($this->root . '/workers/rust/Cargo.toml', "[package]\nname = \"w\"\n");
        file_put_contents($this->root . '/workers/rust/src/main.rs', "fn main() {}\n");
        // A worker that runs from source: no Cargo.toml, so nothing to compare.
        mkdir($this->root . '/workers/php/src', 0o777, true);
        file_put_contents($this->root . '/workers/php/src/Worker.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
    }

    /** A manifest as the worker `$id` announces it, built from `$sourceHash`. */
    private static function manifest(string $id, ?string $sourceHash): ScannerManifest
    {
        return new ScannerManifest($id, '0.2.0', '1.0', '1.0', ['rust'], ['rs'], ['scan'], $sourceHash);
    }

    /** A binary built from the checkout's own source raises nothing. */
    public function testABinaryBuiltFromTheCheckedOutSourceIsNotReported(): void
    {
        $current = (string) WorkerSourceHash::of($this->root . '/workers/rust');

        self::assertSame([], StaleWorkerBinaries::warnings($this->root, [self::manifest('knossos.rust', $current)]));
    }

    /** A binary built from other source is named with both hashes and how to rebuild it. */
    public function testABinaryBuiltFromOtherSourceIsReportedWithBothHashes(): void
    {
        $current = (string) WorkerSourceHash::of($this->root . '/workers/rust');
        $older = str_repeat('ab', 32);

        $warnings = StaleWorkerBinaries::warnings($this->root, [self::manifest('knossos.rust', $older)]);

        self::assertSame([
            'WORKER_BINARY_STALE: The rust worker binary was not built from the source in workers/rust (binary abababababab, checkout ' . substr($current, 0, 12) . '), so this scan did not run the checked-out worker code.'
            . ' Rebuild it with tools/install, or `cargo build --release --locked` in workers/rust and copy the binary from target/release into workers/rust/bin/, then rescan.',
        ], $warnings);
    }

    /** A binary that announces no hash predates the field, which is older than any source that has it. */
    public function testABinaryThatAnnouncesNoHashIsReportedAsPredatingThem(): void
    {
        $warnings = StaleWorkerBinaries::warnings($this->root, [self::manifest('knossos.rust', null)]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('(the binary predates source hashes)', $warnings[0]);
    }

    /** A worker that runs from source, an unknown scanner id and a missing worker directory have nothing to compare. */
    public function testWorkersWithNothingToCompareAreNotReported(): void
    {
        $older = str_repeat('ab', 32);

        self::assertSame([], StaleWorkerBinaries::warnings($this->root, [
            self::manifest('knossos.php', $older),
            self::manifest('knossos.python', $older),
            self::manifest('other.rust', $older),
            self::manifest('knossos.../rust', $older),
            new \stdClass(),
        ]));
        self::assertSame([], StaleWorkerBinaries::warnings('', [self::manifest('knossos.rust', $older)]), 'With no installation root there is no source to compare with.');
    }

    /** Each stale worker gets its own warning, in the order the workers ran. */
    public function testEveryStaleWorkerIsReported(): void
    {
        mkdir($this->root . '/workers/go/src', 0o777, true);
        file_put_contents($this->root . '/workers/go/Cargo.toml', "[package]\n");
        $older = str_repeat('cd', 32);

        $warnings = StaleWorkerBinaries::warnings($this->root, [self::manifest('knossos.rust', $older), self::manifest('knossos.go', $older)]);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('The rust worker binary', $warnings[0]);
        self::assertStringContainsString('The go worker binary', $warnings[1]);
    }
}

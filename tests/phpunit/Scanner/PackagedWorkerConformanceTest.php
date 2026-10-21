<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\RelativePath;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Every packaged worker passes `tools/scanner-conformance --language`: it
 * refuses missing or malformed limits in the same words, refuses exactly the
 * paths the core refuses, and reports a repeated call once per site.
 */
#[Group('scanner-sdk')]
final class PackagedWorkerConformanceTest extends KnossosTestCase
{
    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return ['php' => ['php'], 'python' => ['python'], 'typescript' => ['typescript'], 'rust' => ['rust']];
    }

    #[DataProvider('languages')]
    public function testThePackagedWorkerConforms(string $language): void
    {
        if ($language === 'rust' && !is_executable(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker is not built.');
        }
        $process = proc_open(
            [PHP_BINARY, self::repositoryRoot() . '/tools/scanner-conformance', '--language=' . $language],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::repositoryRoot(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start scanner conformance runner.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $statuses = array_column($report['checks'], 'status', 'name');

        assertSame(['pass', 'pass', 'pass'], [$statuses['scan_limits'] ?? null, $statuses['relative_paths'] ?? null, $statuses['duplicate_edges'] ?? null], $stderr);
        assertSame([0, true], [$exitCode, $report['conformant']], $stdout . $stderr);
    }

    public function testThePathTableIsTheCoresOwnJudgement(): void
    {
        $table = json_decode((string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/scanner/relative-paths.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['accept' => true, 'reject' => false] as $verdict => $accepted) {
            foreach ($table[$verdict] as $path) {
                try {
                    RelativePath::assertValid($path);
                    $valid = true;
                } catch (InvalidArgumentException) {
                    $valid = false;
                }
                assertSame($accepted, $valid, json_encode($path, JSON_THROW_ON_ERROR));
            }
        }
    }
}

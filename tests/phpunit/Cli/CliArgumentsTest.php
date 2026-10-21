<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The option parsing and the report writer the tools/ scripts share
 * (tools/lib/cli-arguments.php), run as a script runs them: in a process of
 * its own, since a refused option ends that process.
 */
final class CliArgumentsTest extends KnossosTestCase
{
    /**
     * Arguments a script given `--root=` and `--report=` with values and
     * `--check` as a flag reads, and what it reads them as, or null when it
     * prints its usage and exits with 64.
     *
     * @return iterable<string, array{0: list<string>, 1: array<string, string>|null}>
     */
    public static function arguments(): iterable
    {
        yield 'nothing' => [[], []];
        yield 'each once' => [['--root=/a/', '--check', '--report=r.json'], ['root' => '/a/', 'check' => '', 'report' => 'r.json']];
        yield 'the last of a repeated option' => [['--root=a', '--root=b'], ['root' => 'b']];
        yield 'a value holding an equals sign' => [['--report=a=b'], ['report' => 'a=b']];
        yield 'an empty value' => [['--root='], null];
        yield 'a flag given a value' => [['--check=1'], null];
        yield 'a valued option without one' => [['--root'], null];
        yield 'an option not named' => [['--rooted=a'], null];
        yield 'a single dash' => [['-root=a'], null];
        yield 'a positional argument' => [['file'], null];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string>|null $expected
     */
    #[Group('documentation')]
    #[DataProvider('arguments')]
    public function testCliOptionsReadsOnlyTheOptionsItIsGiven(array $arguments, ?array $expected): void
    {
        $code = 'require ' . var_export(self::guardTool('lib/cli-arguments.php'), true) . ';'
            . ' echo json_encode(cliOptions("usage: php tools/x.php [--root=DIR] [--report=FILE] [--check]", ["root", "report"], ["check"], 64));';

        [$exit, $output, $errors] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', $code, '--', ...$arguments]);

        if ($expected === null) {
            assertSame(64, $exit);
            assertSame('', $output);
            assertSame("usage: php tools/x.php [--root=DIR] [--report=FILE] [--check]\n", $errors);

            return;
        }
        assertSame(0, $exit, $errors);
        assertSame($expected, json_decode($output, true, 4, JSON_THROW_ON_ERROR) ?: []);
    }

    /** The report goes into a directory made for it, pretty-printed, slashes as they are, one newline at the end. */
    #[Group('documentation')]
    public function testWriteJsonReportCreatesTheDirectoryAndWritesPrettyJson(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-report-' . bin2hex(random_bytes(6));
        $path = $directory . '/nested/report.json';
        $code = 'require ' . var_export(self::guardTool('lib/cli-arguments.php'), true) . ';'
            . ' writeJsonReport(' . var_export($path, true) . ', ["path" => "a/b", "n" => 1], "test report");';
        try {
            [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', $code]);

            assertSame(0, $exit, $errors);
            assertSame("{\n    \"path\": \"a/b\",\n    \"n\": 1\n}\n", (string) file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($directory . '/nested');
            @rmdir($directory);
        }
    }

    /** A directory that cannot be made is an exception naming what it was for, and nothing is written. */
    #[Group('documentation')]
    public function testWriteJsonReportRefusesADirectoryItCannotMake(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'knossos-blocker-');
        self::assertIsString($blocker);
        $code = 'require ' . var_export(self::guardTool('lib/cli-arguments.php'), true) . ';'
            . ' try { writeJsonReport(' . var_export($blocker . '/report.json', true) . ', [], "test report"); }'
            . ' catch (RuntimeException $e) { echo $e->getMessage(); }';
        try {
            [$exit, $output] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', $code]);

            assertSame(0, $exit);
            assertSame('Unable to create test report directory.', $output);
        } finally {
            @unlink($blocker);
        }
    }
}

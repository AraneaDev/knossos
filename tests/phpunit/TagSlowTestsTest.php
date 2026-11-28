<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * tools/tag-slow-tests.php reads PHPUnit's result cache and lists the test
 * methods that took more than five seconds. `--check` refuses one that has no
 * `#[Group('slow')]`, and `--write` adds the group. The tests run the real
 * tool against a tree of their own, with a composer.json that maps the
 * fixture namespace to its test directory.
 */
#[Group('quality')]
final class TagSlowTestsTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = self::scratchPath('knossos-tag-slow');
        mkdir($this->directory . '/tests', 0o700, true);
        file_put_contents($this->directory . '/composer.json', json_encode(['autoload-dev' => ['psr-4' => ['Fixture\\Tests\\' => 'tests/']]], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testATestOverFiveSecondsIsListedAndAFasterOneIsNot(): void
    {
        $this->writeSampleTest('');
        $cache = $this->cache(['Fixture\\Tests\\SampleTest::testSlow' => 6.0, 'Fixture\\Tests\\SampleTest::testFast' => 1.0]);

        [$exit, $output] = $this->tool('--cache=' . $cache);

        assertSame(0, $exit);
        assertContains('Fixture\\Tests\\SampleTest::testSlow', $output);
        assertContains('untagged', $output);
        assertSame(false, str_contains($output, 'testFast'));
    }

    public function testCheckRefusesAnUntaggedSlowTestAndPassesOnceItIsTagged(): void
    {
        $this->writeSampleTest('');
        $cache = $this->cache(['Fixture\\Tests\\SampleTest::testSlow' => 6.0, 'Fixture\\Tests\\SampleTest::testFast' => 1.0]);

        [$exit, , $errors] = $this->tool('--cache=' . $cache, '--check');
        assertSame(1, $exit);
        assertContains("Fixture\\Tests\\SampleTest::testSlow took 6.00 s and has no #[Group('slow')].", $errors);

        $this->writeSampleTest("    #[Group('slow')]\n");
        [$tagged, , $none] = $this->tool('--cache=' . $cache, '--check');
        assertSame(0, $tagged, $none);
    }

    public function testATestAtFiveSecondsOrUnderIsNotSlow(): void
    {
        $this->writeSampleTest('');
        $cache = $this->cache(['Fixture\\Tests\\SampleTest::testSlow' => 5.0]);

        [$exit, $output] = $this->tool('--cache=' . $cache, '--check');

        assertSame(0, $exit);
        assertSame(false, str_contains($output, 'testSlow'));
    }

    public function testWriteTagsEachSlowMethodOnceWithItsImport(): void
    {
        $this->writeSampleTest('', false);
        $cache = $this->cache([
            'Fixture\\Tests\\SampleTest::testSlow' => 7.5,
            'Fixture\\Tests\\SampleTest::testRows with data set "a"' => 5.5,
            'Fixture\\Tests\\SampleTest::testRows with data set "b"' => 9.0,
            'Fixture\\Tests\\SampleTest::testFast' => 0.2,
            'Fixture\\Tests\\GoneTest::testRemoved' => 8.0,
        ]);

        [$exit, , $errors] = $this->tool('--cache=' . $cache, '--write');

        assertSame(0, $exit, $errors);
        assertContains('Fixture\\Tests\\GoneTest::testRemoved is in the cache but not in the tree', $errors);
        $source = (string) file_get_contents($this->directory . '/tests/SampleTest.php');
        assertSame(2, substr_count($source, "#[Group('slow')]"));
        assertContains("use PHPUnit\\Framework\\Attributes\\Group;\n", $source);
        assertContains("    #[Group('slow')]\n    public function testSlow(): void", $source);
        assertContains("    #[DataProvider('rows')]\n    #[Group('slow')]\n    public function testRows(string \$row): void", $source);
        assertContains('    public function testFast(): void', $source);
        assertSame(false, str_contains($source, "#[Group('slow')]\n    public function testFast"));
        [$check] = $this->tool('--cache=' . $cache, '--check');
        assertSame(0, $check);

        $this->tool('--cache=' . $cache, '--write');
        assertSame($source, (string) file_get_contents($this->directory . '/tests/SampleTest.php'));
    }

    public function testAClassTaggedSlowCoversItsMethods(): void
    {
        $this->writeSampleTest('', true, "#[Group('slow')]\n");
        $cache = $this->cache(['Fixture\\Tests\\SampleTest::testSlow' => 6.0]);

        [$exit, $output, $errors] = $this->tool('--cache=' . $cache, '--check');

        assertSame(0, $exit, $errors);
        assertContains('tagged', $output);
    }

    public function testAMissingCacheIsAUsageError(): void
    {
        [$exit, , $errors] = $this->tool('--cache=' . $this->directory . '/absent');

        assertSame(1, $exit);
        assertContains('no PHPUnit result cache at', $errors);
    }

    /**
     * Writes tests/SampleTest.php, with `$slowAttribute` above `testSlow`.
     */
    private function writeSampleTest(string $slowAttribute, bool $importsGroup = true, string $classAttribute = ''): void
    {
        $imports = "use PHPUnit\\Framework\\Attributes\\DataProvider;\n" . ($importsGroup ? "use PHPUnit\\Framework\\Attributes\\Group;\n" : '') . "use PHPUnit\\Framework\\TestCase;\n";
        file_put_contents($this->directory . '/tests/SampleTest.php', "<?php\n\nnamespace Fixture\\Tests;\n\n{$imports}\n"
            . "{$classAttribute}final class SampleTest extends TestCase\n{\n"
            . "    /** Slow on purpose. */\n{$slowAttribute}    public function testSlow(): void\n    {\n    }\n\n"
            . "    public function testFast(): void\n    {\n    }\n\n"
            . "    /** @return list<list<string>> */\n    public static function rows(): array\n    {\n        return [['a'], ['b']];\n    }\n\n"
            . "    #[DataProvider('rows')]\n    public function testRows(string \$row): void\n    {\n    }\n}\n");
    }

    /**
     * A PHPUnit result cache holding `$times`.
     *
     * @param array<string, float> $times
     */
    private function cache(array $times): string
    {
        $path = $this->directory . '/test-results';
        file_put_contents($path, json_encode(['version' => 2, 'defects' => [], 'times' => $times], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array{int, string, string} exit code, output and errors of the tool */
    private function tool(string ...$arguments): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::repositoryRoot() . '/tools/tag-slow-tests.php', '--root=' . $this->directory, ...$arguments]);
    }
}

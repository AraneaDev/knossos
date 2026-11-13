<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `gates` list of knossos.json: the commands a project runs before a commit,
 * which a creation brief later quotes back to whoever is about to write a file.
 */
#[Group('configuration')]
final class ProjectGatesTest extends KnossosTestCase
{
    /** Order is the order the project wrote them in, and `applies_to` narrows a gate by glob. */
    public function testDeclaredGatesAreLoadedInOrder(): void
    {
        $gates = $this->load([
            'gates' => [
                ['name' => 'cs', 'command' => 'vendor/bin/php-cs-fixer fix'],
                ['name' => 'docs', 'command' => 'npx prettier --check docs', 'applies_to' => ['docs/*']],
            ],
        ])->gates;

        self::assertCount(2, $gates);
        self::assertSame('cs', $gates[0]->name);
        self::assertSame('docs', $gates[1]->name);
        self::assertTrue($gates[1]->appliesTo('docs/a.md'));
        self::assertFalse($gates[1]->appliesTo('src/A.php'));
        self::assertTrue($gates[0]->appliesTo('src/A.php'));
    }

    /** A star crosses directory separators, so `src/*` covers a nested file. */
    public function testAGlobMatchesNestedPaths(): void
    {
        $gates = $this->load(['gates' => [['name' => 'src', 'command' => 'make', 'applies_to' => ['src/*']]]])->gates;

        self::assertTrue($gates[0]->appliesTo('src/Query/A.php'));
    }

    /** A gate with nothing to run is a typo, not a gate. */
    public function testAGateWithAnEmptyCommandIsRefused(): void
    {
        $this->assertRefused(['gates' => [['name' => 'cs', 'command' => '']]], 'cs');
    }

    /** Two gates under one name would make "which gate failed" ambiguous. */
    public function testTwoGatesWithOneNameAreRefused(): void
    {
        $this->assertRefused(['gates' => [
            ['name' => 'cs', 'command' => 'a'],
            ['name' => 'cs', 'command' => 'b'],
        ]], 'cs');
    }

    /** The list is bounded like every other list in the file. */
    public function testMoreThanTwentyGatesAreRefused(): void
    {
        $gates = array_map(
            static fn(int $index): array => ['name' => 'g' . $index, 'command' => 'true'],
            range(1, 21),
        );

        $this->assertRefused(['gates' => $gates], 'gates');
    }

    /** `$` alone would let a trailing newline through; the name ends where it ends. */
    public function testANameWithATrailingNewlineIsRefused(): void
    {
        $this->assertRefused(['gates' => [['name' => "cs\n", 'command' => 'true']]], 'gates');
    }

    /** A command is shown at session start, so a line break or tab in it is refused, naming the gate. */
    public function testACommandWithAControlCharacterIsRefused(): void
    {
        foreach (["a\nb", "a\tb", "a\x00b", "a\x7Fb"] as $command) {
            $this->assertRefused(['gates' => [['name' => 'cs', 'command' => $command]]], 'cs');
        }
    }

    /** The Unicode line breaks end a line as surely as `\n` does, so they are refused too. */
    public function testACommandWithAUnicodeLineBreakIsRefused(): void
    {
        foreach (["a\u{2028}b", "a\u{2029}b", "a\u{0085}b"] as $command) {
            $this->assertRefused(['gates' => [['name' => 'cs', 'command' => $command]]], 'cs');
        }
    }

    /** A name is lowercase letters, digits and hyphens. */
    public function testANameWithASpaceAndCapitalsIsRefused(): void
    {
        $this->assertRefused(['gates' => [['name' => 'Bad Name', 'command' => 'true']]], 'gates');
    }

    /** `applies_to` is bounded at ten entries. */
    public function testElevenAppliesToEntriesAreRefused(): void
    {
        $globs = array_map(static fn(int $index): string => 'src/' . $index . '/*', range(1, 11));

        $this->assertRefused(['gates' => [['name' => 'cs', 'command' => 'true', 'applies_to' => $globs]]], 'gates');
    }

    /** No `gates` key, no gates. */
    public function testAProjectWithoutGatesHasNone(): void
    {
        self::assertSame([], $this->load([])->gates);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function load(array $configuration): \Knossos\Configuration\ProjectConfiguration
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1] + $configuration, JSON_THROW_ON_ERROR));
        try {
            return ProjectConfigurationLoader::load($root, [$root]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function assertRefused(array $configuration, string $mentions): void
    {
        $error = self::captureThrown(fn() => $this->load($configuration), DiscoveryException::class);

        self::assertStringContainsString('gates', $error->getMessage());
        self::assertStringContainsString($mentions, $error->getMessage());
    }
}

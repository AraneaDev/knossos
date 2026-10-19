<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * The staged register.tsx type-check reads Claude Code API declarations that
 * are committed, because the CLI only writes them when a session loads the
 * mod. They are only worth checking against if they come from the version the
 * image runs, so tools/claude-code-types-check.php refuses a Dockerfile pin, a
 * VERSION stamp and a declaration header that disagree. These tests plant
 * each disagreement in a tree of their own.
 */
#[Group('quality')]
final class ClaudeCodeTypesTest extends KnossosTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/knossos-claude-code-types-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/tools/claude-code-api/claude-code', 0o700, true);
        mkdir($this->directory . '/tools/claude-code-api/claude-code-tools', 0o700, true);
        $this->pin('2.1.287');
        file_put_contents($this->directory . '/tools/claude-code-api/VERSION', "2.1.287\n");
        file_put_contents($this->directory . '/tools/claude-code-api/claude-code/index.d.ts', "// Written by Claude Code 2.1.287.\ndeclare module 'claude-code' {}\n");
        file_put_contents($this->directory . '/tools/claude-code-api/claude-code-tools/index.d.ts', "declare module 'claude-code' {}\n");
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            $this->runFixtureCommand(['rm', '-rf', $this->directory]);
        }
    }

    public function testDeclarationsFromThePinnedVersionPass(): void
    {
        [$exit, $output, $errors] = $this->check();

        assertSame(0, $exit, $errors);
        assertContains('written by Claude Code 2.1.287, the version the Dockerfile pins', $output);
    }

    /** Moving the pin without regenerating is refused, and the refusal says how to regenerate. */
    public function testAMovedPinIsRefusedNamingTheRegeneration(): void
    {
        $this->pin('2.1.303');

        [$exit, , $errors] = $this->check();

        assertSame(1, $exit, 'declarations from another version passed');
        assertContains('the declarations are from Claude Code 2.1.287, but the Dockerfile pins 2.1.303; run tools/claude-code-types', $errors);
    }

    /** A stamp edited by hand does not make older declarations current. */
    public function testAStampTheDeclarationsDoNotBearOutIsRefused(): void
    {
        $this->pin('2.1.303');
        file_put_contents($this->directory . '/tools/claude-code-api/VERSION', "2.1.303\n");

        [$exit, , $errors] = $this->check();

        assertSame(1, $exit);
        assertContains('does not say it was written by Claude Code 2.1.303', $errors);
    }

    public function testMissingDeclarationsAreRefused(): void
    {
        unlink($this->directory . '/tools/claude-code-api/claude-code-tools/index.d.ts');

        [$exit, , $errors] = $this->check();

        assertSame(1, $exit);
        assertContains('tools/claude-code-api/claude-code-tools/index.d.ts is missing', $errors);
    }

    /** The repository's own declarations match its own pin, or every static lane would fail. */
    public function testTheRepositoryDeclarationsMatchItsPin(): void
    {
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('claude-code-types-check.php'), '--root=' . self::repositoryRoot()]);

        assertSame(0, $exit, $errors);
    }

    private function pin(string $version): void
    {
        file_put_contents($this->directory . '/Dockerfile', "FROM scratch\nRUN npm install --global @anthropic-ai/claude-code@{$version} --no-audit\n");
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function check(): array
    {
        return $this->runFixtureCommandOutput([PHP_BINARY, self::guardTool('claude-code-types-check.php'), '--root=' . $this->directory]);
    }
}

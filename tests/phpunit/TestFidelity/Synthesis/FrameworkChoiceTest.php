<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\TestFidelity\Synthesis;

use InvalidArgumentException;
use Knossos\Query\TestFidelity\ManifestFrameworks;
use Knossos\TestFidelity\Synthesis\FrameworkChoice;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertSame;

/**
 * The framework a template is written for (Decision 5): asked for, else
 * what the project's doubles use most, else a manifest's dev dependency,
 * else the language's default, through the frozen map from a double's
 * framework to a template's (preflight F12).
 */
final class FrameworkChoiceTest extends KnossosTestCase
{
    public function testAnExplicitFrameworkWins(): void
    {
        assertSame(['framework' => 'jest', 'source' => 'explicit'], FrameworkChoice::choose('typescript', 'jest', ['vitest' => 9], ['vitest']));
        assertSame(['framework' => 'hand-written', 'source' => 'explicit'], FrameworkChoice::choose('rust', 'hand-written', [], []));
    }

    public function testTheProjectsOwnDoublesComeNext(): void
    {
        assertSame(['framework' => 'mockery', 'source' => 'existing_doubles'], FrameworkChoice::choose('php', null, ['phpunit' => 2, 'mockery' => 1, 'pest' => 2], ['prophecy']));
        assertSame(['framework' => 'unittest.mock', 'source' => 'existing_doubles'], FrameworkChoice::choose('python', null, ['unittest' => 1, 'pytest' => 5], []));
    }

    public function testATieGoesToTheFirstByName(): void
    {
        assertSame(['framework' => 'jest', 'source' => 'existing_doubles'], FrameworkChoice::choose('typescript', null, ['vitest' => 3, 'jest' => 3], []));
        assertSame(['framework' => 'hand-written', 'source' => 'existing_doubles'], FrameworkChoice::choose('rust', null, ['mockall' => 1, 'hand-written' => 1], []));
    }

    public function testADoubleFrameworkWithNoTemplateCountsForNothing(): void
    {
        // pytest's monkeypatch and the Rust crates outside Q3 map to no template: the next source applies.
        assertSame(['framework' => 'pytest-mock', 'source' => 'manifest'], FrameworkChoice::choose('python', null, ['pytest' => 4], ['pytest-mock']));
        assertSame(['framework' => 'mockall', 'source' => 'default'], FrameworkChoice::choose('rust', null, ['faux' => 2, 'mry' => 1], []));
        // A double of another language's framework is not counted either.
        assertSame(['framework' => 'phpunit', 'source' => 'default'], FrameworkChoice::choose('php', null, ['vitest' => 3, 'phpunit' => 0], []));
    }

    public function testTheManifestThenTheDefault(): void
    {
        assertSame(['framework' => 'prophecy', 'source' => 'manifest'], FrameworkChoice::choose('php', null, [], ['prophecy', 'phpunit']));
        assertSame(['framework' => 'vitest', 'source' => 'default'], FrameworkChoice::choose('typescript', null, [], ['mockery']));
        assertSame(['framework' => 'unittest.mock', 'source' => 'default'], FrameworkChoice::choose('python', null, [], []));
    }

    public function testAFrameworkOfAnotherLanguageIsAnError(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Framework "vitest" writes typescript templates, and the symbol is php: use phpunit, mockery or prophecy.'));
        FrameworkChoice::choose('php', 'vitest', [], []);
    }

    public function testAnUnknownFrameworkIsAnError(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unknown framework "mocha"; a typescript template is written for vitest or jest.'));
        FrameworkChoice::choose('typescript', 'mocha', [], []);
    }

    public function testEveryFrameworkOfQ3(): void
    {
        assertSame(['vitest', 'jest', 'phpunit', 'mockery', 'prophecy', 'unittest.mock', 'pytest-mock', 'mockall', 'hand-written'], FrameworkChoice::all());
        assertSame('rust', FrameworkChoice::languageOf('hand-written'));
        assertSame(null, FrameworkChoice::languageOf('faux'));
    }

    public function testManifestsNameTheirDevDependencies(): void
    {
        assertSame(['vitest', 'jest'], ManifestFrameworks::named('typescript', '{"devDependencies": {"jest": "^29", "vitest": "^3"}}'));
        assertSame([], ManifestFrameworks::named('typescript', '{"dependencies": {"vitest": "^3"}}'));
        assertSame(['mockery', 'prophecy', 'phpunit'], ManifestFrameworks::named('php', '{"require-dev": {"phpunit/phpunit": "^12", "Mockery/Mockery": "^1", "phpspec/prophecy-phpunit": "^2"}}'));
        assertSame([], ManifestFrameworks::named('php', '{"require": {"mockery/mockery": "^1"}}'));
        assertSame(['pytest-mock'], ManifestFrameworks::named('python', "[project.optional-dependencies]\ntest = [\"pytest_mock>=3\"]\n"));
        assertSame([], ManifestFrameworks::named('python', "pytest-mockito==1\n"));
        assertSame(['mockall'], ManifestFrameworks::named('rust', "[dependencies]\nserde = \"1\"\n\n[dev-dependencies]\nmockall = \"0.13\"\n"));
        assertSame(['mockall'], ManifestFrameworks::named('rust', "[target.'cfg(unix)'.dev-dependencies.mockall]\nversion = \"0.13\"\n"));
        assertSame([], ManifestFrameworks::named('rust', "[dependencies]\nmockall = \"0.13\"\n"));
    }

    public function testTheNearestManifestAboveTheFileIsRead(): void
    {
        $root = self::scratchPath('knossos-stale-manifest-frameworks');
        try {
            mkdir($root . '/crates/a/src', 0o777, true);
            file_put_contents($root . '/Cargo.toml', "[workspace]\n");
            file_put_contents($root . '/crates/a/Cargo.toml', "[dev-dependencies]\nmockall = \"0.13\"\n");
            file_put_contents($root . '/package.json', '{"devDependencies": {"jest": "1"}}');

            assertSame(['mockall'], ManifestFrameworks::near($root, 'crates/a/src/lib.rs', 'rust'));
            assertSame(['jest'], ManifestFrameworks::near($root, 'crates/a/src/x.ts', 'typescript'));
            assertSame([], ManifestFrameworks::near($root, 'src/lib.rs', 'rust'));
            assertSame([], ManifestFrameworks::near($root, 'app/x.py', 'python'));
        } finally {
            $this->removeTempTree($root);
        }
    }
}

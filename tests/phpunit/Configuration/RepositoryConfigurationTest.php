<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\IgnoreMatcher;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\Group;

/**
 * This repository's own `knossos.json`, which the architecture gate scans with.
 *
 * The mod's entry point is the only caller of most of its library functions,
 * so ignoring it makes every one of them a dead-code candidate. Its imports of
 * the engine's run-time module resolve through a stand-in declaration instead.
 *
 * The mod's specs are scanned too: they are the only tests of its modules, so
 * ignoring them marks every one of those modules as reached by no test. Their
 * imports of dev dependencies a git archive does not carry resolve to external
 * symbols, which the architecture gate does not count against its budgets.
 */
final class RepositoryConfigurationTest extends KnossosTestCase
{
    #[Group('configuration')]
    public function testTheModsEntryPointStaysInTheGraph(): void
    {
        $root = self::repositoryRoot();
        $ignores = new IgnoreMatcher(ProjectConfigurationLoader::load($root, [$root])->ignores);
        assertFalse($ignores->matches('hooks/register.tsx'));
        assertFalse($ignores->matches('hooks/engine.d.ts'));
        assertFalse($ignores->matches('hooks/lib/layout.ts'));
        assertFalse($ignores->matches('hooks/mod/loaders.ts'));
        assertFalse($ignores->matches('hooks/register.test.ts'));
        assertFalse($ignores->matches('hooks/lib/layout.spec.ts'));
        assertFalse($ignores->matches('hooks/mod/loaders.spec.ts'));
        assertTrue($ignores->matches('tests/Fixtures/shop/src/Checkout.php'));
        assertStringContainsString("declare module 'claude-code'", (string) file_get_contents($root . '/hooks/engine.d.ts'));
    }
}

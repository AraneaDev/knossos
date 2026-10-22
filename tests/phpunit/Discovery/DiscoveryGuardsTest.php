<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\IgnoreMatcher;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for src/Discovery/IgnoreMatcher.php: the EXCLUDED_SEGMENTS
 * and EXCLUDED_PREFIXES lists, and custom pattern matching with the '/**'
 * suffix glob and the fnmatch fallback. RootGuard's own tests live in
 * tests/phpunit/Roots.
 *
 * Conventions: bare global helpers from `tests/phpunit/Support/Assertions.php`;
 * class-level `#[Group('discovery-guards')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('discovery-guards')]
final class DiscoveryGuardsTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    public function testIgnoreMatcherPatternListPropagatesThroughMatches(): void
    {
        $empty = new IgnoreMatcher([]);
        $withPattern = new IgnoreMatcher(['*.tmp']);

        assertSame(false, $empty->matches('src/Foo.php'));
        assertSame(false, $withPattern->matches('src/Foo.php'));
        assertSame(true, $withPattern->matches('foo.tmp'));
    }

    public function testIgnoreMatcherMatchesExcludedSegment(): void
    {
        // Built-in segments arm: any segment in the built-in list
        // triggers true (e.g., 'node_modules').
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('node_modules/lodash/index.js'));
        assertSame(true, $matcher->matches('a/b/c/.git/HEAD'));
        assertSame(true, $matcher->matches('vendor/autoload.php'));
    }

    public function testIgnoreMatcherMatchesExcludedPrefix(): void
    {
        // EXCLUDED_PREFIXES arm: path starting with 'storage/framework/'
        // or 'public/build/' triggers true.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('storage/framework/cache/data.php'));
        assertSame(true, $matcher->matches('public/build/manifest.json'));
        // Sibling paths not matching the prefix are not excluded.
        assertSame(false, $matcher->matches('storage/other/file.php'));
        assertSame(false, $matcher->matches('public/asset/image.png'));
    }

    public function testIgnoreMatcherNormalizesBackslashSeparators(): void
    {
        // Backslash separators are converted to forward slash before
        // segment-matching. Windows-style input paths go through the
        // same built-in segment check after normalisation.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('src\node_modules\foo.js'));
        assertSame(true, $matcher->matches('a\b\.git\HEAD'));
        assertSame(false, $matcher->matches('src\Foo.php'));
    }

    public function testIgnoreMatcherTrimsLeadingAndTrailingSlashes(): void
    {
        // Leading / trailing slashes / backslashes in the input are
        // trimmed before matching. The backslash arm also exercises
        // the str_replace -> trim order.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('/node_modules/foo/'));
        assertSame(true, $matcher->matches('\\node_modules\foo\\'));
        assertSame(false, $matcher->matches('/src/Foo.php/'));
    }

    public function testIgnoreMatcherCustomPatternWithDoubleStarGlob(): void
    {
        // '/**' suffix arm: a pattern ending in '/**' matches the
        // base directory itself (path === base) and any subpath
        // (path starts with base + '/').
        $matcher = new IgnoreMatcher(['docs/**']);
        assertSame(true, $matcher->matches('docs'));
        assertSame(true, $matcher->matches('docs/index.md'));
        assertSame(true, $matcher->matches('docs/sub/page.md'));
        assertSame(false, $matcher->matches('src/docs/index.md'));
    }

    public function testIgnoreMatcherCustomPatternWithGitignoreGlob(): void
    {
        // Gitignore semantics: a slash-free pattern like `*.tmp` or
        // `test_*.php` matches its basename at ANY depth (unlike the old
        // FNM_PATHNAME behaviour, which only matched top-level entries).
        // '*' still never crosses a '/' within a single segment.
        $matcher = new IgnoreMatcher(['*.tmp', 'test_*.php', 'sub/test_*.php']);
        assertSame(true, $matcher->matches('foo.tmp'));
        assertSame(true, $matcher->matches('test_user.php'));
        // A pattern containing a '/' is anchored to the project root.
        assertSame(true, $matcher->matches('sub/test_inner.php'));
        // Slash-free `*.tmp` now matches at depth (the gitignore fix).
        assertSame(true, $matcher->matches('sub/foo.tmp'));
        assertSame(false, $matcher->matches('keep.php'));
    }

    public function testIgnoreMatcherSkipsEmptyCustomPatterns(): void
    {
        // Empty-pattern skip arm: a pattern of '' or just whitespace
        // normalises to '' and is skipped via `continue`.
        $matcher = new IgnoreMatcher(['', '   ']);
        assertSame(false, $matcher->matches('src/Foo.php'));
    }

    public function testIgnoreMatcherReturnsFalseForNonExcludedCleanPath(): void
    {
        // Negative arm: clean path with no excluded segments, no
        // excluded prefix, no matching custom pattern returns false.
        $matcher = new IgnoreMatcher([]);
        assertSame(false, $matcher->matches('src/Foo.php'));
        assertSame(false, $matcher->matches('packages/frontend/src/main.ts'));
        assertSame(false, $matcher->matches('composer.json'));
    }

    public function testIgnoreMatcherHandlesBothSegmentsAndPatternsCorrectly(): void
    {
        // Composition: a path that has BOTH an excluded segment AND
        // a matching custom pattern returns true (segment check
        // first). Also exercises pattern-with-backslash glob
        // normalisation in the pattern itself.
        $matcher = new IgnoreMatcher(['docs\**']);
        assertSame(true, $matcher->matches('docs/index.md'));
        assertSame(true, $matcher->matches('docs/'));
    }
}

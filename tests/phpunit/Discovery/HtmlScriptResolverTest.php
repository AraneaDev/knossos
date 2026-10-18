<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\HtmlScriptMiss;
use Knossos\Discovery\HtmlScriptResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Which scanned file a script URL in an HTML page names, asked with file lists built by hand. */
#[Group('discovery')]
final class HtmlScriptResolverTest extends TestCase
{
    /**
     * @param list<string> $files
     * @param array<string, string> $webRoots
     */
    private static function resolve(string $page, string $url, array $files, array $webRoots = []): string|HtmlScriptMiss|null
    {
        return HtmlScriptResolver::resolve($page, $url, array_fill_keys($files, true), $webRoots);
    }

    private static function assertMiss(string $reason, string|HtmlScriptMiss|null $result): HtmlScriptMiss
    {
        self::assertInstanceOf(HtmlScriptMiss::class, $result);
        assertSame($reason, $result->reason);

        return $result;
    }

    public function testARelativePathResolvesFromThePageFolder(): void
    {
        assertSame('web/public/app.mjs', self::resolve('web/public/index.html', './app.mjs', ['web/public/app.mjs']));
        assertSame('web/public/app.mjs', self::resolve('web/public/index.html', 'app.mjs', ['web/public/app.mjs']));
        assertSame('web/lib/x.js', self::resolve('web/public/index.html', '../lib/x.js', ['web/lib/x.js']));
        // A relative path is not searched for elsewhere.
        self::assertMiss('none', self::resolve('web/public/index.html', './app.mjs', ['app.mjs']));
    }

    /** The server maps `/static/` onto the page's own folder, so the first segment is dropped there. */
    public function testARootPathResolvesByDroppingItsFirstSegmentBesideThePage(): void
    {
        assertSame('web/public/app.mjs', self::resolve('web/public/index.html', '/static/app.mjs', ['web/public/app.mjs', 'web/server.ts']));
        // Every parent folder up to the project root is a candidate too.
        assertSame('web/static/app.mjs', self::resolve('web/public/index.html', '/static/app.mjs', ['web/static/app.mjs']));
    }

    public function testTheOldPublicAndStaticReadingsStillResolve(): void
    {
        assertSame('public/x.js', self::resolve('index.html', '/x.js', ['public/x.js']));
        assertSame('static/x.js', self::resolve('index.html', '/x.js', ['static/x.js']));
        // A page in a subfolder has its bundler's `public/` beside it.
        assertSame('frontend/public/x.js', self::resolve('frontend/index.html', '/x.js', ['frontend/public/x.js']));
    }

    public function testTwoMatchesAreAmbiguous(): void
    {
        $miss = self::assertMiss('ambiguous', self::resolve('index.html', '/app.js', ['app.js', 'public/app.js']));
        assertSame(['app.js', 'public/app.js'], $miss->candidates);
    }

    public function testNoMatchIsReported(): void
    {
        $miss = self::assertMiss('none', self::resolve('index.html', '/gone.js', ['other.js']));
        self::assertContains('gone.js', $miss->candidates);
        self::assertContains('public/gone.js', $miss->candidates);
    }

    public function testAConfiguredRootWinsAndNeverFallsBack(): void
    {
        $roots = ['/static/' => 'web/public/'];
        // Ambiguous for the heuristic (`static/app.mjs` exists too); the mapping decides.
        assertSame('web/public/app.mjs', self::resolve('web/public/index.html', '/static/app.mjs', ['web/public/app.mjs', 'static/app.mjs'], $roots));
        $miss = self::assertMiss('mapped-missing', self::resolve('web/public/index.html', '/static/gone.mjs', ['gone.mjs', 'web/gone.mjs'], $roots));
        assertSame(['web/public/gone.mjs'], $miss->candidates);
        // The longest matching prefix wins, whatever order the mapping lists them in.
        $nested = ['/static/' => 'web/public/', '/static/js/' => 'assets/'];
        assertSame('assets/x.js', self::resolve('index.html', '/static/js/x.js', ['assets/x.js', 'web/public/js/x.js'], $nested));
        assertSame('web/public/y.js', self::resolve('index.html', '/static/y.js', ['web/public/y.js'], $nested));
        // A root path no prefix matches falls to the heuristic.
        assertSame('other.js', self::resolve('index.html', '/other.js', ['other.js'], $roots));
    }

    public function testQueryFragmentAndEncodingAreStripped(): void
    {
        assertSame('web/a b.mjs', self::resolve('web/index.html', './a%20b.mjs?v=2#x', ['web/a b.mjs']));
        assertSame('web/c.mjs', self::resolve('web/index.html', 'c.mjs#top', ['web/c.mjs']));
    }

    public function testAPathAboveTheProjectRootIsUnresolved(): void
    {
        self::assertMiss('outside', self::resolve('index.html', '../../x.js', ['x.js']));
        self::assertMiss('outside', self::resolve('web/index.html', '/../x.js', ['x.js']));
        self::assertMiss('outside', self::resolve('index.html', '/static/x.js', ['x.js'], ['/static/' => '../']));
        // Climbing and coming back down inside the project is fine.
        assertSame('web/x.js', self::resolve('web/public/index.html', '../../web/x.js', ['web/x.js']));
    }

    public function testRemoteDataBareAndNonScriptReferencesAreIgnored(): void
    {
        foreach (['https://cdn/x.js', 'http://cdn/x.js', '//cdn/x.js', 'data:text/javascript,1', 'blob:https://x/y', 'react', 'lodash/fp', './style.css', '/static/', ''] as $url) {
            self::assertNull(self::resolve('index.html', $url, ['x.js', 'style.css']), $url);
        }
    }
}

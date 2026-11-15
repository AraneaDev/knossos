<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The README links to the documentation site, not to the Markdown files, and
 * the link checker only reads an outside address for its syntax. A page that is
 * renamed or removed would leave the README pointing at nothing, so each of its
 * links to the site is matched here against the page the site builds it from.
 */
final class ReadmeSiteLinksTest extends KnossosTestCase
{
    /** Where the documentation site is published. */
    private const SITE = 'https://araneadev.github.io/knossos/';

    /** The site's own page for docs/README.md (website/.vitepress/config.mjs). */
    private const INDEX_PAGE = 'all-pages';

    #[Group('documentation')]
    public function testEveryLinkToTheSiteNamesAPageThatExists(): void
    {
        $links = $this->siteLinks();
        self::assertNotSame([], $links, 'the README links to the documentation site');

        $missing = [];
        foreach ($links as $path) {
            if (!$this->isPage($path)) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing, 'README links to site pages that docs/ does not have');
    }

    #[Group('documentation')]
    public function testTheReadmeLinksToNoMarkdownPageUnderDocs(): void
    {
        preg_match_all('/\]\((docs\/(?!images\/)[^)#]+\.md)(?:#[^)]*)?\)/', $this->readme(), $matches);

        self::assertSame([], $matches[1], 'README links to a Markdown page; link to its page on the site');
    }

    /**
     * The path of every README link into the site, without its anchor.
     *
     * @return list<string>
     */
    private function siteLinks(): array
    {
        preg_match_all('/\]\(' . preg_quote(self::SITE, '/') . '([^)#]*)(?:#[^)]*)?\)/', $this->readme(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Whether the site builds a page at this path: the front page, the index, a page or a folder's index. */
    private function isPage(string $path): bool
    {
        if ($path === '' || $path === self::INDEX_PAGE) {
            return true;
        }
        $docs = self::repositoryRoot() . '/docs/';

        return str_ends_with($path, '/')
            ? is_file($docs . $path . 'index.md')
            : is_file($docs . $path . '.md');
    }

    /** The README's text. */
    private function readme(): string
    {
        return (string) file_get_contents(self::repositoryRoot() . '/README.md');
    }
}

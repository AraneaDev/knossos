<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use FilesystemIterator;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * docs/README.md is the one list of the documentation pages: GitHub shows it as
 * the index, and the site builds its sidebar from the same headings and links
 * (website/.vitepress/navigation.mjs). A page it does not list would be
 * published with no way to reach it.
 */
final class DocumentationIndexTest extends KnossosTestCase
{
    /** Local scratch under docs/ that .gitignore keeps out of the repository. */
    private const SCRATCH = ['audits', 'superpowers'];

    #[Group('documentation')]
    public function testEveryPageUnderDocsIsListedInTheIndex(): void
    {
        $listed = array_column($this->indexLinks(), 'page');
        $unlisted = array_values(array_diff($this->pages(), ['README.md', 'index.md'], $listed));

        self::assertSame([], $unlisted, 'pages under docs/ that docs/README.md does not link to');
    }

    #[Group('documentation')]
    public function testEveryPageTheIndexListsExists(): void
    {
        $pages = $this->pages();
        $missing = [];
        foreach ($this->indexLinks() as $link) {
            if ($link['page'] !== null && !in_array($link['page'], $pages, true)) {
                $missing[] = $link['target'];
            }
        }

        self::assertSame([], $missing, 'links in docs/README.md to a page that does not exist');
        self::assertGreaterThan(50, count($pages), 'the scan found the pages');
    }

    #[Group('documentation')]
    public function testTheIndexKeepsTheHeadingsTheSiteTopBarLinksTo(): void
    {
        $headings = [];
        foreach (file(self::repositoryRoot() . '/docs/README.md', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^## (.+?)\s*$/', $line, $match) === 1) {
                $headings[] = $match[1];
            }
        }

        // website/.vitepress/config.mjs looks these up by title and fails the build without them.
        foreach (['Get started', 'Claude Code', 'Guides', 'Reference'] as $title) {
            self::assertContains($title, $headings);
        }
    }

    /**
     * The links that start a list item in docs/README.md, as the site reads them.
     *
     * @return list<array{target: string, page: string|null}>
     */
    private function indexLinks(): array
    {
        $links = [];
        foreach (file(self::repositoryRoot() . '/docs/README.md', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^- \[.+?]\(([^)\s]+)\)/', $line, $match) !== 1) {
                continue;
            }
            $file = explode('#', $match[1], 2)[0];
            $links[] = ['target' => $match[1], 'page' => str_ends_with($file, '.md') ? $file : null];
        }

        return $links;
    }

    /**
     * Every Markdown page under docs/, relative to it.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        $docs = self::repositoryRoot() . '/docs';
        $pages = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr((string) $file, strlen($docs) + 1);
            if (in_array(explode('/', $relative)[0], self::SCRATCH, true) || !str_ends_with($relative, '.md')) {
                continue;
            }
            $pages[] = $relative;
        }
        sort($pages);

        return $pages;
    }
}

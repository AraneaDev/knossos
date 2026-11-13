<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\CreationBrief;

use Knossos\Query\CreationBrief\CreationBriefRenderer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The brief as text: whole, trimmed from the bottom, and honest about a stale graph. */
#[Group('query')]
final class CreationBriefRendererTest extends KnossosTestCase
{
    /** @return array<string, mixed> */
    private static function brief(): array
    {
        return [
            'path' => 'src/Query/FooService.php',
            'exists' => false,
            'directory' => 'src/Query',
            'extension' => 'php',
            'role' => 'source',
            'placement' => ['boundary' => 'Query', 'may_not_use' => ['Cli', 'Mcp']],
            'basis' => ['directory' => 'src/Query', 'files' => 6, 'borrowed' => false],
            'neighbours' => ['src/Query/AService.php', 'src/Query/BService.php', 'src/Query/CService.php'],
            'conventions' => [
                ['id' => 'kind', 'text' => 'one class per file', 'files' => 6, 'of' => 6],
                ['id' => 'modifier:final', 'text' => 'final', 'files' => 6, 'of' => 6],
                ['id' => 'lines', 'text' => 'median 12 lines, largest 20', 'files' => 6, 'of' => 6],
            ],
            'goes_with' => [
                ['path' => 'tests/phpunit/Query/FooServiceTest.php', 'kind' => 'test'],
                ['path' => 'tools/lint-files.txt', 'commits' => 9, 'of' => 11, 'kind' => 'companion'],
            ],
            'gates' => [['name' => 'cs', 'command' => 'vendor/bin/php-cs-fixer fix'], ['name' => 'docblocks', 'command' => 'php tools/docstring-report.php']],
            'notes' => 2,
            'signature' => 'x',
        ];
    }

    public function testTheBriefReadsAsOneLinePerFact(): void
    {
        $expected = <<<'TXT'
        knossos · new file in Query
        may not use: Cli, Mcp
        like: src/Query/AService.php, src/Query/BService.php, src/Query/CService.php
        here: 6 of 6 one class per file · 6 of 6 final · median 12 lines, largest 20
        goes with: tests/phpunit/Query/FooServiceTest.php · tools/lint-files.txt (9 of 11 additions)
        gates: cs · docblocks. Run: vendor/bin/php-cs-fixer fix; php tools/docstring-report.php
        notes: 2 on the neighbours and the boundary (list_annotations)
        TXT;

        self::assertSame($expected, CreationBriefRenderer::lines(self::brief()));
    }

    /** A borrowed population says where it came from, and a line with nothing to say is left out. */
    public function testABorrowedFolderIsNamedAndEmptyLinesAreOmitted(): void
    {
        $data = self::brief();
        $data['basis']['borrowed'] = true;
        $data['placement'] = ['boundary' => null, 'may_not_use' => []];
        $data['neighbours'] = [];
        $data['goes_with'] = [];
        $data['gates'] = [];
        $data['notes'] = 0;

        self::assertSame(
            "knossos · new file in src/Query\nhere (in src/Query, the nearest folder with enough files): 6 of 6 one class per file · 6 of 6 final · median 12 lines, largest 20",
            CreationBriefRenderer::lines($data),
        );
    }

    public function testAnEmptyBriefRendersNothing(): void
    {
        $data = ['directory' => 'x', 'placement' => ['boundary' => null, 'may_not_use' => []], 'neighbours' => [], 'conventions' => [], 'goes_with' => [], 'gates' => [], 'notes' => 0];

        self::assertSame('', CreationBriefRenderer::lines($data));
        self::assertSame('', CreationBriefRenderer::lines($data, ['state' => 'stale', 'changed_files_since' => 3]));
    }

    /** Forty conventions do not fit: the text is cut from the bottom to 14 lines and 1,200 characters. */
    public function testALongBriefIsCutFromTheBottom(): void
    {
        $data = self::brief();
        $data['conventions'] = [];
        for ($i = 0; $i < 40; ++$i) {
            $data['conventions'][] = ['id' => 'uses:T' . $i, 'text' => 'use SomeFairlyLongTypeName' . $i, 'files' => 30, 'of' => 33];
        }

        $text = CreationBriefRenderer::lines($data);
        $lines = explode("\n", $text);

        self::assertLessThanOrEqual(1200, strlen($text));
        self::assertLessThanOrEqual(14, count($lines));
        self::assertSame('knossos · new file in Query', $lines[0]);
        self::assertStringNotContainsString('notes:', $text);
    }

    /** A line too long for the character budget goes whole, with everything below it. */
    public function testTheCharacterBudgetCutsWholeLines(): void
    {
        $data = self::brief();
        $data['gates'] = [['name' => 'long', 'command' => str_repeat('x', 1300)]];

        $text = CreationBriefRenderer::lines($data);

        self::assertLessThanOrEqual(1200, strlen($text));
        self::assertStringNotContainsString('gates:', $text);
        self::assertStringNotContainsString('notes:', $text);
        self::assertStringContainsString('goes with:', $text);
    }

    /** The stale line is the last of the 14 even when the other lines are cut. */
    public function testAStaleGraphKeepsItsLineAsTheLastOfFourteen(): void
    {
        $data = self::brief();
        $data['conventions'] = [];
        for ($i = 0; $i < 40; ++$i) {
            $data['conventions'][] = ['id' => 'uses:T' . $i, 'text' => 'use SomeLongTypeName' . $i, 'files' => 30, 'of' => 33];
        }
        $data['neighbours'] = [];
        $data['goes_with'] = [];
        $data['gates'] = [];
        $data['notes'] = 0;

        $lines = explode("\n", CreationBriefRenderer::lines($data, ['state' => 'stale', 'changed_files_since' => 4, 'added_files_since' => 2, 'deleted_files_since' => 1]));

        self::assertCount(14, $lines);
        self::assertSame('graph is behind: 4 changed, 2 added, 1 deleted since the scan', $lines[13]);
    }

    /** A graph that is not stale adds no line. */
    public function testAFreshGraphAddsNothing(): void
    {
        self::assertStringNotContainsString('graph is behind', CreationBriefRenderer::lines(self::brief(), ['state' => 'fresh']));
    }
}

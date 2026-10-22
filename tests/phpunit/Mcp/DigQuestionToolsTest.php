<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The pane's note reminder names question tools by string; a renamed tool would switch it off silently. */
final class DigQuestionToolsTest extends KnossosTestCase
{
    /**
     * Tools that are not destructive and are still not a lookup an agent digs
     * past, each with the reason. A non-destructive tool that writes, such as
     * a scan or a note, is listed here as `writes`.
     */
    private const NOT_QUESTIONS = [
        'server_info' => 'runtime',
        'diagnose_runtime' => 'runtime',
        'list_projects' => 'inventory',
        'scan_project' => 'writes',
        'annotate_component' => 'writes',
        'maintain_database' => 'writes',
        'list_snapshots' => 'history',
        'snapshot_diff' => 'history',
        'architecture_trends' => 'history',
        'architecture_summary' => 'overview',
        'list_diagnostics' => 'diagnostics',
        'quality_gate' => 'gate',
        'export_agent_brief' => 'export',
        'export_diagram' => 'export',
    ];

    #[Group('mcp')]
    public function testEveryQuestionToolTheReminderWatchesExists(): void
    {
        $registered = array_column(ToolCatalog::definitions(), 'name');

        self::assertSame([], array_values(array_diff(self::questions(), $registered)), 'dig.ts names tools the catalogue does not have.');
        self::assertSame([], array_values(array_diff(array_keys(self::NOT_QUESTIONS), $registered)), 'NOT_QUESTIONS names tools the catalogue does not have.');
        self::assertSame([], array_values(array_intersect(self::questions(), array_keys(self::NOT_QUESTIONS))), 'A tool is both a question and not one.');
    }

    /**
     * The reverse: a new tool that answers a question about the code and is
     * missing from QUESTIONS switches the reminder off for that tool silently.
     * Every tool that is not destructive is a question or says why not.
     * Most question tools may refresh a stale graph, so they do not carry
     * readOnlyHint, and destructiveHint is the line between reading and
     * removing what a project holds. A tool has to declare destructiveHint,
     * so leaving its annotations out cannot skip the check.
     */
    #[Group('mcp')]
    public function testEveryNonDestructiveToolIsAQuestionOrSaysWhyNot(): void
    {
        $uncounted = [];
        foreach (ToolCatalog::definitions() as $definition) {
            $annotations = $definition['annotations'] ?? null;
            self::assertIsArray($annotations, $definition['name'] . ' declares no annotations.');
            self::assertIsBool($annotations['destructiveHint'] ?? null, $definition['name'] . ' declares no destructiveHint.');
            if ($annotations['destructiveHint']) {
                continue;
            }
            $name = $definition['name'];
            if (!in_array($name, self::questions(), true) && !array_key_exists($name, self::NOT_QUESTIONS)) {
                $uncounted[] = $name;
            }
        }

        self::assertSame([], $uncounted, 'Neither in dig.ts QUESTIONS nor in NOT_QUESTIONS.');
    }

    /** @return list<string> the tool names dig.ts QUESTIONS holds */
    private static function questions(): array
    {
        $source = (string) file_get_contents(self::repositoryRoot() . '/hooks/mod/dig.ts');
        self::assertSame(1, preg_match('/const QUESTIONS(?:: [\w<>]+)? = new Set\(\[(.*?)\]\)/s', $source, $match), 'dig.ts declares no QUESTIONS set.');
        preg_match_all("/'([^'\s]+)'/", $match[1], $names);
        self::assertNotSame([], $names[1]);

        return $names[1];
    }
}

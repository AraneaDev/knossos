<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBrief;
use Knossos\Query\SessionBriefRenderer;
use Knossos\Query\SessionBriefService;
use Knossos\Query\SessionGates;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Where the gates block sits in the session brief, and that an unreadable knossos.json does not break it. */
final class SessionBriefGatesRenderTest extends KnossosTestCase
{
    #[Group('query')]
    public function testGatesSitBetweenTheNotesAndTheEntryPoints(): void
    {
        $brief = new SessionBrief(
            'fresh',
            'project_1',
            'Shop',
            '/work/shop',
            60,
            0,
            10,
            notes: ['A (user): n'],
            entryPoints: ['Main (command)'],
            gates: ['declared' => [['name' => 'cs', 'command' => 'fix']], 'found' => []],
        );

        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, str_contains($text, "Notes: A (user): n\nGates this project declares (run before pushing):\n- cs: fix\nEntry: Main (command)"));
    }

    #[Group('query')]
    public function testAnUnscannedBriefWithNothingReadShowsNoGates(): void
    {
        $brief = new SessionBrief('unscanned', null, null, '/work/shop', null, 0, 0);

        assertSame(false, str_contains((new SessionBriefRenderer())->render($brief), 'Gates'));
    }

    #[Group('query')]
    public function testAnUnreadableConfigurationGivesNoGates(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            file_put_contents($root . '/knossos.json', '{ not json');

            assertSame(SessionGates::none(), SessionGates::forRoot($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testScriptsFromThePackageManifestsAreFoundWhenNothingIsDeclared(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            file_put_contents($root . '/package.json', '{"scripts":{"test":"x","lint":"y"}}');
            file_put_contents($root . '/composer.json', '{"scripts":{"cs":"z"}}');

            assertSame(['declared' => [], 'found' => ['cs', 'lint', 'test']], SessionGates::forRoot($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAStaleBriefWithALongRulesSectionStillShowsTheFullGatesBlock(): void
    {
        $gates = ['declared' => [['name' => 'cs', 'command' => 'fix'], ['name' => 'lint', 'command' => 'check']], 'found' => []];
        $brief = new SessionBrief('stale', 'project_1', 'Shop', '/work/shop', 7200, 3, 10, rules: array_fill(0, 30, 'core -x-> tests and a long rule'), gates: $gates);

        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(false, str_contains($text, 'core -x->'));
        assertSame(true, str_contains($text, "Gates this project declares (run before pushing):\n- cs: fix\n- lint: check\n"));
    }

    #[Group('query')]
    public function testAnAllowedButUnscannedFolderShowsItsGates(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            file_put_contents($root . '/knossos.json', '{"version":1,"gates":[{"name":"cs","command":"fix"}]}');

            $text = (new SessionBriefRenderer())->render(SessionBriefService::unscanned($root));

            assertSame(true, str_contains($text, "Gates this project declares (run before pushing):\n- cs: fix\n"));
        } finally {
            $this->removeTempTree($root);
        }
    }
}

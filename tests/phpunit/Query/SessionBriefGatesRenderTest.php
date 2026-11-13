<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBrief;
use Knossos\Query\SessionBriefRenderer;
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
    public function testAnUnscannedBriefShowsNoGates(): void
    {
        $brief = new SessionBrief('unscanned', null, null, '/work/shop', null, 0, 0, gates: ['declared' => [['name' => 'cs', 'command' => 'fix']], 'found' => []]);

        assertSame(false, str_contains((new SessionBriefRenderer())->render($brief), 'Gates'));
    }

    #[Group('query')]
    public function testAnUnreadableConfigurationGivesNoGates(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            file_put_contents($root . '/knossos.json', '{ not json');

            assertSame(['declared' => [], 'found' => []], SessionGates::forRoot($root));
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
}

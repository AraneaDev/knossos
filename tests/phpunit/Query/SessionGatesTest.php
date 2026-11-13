<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Configuration\ProjectGate;
use Knossos\Query\SessionGates;
use Knossos\Query\SessionGatesRenderer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** What a session start says about a project's gates: the declared ones, or the scripts that could become gates. */
final class SessionGatesTest extends KnossosTestCase
{
    /** @return list<ProjectGate> */
    private static function gates(int $count): array
    {
        $gates = [];
        for ($i = 1; $i <= $count; ++$i) {
            $gates[] = new ProjectGate('gate-' . $i, 'run ' . $i);
        }

        return $gates;
    }

    /**
     * @param list<ProjectGate> $gates
     * @param array<string, mixed> $composer
     * @param array<string, mixed> $package
     * @return list<string>
     */
    private static function lines(array $gates, array $composer = [], array $package = []): array
    {
        return SessionGatesRenderer::lines(SessionGates::of(new ProjectConfiguration(gates: $gates), $composer, $package));
    }

    #[Group('query')]
    public function testDeclaredGatesAreListedWithTheirCommands(): void
    {
        assertSame([
            'Gates this project declares (run before pushing):',
            '- gate-1: run 1',
            '- gate-2: run 2',
        ], self::lines(self::gates(2)));
    }

    #[Group('query')]
    public function testFiveGatesFitAndSevenAreNamedOnOneLine(): void
    {
        assertSame(6, count(self::lines(self::gates(5))));

        $seven = self::lines(self::gates(7));
        assertSame([
            'Gates this project declares (run before pushing):',
            '- gate-1, gate-2, gate-3, gate-4, gate-5, gate-6, gate-7',
            '- commands: the "gates" list in knossos.json, or creation_brief for one path',
        ], $seven);
    }

    #[Group('query')]
    public function testWithoutDeclaredGatesTheScriptsFoundAreNamed(): void
    {
        assertSame(
            ['No gates declared. Scripts found: a, b, c, extra, lint, test. Declare the ones that must pass under "gates" in knossos.json.'],
            self::lines([], ['zed' => 'x', 'test' => 'x', 'b' => 'x'], ['lint' => 'x', 'a' => 'x', 'c' => 'x', 'test' => 'x', 'extra' => 'x']),
        );
    }

    #[Group('query')]
    public function testAProjectWithNeitherShowsNothing(): void
    {
        assertSame([], self::lines([]));
        assertSame([], self::lines([], [], []));
    }
}

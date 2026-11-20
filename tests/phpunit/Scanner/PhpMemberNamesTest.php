<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A PHP class-like node lists the member names it declares itself, for the
 * test-fidelity rules, and says when `__call` leaves its names open.
 */
#[Group('php-scanner')]
final class PhpMemberNamesTest extends KnossosTestCase
{
    public function testListsDeclaredAndDocblockMethods(): void
    {
        $nodes = $this->classLikes('members', 'src/Models.php');

        assertSame(['alpha', 'label', 'make', 'tags', 'zeta'], $nodes['Members\Widget']->attributes['member_names']);
        assertSame(['area'], $nodes['Members\Shape']->attributes['member_names']);
        assertSame(['greet'], $nodes['Members\Greets']->attributes['member_names']);
        assertSame(['cases', 'label'], $nodes['Members\Suit']->attributes['member_names']);
        assertSame(null, $nodes['Members\Widget']->attributes['member_names_open'] ?? null);
    }

    public function testAMagicClassIsOpen(): void
    {
        $nodes = $this->classLikes('nemesis-magic', 'src/Bag.php');

        assertSame(true, $nodes['Magic\Bag']->attributes['member_names_open']);
        assertSame(['__call', '__get'], $nodes['Magic\Bag']->attributes['member_names']);
    }

    public function testInheritedNamesStayWithTheParent(): void
    {
        $nodes = $this->classLikes('members', 'src/Models.php');

        assertSame(['inherited'], $nodes['Members\Base']->attributes['member_names']);
        assertSame(false, in_array('inherited', $nodes['Members\Widget']->attributes['member_names'], true));
    }

    public function testMethodTagFormsAreReadByTheNameBeforeTheParameterList(): void
    {
        $node = $this->classLikes('members', 'src/Edges.php')['Members\DocumentedForms'];

        assertSame(['bare', 'create', 'foo', 'list', 'union'], $node->attributes['member_names']);
        assertSame(null, $node->attributes['member_names_open'] ?? null);
    }

    public function testAMethodLineThatCannotBeReadOpensTheList(): void
    {
        $node = $this->classLikes('members', 'src/Edges.php')['Members\Malformed'];

        assertSame(['create'], $node->attributes['member_names']);
        assertSame(true, $node->attributes['member_names_open']);
    }

    public function testAMixinOpensTheList(): void
    {
        $node = $this->classLikes('members', 'src/Edges.php')['Members\Forwarding'];

        assertSame(true, $node->attributes['member_names_open']);
    }

    public function testTraitAliasesJoinTheUsingClass(): void
    {
        $node = $this->classLikes('members', 'src/Edges.php')['Members\UsesAliases'];

        assertSame(['bar', 'hidden'], $node->attributes['member_names']);
    }

    public function testEnumsCarryTheirBuiltIns(): void
    {
        $nodes = $this->classLikes('members', 'src/Edges.php');

        assertSame(['cases'], $nodes['Members\Plain']->attributes['member_names']);
        assertSame(['cases', 'from', 'tryFrom'], $nodes['Members\Backed']->attributes['member_names']);
    }

    /** @return array<string, object> */
    private function classLikes(string $fixture, string $file): array
    {
        $client = $this->phpWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/test-fidelity/php/' . $fixture,
                'files' => [$file],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $nodes = [];
        foreach ($contributions[0]->nodes as $node) {
            if (in_array($node->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                $nodes[$node->canonicalName] = $node;
            }
        }

        return $nodes;
    }
}

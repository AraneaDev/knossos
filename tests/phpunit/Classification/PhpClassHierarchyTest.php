<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\PhpClassHierarchy;
use Knossos\Tests\Phpunit\Support\PhpFacts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('classification')]
final class PhpClassHierarchyTest extends TestCase
{
    public function testAncestryCrossesProjectBasesAndTraits(): void
    {
        $hierarchy = PhpClassHierarchy::fromContributions([
            PhpFacts::contribution('App\\Livewire\\PostTable', 'app/Livewire/PostTable.php', 'App\\Livewire\\BaseComponent', ['updatedTitle', 'unusedAction', 'render'], ['App\\Livewire\\Concerns\\HasSorting']),
            PhpFacts::contribution('App\\Livewire\\BaseComponent', 'app/Livewire/BaseComponent.php', 'Livewire\\Component', ['notify']),
            PhpFacts::contribution('App\\Livewire\\Concerns\\HasSorting', 'app/Livewire/Concerns/HasSorting.php', null, ['mountHasSorting', 'sortBy'], [], 'trait'),
        ]);
        $table = 'php:class:App\\Livewire\\PostTable';

        self::assertSame(['php:class:App\\Livewire\\BaseComponent', 'Livewire\\Component'], $hierarchy->ancestorsLeaving($table));
        self::assertTrue($hierarchy->extendsExternal($table, ['Livewire\\Component']));
        self::assertFalse($hierarchy->extendsExternal($table, ['Illuminate\\Database\\Eloquent\\Model']));
        $methods = array_keys($hierarchy->methods($table));
        sort($methods);
        self::assertSame(['mountHasSorting', 'notify', 'render', 'sortBy', 'unusedAction', 'updatedTitle'], $methods);
        self::assertSame(['php:trait:App\\Livewire\\Concerns\\HasSorting'], $hierarchy->traitsOf($table));
        self::assertSame([$table], $hierarchy->classesIn('app/Livewire/PostTable.php'));
        self::assertSame('App\\Livewire\\PostTable', $hierarchy->node($table)?->canonicalName);
        self::assertNull($hierarchy->node('php:class:Missing'));
    }

    public function testTheNearestDeclarationWinsAndTraitsOfTraitsCount(): void
    {
        $hierarchy = PhpClassHierarchy::fromContributions([
            PhpFacts::contribution('A\\Child', 'a/Child.php', 'A\\Base', ['own'], ['A\\Outer']),
            PhpFacts::contribution('A\\Base', 'a/Base.php', null, ['own', 'inherited']),
            PhpFacts::contribution('A\\Outer', 'a/Outer.php', null, ['fromOuter'], ['A\\Inner'], 'trait'),
            PhpFacts::contribution('A\\Inner', 'a/Inner.php', null, ['fromInner', 'own'], [], 'trait'),
        ]);

        $methods = $hierarchy->methods('php:class:A\\Child');

        self::assertSame('php:method:A\\Child::own', $methods['own']);
        self::assertSame('php:method:A\\Inner::fromInner', $methods['fromInner']);
        self::assertSame('php:method:A\\Base::inherited', $methods['inherited']);
        self::assertSame(['php:trait:A\\Outer', 'php:trait:A\\Inner'], $hierarchy->traitsOf('php:class:A\\Child'));
    }

    public function testACycleOfTwoClassesTerminates(): void
    {
        $hierarchy = PhpClassHierarchy::fromContributions([
            PhpFacts::contribution('A\\One', 'a/One.php', 'A\\Two', ['one']),
            PhpFacts::contribution('A\\Two', 'a/Two.php', 'A\\One', ['two']),
        ]);

        self::assertSame(['php:class:A\\Two'], $hierarchy->ancestorsLeaving('php:class:A\\One'));
        self::assertFalse($hierarchy->extendsExternal('php:class:A\\One', ['A\\One']));
        self::assertSame(['one', 'two'], array_keys($hierarchy->methods('php:class:A\\One')));
    }

    public function testModuleAttributesAndObserversAreReadFromTheFacts(): void
    {
        $module = new \Knossos\Scanner\Protocol\NodeFact(
            'php:module:config/livewire.php',
            'module',
            'config/livewire.php',
            'config/livewire.php',
            \Knossos\Scanner\Protocol\Origin::Ast,
            \Knossos\Scanner\Protocol\Confidence::Certain,
            new \Knossos\Scanner\Protocol\Evidence('config/livewire.php', 1, 1),
            ['livewire_config' => ['view_path' => null]],
        );
        $hierarchy = PhpClassHierarchy::fromContributions([
            PhpFacts::contribution('A\\Model', 'a/Model.php', null, [], [], 'class', [$module], [PhpFacts::edge('observes', 'php:class:A\\Model', 'php:class:A\\Watcher')]),
        ]);

        self::assertSame(['view_path' => null], $hierarchy->moduleAttributes('config/livewire.php')['livewire_config']);
        self::assertSame([], $hierarchy->moduleAttributes('config/none.php'));
        self::assertSame(['php:class:A\\Watcher'], $hierarchy->observers());
    }
}

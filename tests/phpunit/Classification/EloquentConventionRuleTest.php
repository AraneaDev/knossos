<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\ClassificationEngine;
use Knossos\Classification\EloquentConventionRule;
use Knossos\Classification\LaravelPathRoleRule;
use Knossos\Classification\PhpClassHierarchy;
use Knossos\Tests\Phpunit\Support\PhpFacts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('classification')]
final class EloquentConventionRuleTest extends TestCase
{
    public function testLegacyAccessorsOnModelsAndObserverHooks(): void
    {
        $contributions = [
            PhpFacts::contribution('App\\Models\\User', 'app/Models/User.php', 'Illuminate\\Foundation\\Auth\\User', ['getDisplayNameAttribute', 'getAttribute']),
            PhpFacts::contribution('App\\Models\\Base', 'app/Models/Base.php', 'Illuminate\\Database\\Eloquent\\Model'),
            PhpFacts::contribution('App\\Models\\Post', 'app/Models/Post.php', 'App\\Models\\Base', ['getShoutedTitleAttribute', 'setTitleAttribute'], [], 'class', [], [
                PhpFacts::edge('observes', 'php:class:App\\Models\\Post', 'php:class:App\\Watchers\\PostObserver'),
            ]),
            PhpFacts::contribution('App\\Support\\Plain', 'app/Support/Plain.php', null, ['getFooAttribute']),
            PhpFacts::contribution('App\\Watchers\\PostObserver', 'app/Watchers/PostObserver.php', null, ['created', 'helper']),
        ];
        $facts = (new ClassificationEngine([new EloquentConventionRule(PhpClassHierarchy::fromContributions($contributions))]))->classify($contributions);
        $roles = [];
        foreach ($facts as $fact) {
            $roles[$fact->nodeReference][] = $fact->role;
        }

        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Models\\User::getDisplayNameAttribute']);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Models\\Post::getShoutedTitleAttribute']);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Models\\Post::setTitleAttribute']);
        self::assertArrayNotHasKey('php:method:App\\Models\\User::getAttribute', $roles, 'Model::getAttribute is not a named accessor.');
        self::assertArrayNotHasKey('php:method:App\\Support\\Plain::getFooAttribute', $roles);
        self::assertSame(['laravel.observer'], $roles['php:class:App\\Watchers\\PostObserver']);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Watchers\\PostObserver::created']);
        self::assertArrayNotHasKey('php:method:App\\Watchers\\PostObserver::helper', $roles);
        $accessor = array_values(array_filter($facts, static fn($fact): bool => $fact->nodeReference === 'php:method:App\\Models\\User::getDisplayNameAttribute'));
        self::assertSame(['class_role' => 'laravel.model', 'reason' => 'accessor'], $accessor[0]->attributes);
    }

    public function testAnObserverTheDirectoryRuleOwnsIsNotClassifiedTwice(): void
    {
        $contributions = [
            PhpFacts::contribution('App\\Models\\Post', 'app/Models/Post.php', 'Illuminate\\Database\\Eloquent\\Model', [], [], 'class', [], [
                PhpFacts::edge('observes', 'php:class:App\\Models\\Post', 'php:class:App\\Observers\\PostObserver'),
            ]),
            PhpFacts::contribution('App\\Observers\\PostObserver', 'app/Observers/PostObserver.php', null, ['created']),
        ];
        $rules = [new LaravelPathRoleRule(), new EloquentConventionRule(PhpClassHierarchy::fromContributions($contributions))];

        $facts = (new ClassificationEngine($rules))->classify($contributions);
        $observerRoles = array_map(static fn($fact): string => $fact->nodeReference . ' ' . $fact->role, array_filter($facts, static fn($fact): bool => str_contains($fact->nodeReference, 'PostObserver')));

        self::assertSame([
            'php:class:App\\Observers\\PostObserver laravel.observer',
            'php:method:App\\Observers\\PostObserver::created laravel.entry_method',
        ], array_values($observerRoles));
    }

    public function testTheDirectoryRuleKnowsObserverMethods(): void
    {
        $contributions = [PhpFacts::contribution('App\\Observers\\X', 'app/Observers/X.php', null, ['updated', 'helper'])];
        $facts = (new ClassificationEngine([new LaravelPathRoleRule()]))->classify($contributions);

        self::assertSame(
            ['php:class:App\\Observers\\X laravel.observer', 'php:method:App\\Observers\\X::updated laravel.entry_method'],
            array_map(static fn($fact): string => $fact->nodeReference . ' ' . $fact->role, $facts),
        );
    }
}

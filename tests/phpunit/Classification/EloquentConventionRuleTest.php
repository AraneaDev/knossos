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

    public function testMethodsReachedThroughTraitsAndBaseObserversAreClassified(): void
    {
        $contributions = [
            PhpFacts::contribution('App\\Concerns\\HasFullName', 'app/Concerns/HasFullName.php', null, ['getFullNameAttribute', 'helper'], [], 'trait'),
            PhpFacts::contribution('App\\Models\\Person', 'app/Models/Person.php', 'Illuminate\\Database\\Eloquent\\Model', [], ['App\\Concerns\\HasFullName'], 'class', [], [
                PhpFacts::edge('observes', 'php:class:App\\Models\\Person', 'php:class:App\\Watchers\\PersonObserver'),
            ]),
            PhpFacts::contribution('App\\Concerns\\Audits', 'app/Concerns/Audits.php', null, ['saved', 'audit'], [], 'trait'),
            PhpFacts::contribution('App\\Watchers\\BaseObserver', 'app/Watchers/BaseObserver.php', null, ['created', 'basehelper'], ['App\\Concerns\\Audits']),
            PhpFacts::contribution('App\\Watchers\\PersonObserver', 'app/Watchers/PersonObserver.php', 'App\\Watchers\\BaseObserver', ['deleted']),
            PhpFacts::contribution('App\\Watchers\\Stranger', 'app/Watchers/Stranger.php', 'App\\Watchers\\BaseObserver', ['created']),
        ];
        $facts = (new ClassificationEngine([new EloquentConventionRule(PhpClassHierarchy::fromContributions($contributions))]))->classify($contributions);
        $roles = [];
        foreach ($facts as $fact) {
            $roles[$fact->nodeReference][] = $fact->role;
        }

        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Concerns\\HasFullName::getFullNameAttribute'] ?? null);
        self::assertArrayNotHasKey('php:method:App\\Concerns\\HasFullName::helper', $roles);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Watchers\\BaseObserver::created'] ?? null);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Concerns\\Audits::saved'] ?? null);
        self::assertSame(['laravel.entry_method'], $roles['php:method:App\\Watchers\\PersonObserver::deleted'] ?? null);
        self::assertArrayNotHasKey('php:method:App\\Watchers\\BaseObserver::basehelper', $roles);
        self::assertArrayNotHasKey('php:method:App\\Concerns\\Audits::audit', $roles);
        self::assertArrayNotHasKey('php:method:App\\Watchers\\Stranger::created', $roles);
    }

    public function testAnObserverUnderAnEarlierPathFragmentStillGetsItsRole(): void
    {
        $contributions = [
            PhpFacts::contribution('App\\Models\\Person', 'app/Models/Person.php', 'Illuminate\\Database\\Eloquent\\Model', [], [], 'class', [], [
                PhpFacts::edge('observes', 'php:class:App\\Models\\Person', 'php:class:App\\Models\\Observers\\PersonObserver'),
            ]),
            PhpFacts::contribution('App\\Models\\Observers\\PersonObserver', 'app/Models/Observers/PersonObserver.php', null, ['created']),
        ];
        $rules = [new LaravelPathRoleRule(), new EloquentConventionRule(PhpClassHierarchy::fromContributions($contributions))];

        $facts = (new ClassificationEngine($rules))->classify($contributions);
        $observer = array_map(static fn($fact): string => $fact->nodeReference . ' ' . $fact->role, array_filter($facts, static fn($fact): bool => str_contains($fact->nodeReference, 'PersonObserver')));

        self::assertContains('php:class:App\\Models\\Observers\\PersonObserver laravel.observer', $observer);
        self::assertContains('php:method:App\\Models\\Observers\\PersonObserver::created laravel.entry_method', $observer);
        self::assertSame('laravel.model', LaravelPathRoleRule::roleForPath('app/Models/Observers/PersonObserver.php'));
        self::assertSame('laravel.observer', LaravelPathRoleRule::roleForPath('app/Observers/PostObserver.php'));
        self::assertNull(LaravelPathRoleRule::roleForPath('app/Support/Plain.php'));
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

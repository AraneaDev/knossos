<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\ScanLimits;
use PHPUnit\Framework\Attributes\Group;

/**
 * A class method that fulfils a member of a type the class extends or
 * implements is marked `overrides`: the source says so with `override`, or the
 * checker finds the member on a heritage type or, for an object literal, on
 * the type of the parameter it is handed to, or a `super` call of the
 * method's own name names it. A dependency's base class or a
 * built-in interface is not in the graph, so without the mark the dispatch
 * through it reads as no reference at all.
 */
#[Group('typescript-scanner')]
final class TypescriptSupertypeMemberTest extends KnossosTestCase
{
    public function testMethodsFulfillingAHeritageTypesMemberAreMarked(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/typescript-supertypes',
                'files' => ['src/walker.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        $overrides = [];
        foreach ($contributions[0]->nodes as $node) {
            if ($node->kind === 'method') {
                $overrides[$node->canonicalName] = $node->attributes['overrides'] ?? false;
            }
        }
        ksort($overrides);

        self::assertSame([
            'src/walker.ts#Factory::enter' => false,
            // The contract's own member is not a fulfilment of it.
            'src/walker.ts#Hooks::resolve' => false,
            'src/walker.ts#Walker::enter' => true,
            'src/walker.ts#Walker::helper' => false,
            'src/walker.ts#Walker::leave' => true,
            'src/walker.ts#Walker::next' => true,
            // A literal handed to a parameter its type names.
            'src/walker.ts#{object}@36:10::resolve' => true,
        ], $overrides);
    }

    /**
     * A dependency that ships no types gives the checker an `any` base, which
     * names no member. A `super` call of the method's own name fails unless
     * the base has that member, so it is the one sign left.
     */
    public function testASuperCallOfTheMethodsOwnNameMarksItWhenTheBaseIsUntyped(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/typescript-supertypes',
                'files' => ['src/deferring.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        $overrides = [];
        foreach ($contributions[0]->nodes as $node) {
            if ($node->kind === 'method') {
                $overrides[$node->canonicalName] = $node->attributes['overrides'] ?? false;
            }
        }
        ksort($overrides);

        self::assertSame([
            'src/deferring.ts#Dim::draw' => true,
            // A nested function is code of its own, which may never run.
            'src/deferring.ts#Dim::later' => false,
            // Calling a different base member proves nothing about this one.
            'src/deferring.ts#Dim::tidy' => false,
        ], $overrides);
    }

    /**
     * A union names a member only when every part has it, so the contract of
     * an optional parameter (`Options | undefined`) or of a loader-or-options
     * one (`Loader | AsyncOptions`) went unseen and the library's callbacks
     * were reported as possibly dead.
     */
    public function testALiteralHandedToAUnionTypedParameterFulfilsThePartNamingTheMember(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['limits' => ScanLimits::DEFAULT,
                'root' => self::repositoryRoot() . '/tests/Fixtures/typescript-supertypes',
                'files' => ['src/options.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        $overrides = [];
        foreach ($contributions[0]->nodes as $node) {
            if ($node->kind === 'method') {
                $overrides[$node->canonicalName] = $node->attributes['overrides'] ?? false;
            }
        }
        ksort($overrides);

        self::assertSame([
            'src/options.ts#AsyncOptions::loader' => false,
            'src/options.ts#Options::handler' => false,
            'src/options.ts#Options::setHeaders' => false,
            'src/options.ts#{object}@10:30::loader' => true,
            // Inferred from the literal itself, beside `undefined`: no contract.
            'src/options.ts#{object}@11:7::run' => false,
            'src/options.ts#{object}@8:30::handler' => true,
            'src/options.ts#{object}@9:12::handler' => true,
            'src/options.ts#{object}@9:12::setHeaders' => true,
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ReportableComponent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The exclusions health and the gate share, read off a node and its roles alone. */
#[Group('query')]
final class ReportableComponentTest extends TestCase
{
    public function testEachGraphLocalGroundNamesItsCounter(): void
    {
        $method = static fn(array $attributes): array => ['kind' => 'method', 'attributes_json' => json_encode($attributes, JSON_THROW_ON_ERROR)];

        self::assertSame('inherited', ReportableComponent::graphLocalExclusion($method(['overrides' => true]), []));
        self::assertSame('constructors', ReportableComponent::graphLocalExclusion($method(['runtime_invoked' => true]), []));
        self::assertSame('entry_scripts', ReportableComponent::graphLocalExclusion(['kind' => 'module', 'attributes_json' => '{"executable":true}'], []));
        self::assertSame('type_declarations', ReportableComponent::graphLocalExclusion($method(['declaration_file' => true]), []));
        self::assertSame('convention_discovered', ReportableComponent::graphLocalExclusion(['kind' => 'class'], ['laravel.controller']));
        self::assertNull(ReportableComponent::graphLocalExclusion(['kind' => 'class', 'attributes_json' => '{}'], ['application.service']));
    }

    /** The node's own mark wins over a role, in the order health charges its counters. */
    public function testAnAttributeGroundComesBeforeAConventionRole(): void
    {
        $node = ['kind' => 'method', 'attributes_json' => '{"overrides":true,"runtime_invoked":true}'];

        self::assertSame('inherited', ReportableComponent::graphLocalExclusion($node, ['symfony.controller']));
    }
}

<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Query\EntryPointCriteria;
use Knossos\Query\ReportableComponent;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A framework calls these by convention: a URL router, a middleware stack, a
 * task scheduler, an authorization gate, a file-system router. Nothing in the
 * code names them, so outside the convention list each read as possibly dead
 * (25 Django views in one project, Next.js's own page and layout).
 */
#[Group('classification')]
final class FrameworkInvokedRolesTest extends KnossosTestCase
{
    /** @return array<string, array{string}> */
    public static function frameworkInvoked(): array
    {
        return [
            'django view' => ['django.view'],
            'django middleware' => ['django.middleware'],
            'fastapi route handler' => ['fastapi.route_handler'],
            'flask route handler' => ['flask.route_handler'],
            'nextjs route handler' => ['nextjs.route_handler'],
            'rust route handler' => ['rust.route_handler'],
            'celery task' => ['python.task'],
            'laravel policy' => ['laravel.policy'],
            'laravel middleware' => ['laravel.middleware'],
            'nextjs page' => ['nextjs.page'],
            'nextjs layout' => ['nextjs.layout'],
        ];
    }

    #[DataProvider('frameworkInvoked')]
    public function testARoleAFrameworkInvokesIsAConvention(string $role): void
    {
        self::assertTrue(ReportableComponent::isDiscoveredByConvention([$role]));
    }

    /** A request starts at a view, a route handler or a page: each is an entry point. */
    public function testARequestHandlerIsAnEntryPoint(): void
    {
        foreach (['django.view', 'fastapi.route_handler', 'nextjs.page'] as $role) {
            self::assertContains($role, EntryPointCriteria::ROLES);
        }
    }

    /**
     * A component JSX does not name may really be unused, and `laravel.event`
     * and `application.middleware` are also given by a class name's suffix
     * alone, in any project: none is a convention.
     */
    public function testARoleNamedByUseOrNameAloneIsNoConvention(): void
    {
        self::assertFalse(ReportableComponent::isDiscoveredByConvention(['react.component']));
        self::assertFalse(ReportableComponent::isDiscoveredByConvention(['laravel.event']));
        self::assertFalse(ReportableComponent::isDiscoveredByConvention(['application.middleware']));
    }
}

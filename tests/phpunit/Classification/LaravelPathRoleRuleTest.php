<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\LaravelPathRoleRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The role Laravel's directory convention gives a file, which other rules
 * ask before claiming a node.
 */
#[Group('classification')]
final class LaravelPathRoleRuleTest extends TestCase
{
    /** The first fragment of the path that matches decides, so an observer under `app/Models/` is a model by its path. */
    public function testRoleForPathTakesTheFirstMatchingFragment(): void
    {
        self::assertSame('laravel.model', LaravelPathRoleRule::roleForPath('app/Models/Observers/PersonObserver.php'));
        self::assertSame('laravel.observer', LaravelPathRoleRule::roleForPath('app/Observers/PostObserver.php'));
        self::assertNull(LaravelPathRoleRule::roleForPath('app/Support/Plain.php'));
    }
}

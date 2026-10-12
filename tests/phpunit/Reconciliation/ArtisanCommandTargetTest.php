<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\EdgeTargetResolver;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('reconciliation')]
final class ArtisanCommandTargetTest extends KnossosTestCase
{
    public function testInvokableAndMissingCommandEntries(): void
    {
        $nodes = [];
        foreach (['Invoke' => 'orders:invoke', 'Missing' => 'orders:missing', 'Both' => 'orders:both'] as $class => $name) {
            $canonical = $class . '::$signature';
            $nodes[] = new NodeFact('php:property:' . $canonical, 'property', $canonical, $canonical, Origin::Ast, Confidence::Certain, new Evidence('Commands.php', 1, 1), ['artisan_command' => $name]);
        }
        $resolver = EdgeTargetResolver::forContributions([
            'php:method:Invoke::__invoke' => 'invoke',
            'php:method:Both::handle' => 'handle',
            'php:method:Both::__invoke' => 'other',
        ], [new ScanContribution('knossos.php:file:Commands.php', $nodes)]);

        self::assertSame(['php:artisan_command:orders:invoke', 'invoke'], $resolver->resolve('php:artisan_command:orders:invoke', false));
        self::assertSame(['php:artisan_command:orders:both', 'handle'], $resolver->resolve('php:artisan_command:orders:both', false));
        self::assertSame(['php:artisan_command:orders:missing', null], $resolver->resolve('php:artisan_command:orders:missing', false));
        self::assertSame(['php:artisan_command:migrate', null], $resolver->resolve('php:artisan_command:migrate', false));
    }
}

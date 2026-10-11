<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Reconciliation\UrlTemplate;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class UrlTemplateTest extends KnossosTestCase
{
    #[Group('reconciliation')]
    public function testParameterFormsAllBecomeOneShape(): void
    {
        foreach (['/orders/{id}', '/orders/<int:id>', '/orders/<id>', '/orders/:id', '/orders/{}'] as $path) {
            assertSame('/orders/{}', (string) UrlTemplate::parse($path)[0], $path);
        }
    }

    #[Group('reconciliation')]
    public function testQueryFragmentSlashesAndTrailingSlashAreDropped(): void
    {
        assertSame('/api/orders', (string) UrlTemplate::parse('api//orders/?page=2#top')[0]);
        assertSame('/', (string) UrlTemplate::parse('/')[0]);
    }

    #[Group('reconciliation')]
    public function testAnOptionalLaravelParameterGivesBothTemplates(): void
    {
        assertSame(['/posts/{}', '/posts'], array_map('strval', UrlTemplate::parse('posts/{slug?}')));
    }

    #[Group('reconciliation')]
    public function testAnAbsoluteUrlOrARegexIsNoPath(): void
    {
        assertSame([], UrlTemplate::parse('https://api.example.com/orders'));
        assertSame([], UrlTemplate::parse('//cdn.example.com/x'));
        assertSame([], UrlTemplate::parse('^orders/(?P<id>\d+)/$'));
        assertSame([], UrlTemplate::parse(''));
    }

    #[Group('reconciliation')]
    public function testMatchingRules(): void
    {
        $t = static fn(string $p): UrlTemplate => UrlTemplate::parse($p)[0];
        assertSame(true, $t('/orders/new')->equalsExactly($t('/orders/new')));
        assertSame(false, $t('/orders/{}')->equalsExactly($t('/orders/{id}')));
        assertSame(true, $t('/orders/42')->matchesWithParameters($t('/orders/{id}')));
        assertSame(true, $t('/orders/{}')->matchesWithParameters($t('/orders/{id}')));
        assertSame(false, $t('/orders/{}')->matchesWithParameters($t('/orders/new')));
        assertSame(true, $t('/api/orders/42')->endsWith($t('/orders/{id}')));
        assertSame(false, $t('/api/orders')->endsWith($t('/api/orders/{id}')));
        assertSame(true, $t('/{slug}')->isAllParameters());
        assertSame(1, $t('/orders/{id}')->literalCount());
        assertSame('/api/orders', (string) $t('/orders')->prepend($t('/api')));
    }
}

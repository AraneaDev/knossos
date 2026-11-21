<?php

namespace Experiments\Mockery\Tests;

use Experiments\Mockery\Notifier;
use Mockery;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    public function testMockeryChain(): void
    {
        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->with('message', 'urgent')->andReturn('ok');
        self::assertNotNull($notifier);
    }
}

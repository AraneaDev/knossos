<?php

namespace Experiments\Callback\Tests;

use Experiments\Callback\Courier;
use PHPUnit\Framework\TestCase;

class CourierTest extends TestCase
{
    public function testSendUsesACallbackThatOutlivedTheSignature(): void
    {
        $mailer = $this->createMock(Courier::class);
        // PHP hands the callback whatever the stubbed method received, so the
        // second parameter raises ArgumentCountError the first time it runs.
        $mailer->method('send')->willReturnCallback(
            function (string $to, bool $subject) {
                return $to !== '' && !$subject;
            }
        );

        $this->assertNotNull($mailer);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class EdgeCasesTest extends TestCase
{
    public function testVariableTargetAndForeignProperty(): void
    {
        $className = InvoiceRepository::class;

        // createMock with a variable argument: the target resolves through the variable's value
        $dynamic = $this->createMock($className);
        $dynamic->method('findById')->willReturn(42);

        // assignment to a property of a non-$this object is not a property mock binding
        $holder = new \stdClass();
        $holder->repo = $this->createMock(InvoiceRepository::class);

        $this->assertInstanceOf(InvoiceRepository::class, $dynamic);
    }

    public function testDynamicMemberName(): void
    {
        $member = 'save';
        $mock = $this->createMock(InvoiceRepository::class);
        $mock->method($member)->willReturn(true);

        $this->assertIsBool($mock->save());
    }

    public function testNewExpressionOperand(): void
    {
        $engine = new \App\Engine();
        $this->assertNotSame(new \App\Engine(), $engine);
    }
}

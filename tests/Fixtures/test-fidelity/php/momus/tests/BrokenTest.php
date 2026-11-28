<?php

namespace App\Tests;

final class BrokenTest extends TestCase
{
    public function testMissingClosingBrace(): void
    {
        $mock = $this->createMock(InvoiceRepository::class);
        $mock->method('findById')->willReturn(1);
        self::assertSame(1, $mock->findById(1));

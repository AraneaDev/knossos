<?php

namespace App\Tests;

use App\InvoiceRepository;
use PHPUnit\Framework\TestCase;

final class AnonymousTest extends TestCase
{
    public function testPlantedStaleOverride(): void
    {
        $repo = new class extends InvoiceRepository {
            public function staleMethod(): void
            {
            }
        };
        self::assertNotSame($repo, null);
    }

    public function testHealthyOverride(): void
    {
        $repo = new class extends InvoiceRepository {
            public function findById($id)
            {
                return null;
            }
        };
        self::assertNotSame($repo, null);
    }
}

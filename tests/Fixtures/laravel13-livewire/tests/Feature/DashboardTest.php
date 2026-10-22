<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use Livewire\Livewire;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    public function test_load_more(): void
    {
        Livewire::test(Dashboard::class)->call('loadMore')->assertSet('limit', 20);
    }
}

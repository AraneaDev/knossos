<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

// Discovered by Laravel from app/Console/Commands and run by hand: nothing schedules it.
class RebuildSearch extends Command
{
    protected $signature = 'search:rebuild';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

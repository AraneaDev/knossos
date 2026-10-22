<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneDrafts extends Command
{
    protected $signature = 'drafts:prune';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

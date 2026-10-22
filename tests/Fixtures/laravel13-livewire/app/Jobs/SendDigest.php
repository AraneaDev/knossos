<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
class SendDigest implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        logger()->info('digest');
    }
}

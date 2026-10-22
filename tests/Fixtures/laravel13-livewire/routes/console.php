<?php

use App\Console\Commands\PruneDrafts;
use App\Jobs\SendDigest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
});

Schedule::command(PruneDrafts::class)->daily();
Schedule::job(new SendDigest())->hourly();

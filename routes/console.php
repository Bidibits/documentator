<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// the archive rules, every night by the office clock (needs the scheduler: `php artisan schedule:work` while developing,
// one cron line `* * * * * php artisan schedule:run` on a server)
Schedule::command('documentator:archive-due')->dailyAt('02:00')->timezone(config('documentator.timezone'))->withoutOverlapping();

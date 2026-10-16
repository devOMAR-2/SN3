<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Operational schedule, run by the `scheduler` service (`php artisan schedule:work`).
| onOneServer() keeps a task to one run per slot if the scheduler is ever replicated;
| it locks through the default cache store (database). See ADR-015.
*/

// Failed jobs stay visible for 30 days (operational retention, DECIDED-004), then go.
Schedule::command('queue:prune-failed', ['--hours' => 720])
    ->dailyAt('03:00')
    ->onOneServer();

Schedule::command('queue:prune-batches', ['--hours' => 48, '--unfinished' => 72, '--cancelled' => 72])
    ->dailyAt('03:10')
    ->onOneServer();

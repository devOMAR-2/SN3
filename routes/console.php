<?php

use App\Models\ApiIdempotencyRecord;
use Illuminate\Support\Facades\Schedule;

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

// Expired password-reset tokens (FND-004).
Schedule::command('auth:clear-resets')
    ->dailyAt('03:20')
    ->onOneServer();

// Idempotency-Key results are kept for 24 hours (FND-005, API_SPEC.md §1.4).
Schedule::command('model:prune', ['--model' => [ApiIdempotencyRecord::class]])
    ->dailyAt('03:30')
    ->onOneServer();

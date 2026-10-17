<?php

use App\Models\ApiIdempotencyRecord;
use App\Models\TaskComment;
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

// Idempotency-Key results are kept for 24 hours (FND-005, API_SPEC.md §1.4); deleted comments for
// the 30-day recovery window (CORE-008, DATABASE.md §5, ADR-027 §2).
Schedule::command('model:prune', ['--model' => [ApiIdempotencyRecord::class, TaskComment::class]])
    ->dailyAt('03:30')
    ->onOneServer();

// Teams and projects trashed more than 30 days ago are purged for good (CORE-010, DECIDED-011,
// ADR-022). Idempotent and local only: it never deletes anything on GitHub.
Schedule::command('trash:purge')
    ->dailyAt('03:40')
    ->onOneServer()
    // The overlap lock expires after 60 minutes, so a crashed run cannot block the next day's.
    ->withoutOverlapping(60);

// Team exports and their archives are deleted 24 hours after they complete (CORE-012, API_SPEC.md
// §13, ADR-024 §4). Hourly, so an archive outlives its expiry by at most an hour.
Schedule::command('exports:prune')
    ->hourlyAt(50)
    ->onOneServer()
    ->withoutOverlapping(60);

// Attachment files that no attachment needs any more: removals the storage could not finish, files
// of purged projects and teams, and orphans of rolled-back uploads (CORE-008, ADR-027 §5).
Schedule::command('attachments:sweep')
    ->hourlyAt(35)
    ->onOneServer()
    ->withoutOverlapping(60);

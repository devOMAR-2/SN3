<?php

use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

// Outside tests the console kernel always fires CommandStarting/CommandFinished.
uses(WithConsoleEvents::class);

afterEach(function () {
    foreach ([Heartbeat::QUEUE_WORKER, Heartbeat::SCHEDULER] as $name) {
        @unlink(Heartbeat::path($name));
    }
});

function scheduledCommand(string $command): ?ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, $command));
}

it('schedules daily pruning of failed jobs after the 30-day retention', function () {
    $event = scheduledCommand('queue:prune-failed');

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--hours=720')
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules daily pruning of job batches', function () {
    $event = scheduledCommand('queue:prune-batches');

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--hours=48')
        ->and($event->command)->toContain('--unfinished=72')
        ->and($event->command)->toContain('--cancelled=72')
        ->and($event->expression)->toBe('10 3 * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules daily pruning of expired idempotency records', function () {
    $event = scheduledCommand('model:prune');

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('ApiIdempotencyRecord')
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules the daily trash purge (CORE-010)', function () {
    $event = scheduledCommand('trash:purge');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 3 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('lists only the operational tasks', function () {
    expect(app(Schedule::class)->events())->toHaveCount(5);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('queue:prune-failed --hours=720')
        ->expectsOutputToContain('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')
        ->expectsOutputToContain('auth:clear-resets')
        ->expectsOutputToContain('model:prune')
        ->expectsOutputToContain('trash:purge')
        ->assertSuccessful();
});

it('touches the worker heartbeat on every worker loop', function () {
    @unlink(Heartbeat::path(Heartbeat::QUEUE_WORKER));

    Event::dispatch(new Looping('database', 'default'));

    expect(Heartbeat::path(Heartbeat::QUEUE_WORKER))->toBeFile()
        ->and(time() - filemtime(Heartbeat::path(Heartbeat::QUEUE_WORKER)))->toBeLessThan(5);
});

it('touches the scheduler heartbeat after each schedule:run', function () {
    @unlink(Heartbeat::path(Heartbeat::SCHEDULER));
    // A minute when no task is due, so nothing else runs.
    $this->travelTo(now()->setTime(12, 0));

    Artisan::call('schedule:run');

    expect(Heartbeat::path(Heartbeat::SCHEDULER))->toBeFile();
});

it('does not touch the scheduler heartbeat for other commands', function () {
    @unlink(Heartbeat::path(Heartbeat::SCHEDULER));

    Artisan::call('schedule:list');

    expect(Heartbeat::path(Heartbeat::SCHEDULER))->not->toBeFile();
});

<?php

require_once __DIR__.'/../Tasks/TaskHelpers.php';

use App\Domain\Notifications\TaskWatchers;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// CORE-009 under real concurrency (ADR-029 §2, §8). PHP processes race on committed data, released
// together from an advisory-lock barrier, like TaskConcurrencyTest. So this file does not use
// RefreshDatabase; it cleans up after itself.
//
// - API_SPEC.md §9 "batch/dedupe noisy events": simultaneous deliveries of edits to one watcher end as
//   one unread notification counting every edit, with every field name, never as duplicates or a
//   unique-violation error.
// - NOT-01 / FR-COL-005: a watch racing the watcher's removal from the private project either fails
//   (404) or lands before the removal; either way the removed member receives nothing afterwards.

const NOTIFY_RACE_BARRIER_KEY = 640_009;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team);
    $this->watcher = taskTeamMember($this->team);
    $this->project = taskProject($this->team, 'private', ['project_key' => 'NRACE']);
    taskProjectMember($this->project, $this->member);
    taskProjectMember($this->project, $this->watcher);
    $this->task = taskIn($this->project);

    // In-app only: no email jobs from the racing processes.
    DB::table('user_preferences')->insert(['user_id' => $this->watcher->id, 'email_notifications' => false, 'in_app_notifications' => true, 'theme' => 'system', 'settings' => '{}']);
});

afterEach(function () {
    $userIds = TeamMembership::query()->where('team_id', $this->team->id)->pluck('user_id')->push($this->member->id, $this->watcher->id, $this->owner->id)->unique()->all();

    DB::transaction(function (): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('teams')->where('id', $this->team->id)->delete();
    });
    DB::table('user_preferences')->whereIn('user_id', $userIds)->delete();
    User::query()->whereKey($userIds)->delete();
});

/**
 * Runs each PHP snippet in its own `php artisan tinker` process, all released together once every
 * process waits on the barrier, and returns their trimmed outputs, sorted.
 *
 * @param  list<string>  $snippets
 * @return list<string>
 */
function notifyRaceInProcesses(array $snippets): array
{
    $connection = config('database.connections.pgsql');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];

    $commands = array_map(fn (string $snippet): string => 'php artisan tinker --no-interaction --execute='.escapeshellarg(sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { %s } catch (Throwable $e) { echo class_basename($e); }',
        NOTIFY_RACE_BARRIER_KEY,
        $snippet,
    )), $snippets);

    DB::select('select pg_advisory_lock(?)', [NOTIFY_RACE_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', NOTIFY_RACE_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The racing processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [NOTIFY_RACE_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

function deliverEditSnippet(Task $task, Project $project, User $actor, string $field): string
{
    return sprintf(
        'app(App\Domain\Notifications\Notifier::class)->taskChanged(new App\Domain\Tasks\Events\TaskChanged("%s", "%s", "%s", "%s", ["%s"], null)); echo "delivered";',
        $task->id,
        $project->id,
        $project->team_id,
        $actor->id,
        $field,
    );
}

it('collapses simultaneous deliveries into one unread notification counting every edit', function () {
    DB::table('task_watchers')->insert(['task_id' => $this->task->id, 'user_id' => $this->watcher->id, 'team_id' => $this->team->id, 'project_id' => $this->project->id, 'created_at' => now()]);

    $outcomes = notifyRaceInProcesses([
        deliverEditSnippet($this->task, $this->project, $this->member, 'title'),
        deliverEditSnippet($this->task, $this->project, $this->member, 'priority'),
        deliverEditSnippet($this->task, $this->project, $this->owner, 'status_id'),
        deliverEditSnippet($this->task, $this->project, $this->owner, 'title'),
    ]);

    $rows = InboxNotification::query()->where('recipient_user_id', $this->watcher->id)->get();

    expect($outcomes)->toBe(['delivered', 'delivered', 'delivered', 'delivered'])
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->event_count)->toBe(4)
        ->and($rows->first()->fields())->toBe(['priority', 'status_id', 'title']);
});

it('never notifies a member whose watch raced their removal from the private project', function () {
    $outcomes = notifyRaceInProcesses([
        sprintf(
            'app(App\Domain\Notifications\Actions\WatchTask::class)->handle(App\Models\Task::with("project")->findOrFail("%s"), App\Models\User::findOrFail("%s")); echo "watched";',
            $this->task->id,
            $this->watcher->id,
        ),
        sprintf(
            'app(App\Domain\Projects\Actions\RemoveProjectMember::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), "%s"); echo "removed";',
            $this->project->id,
            $this->owner->id,
            $this->watcher->id,
        ),
    ]);

    // Either order is fine; a lost race is the 404 the hidden project answers, never a database error.
    expect($outcomes)->toBeIn([['removed', 'watched'], ['ModelNotFoundException', 'removed']]);

    app(UpdateTask::class)->handle(Task::with('project')->findOrFail($this->task->id), $this->member, 1, ['title' => 'After the race']);

    expect(InboxNotification::query()->where('recipient_user_id', $this->watcher->id)->count())->toBe(0)
        ->and(TaskWatchers::watching($this->task, $this->watcher))->toBe($outcomes === ['removed', 'watched']);
});

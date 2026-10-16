<?php

use App\Domain\Projects\TaskNumbers;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// CORE-004 under real concurrency (ADR-021 §5, §7). PHP processes race on committed data, released
// together from an advisory-lock barrier, like OwnershipTransferConcurrencyTest (DB-T06). So this
// file does not use RefreshDatabase; it cleans up after itself.
//
// - DATABASE.md §16 DB-T05: simultaneous task-number allocations get distinct, consecutive numbers.
// - PROJ-01: a key change racing the first allocation either lands before it or is refused (409);
//   a task number is never issued under a key that later changes.
// - Status ordering: a status added while the workflow is reordered never leaves a gap or a
//   duplicate position.

const WORKFLOW_RACE_BARRIER_KEY = 640_004;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = Project::factory()->for($this->team)->withDefaultStatuses()->create(['project_key' => 'RACE']);
});

afterEach(function () {
    // Statuses go with their project (ON DELETE CASCADE), memberships with the team.
    DB::table('projects')->where('team_id', $this->team->id)->delete();
    Team::withTrashed()->whereKey($this->team->id)->forceDelete();
    User::query()->whereKey($this->owner->id)->delete();
});

/**
 * Runs each PHP snippet in its own `php artisan tinker` process, all released together once every
 * process waits on the barrier, and returns their trimmed outputs, sorted.
 *
 * @param  list<string>  $snippets
 * @return list<string>
 */
function wfRaceInProcesses(array $snippets): array
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
    ];

    $commands = array_map(fn (string $snippet): string => 'php artisan tinker --no-interaction --execute='.escapeshellarg(sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { %s } catch (Throwable $e) { echo class_basename($e); }',
        WORKFLOW_RACE_BARRIER_KEY,
        $snippet,
    )), $snippets);

    DB::select('select pg_advisory_lock(?)', [WORKFLOW_RACE_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', WORKFLOW_RACE_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The racing processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [WORKFLOW_RACE_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

/** A snippet that allocates one task number the way CORE-005's task creation will, and prints it. */
function wfAllocateSnippet(Project $project, User $actor): string
{
    return sprintf(
        'echo DB::transaction(fn () => App\Domain\Projects\TaskNumbers::allocate(App\Domain\Projects\ProjectWriteLock::project(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), "contribute")));',
        $project->id,
        $actor->id,
    );
}

it('gives simultaneous task creates distinct, consecutive numbers (DB-T05)', function () {
    $allocate = wfAllocateSnippet($this->project, $this->owner);

    expect(wfRaceInProcesses([$allocate, $allocate, $allocate]))->toBe(['1', '2', '3'])
        ->and($this->project->refresh()->next_task_number)->toBe(4);
});

it('never issues a task number under a key that changes afterwards (PROJ-01)', function () {
    $outcomes = wfRaceInProcesses([
        wfAllocateSnippet($this->project, $this->owner),
        sprintf(
            'app(App\Domain\Projects\Actions\UpdateProject::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), 1, ["key" => "MOVED"]); echo "updated";',
            $this->project->id,
            $this->owner->id,
        ),
    ]);

    // Key change first (then the task is MOVED-1), or the allocation first (then the change is a 409).
    expect([['1', 'updated'], ['1', 'DomainConflictException']])->toContain($outcomes);

    $project = $this->project->refresh();
    expect($project->next_task_number)->toBe(2)
        ->and($project->project_key)->toBe(in_array('updated', $outcomes, true) ? 'MOVED' : 'RACE');
});

it('keeps positions dense when a status is added while the workflow is reordered', function () {
    $reversed = $this->project->statuses()->orderByDesc('position')->pluck('id')->map(fn ($id) => '"'.$id.'"')->implode(', ');

    $outcomes = wfRaceInProcesses([
        sprintf(
            'app(App\Domain\Workflows\Actions\CreateStatus::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), "Raced", App\Domain\Workflows\StatusCategory::Todo); echo "created";',
            $this->project->id,
            $this->owner->id,
        ),
        sprintf(
            'app(App\Domain\Workflows\Actions\ReorderStatuses::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), [%s]); echo "reordered";',
            $this->project->id,
            $this->owner->id,
            $reversed,
        ),
    ]);

    // Reorder first (then the new status is appended), or create first (then the stale list is a 409).
    expect([['created', 'reordered'], ['DomainConflictException', 'created']])->toContain($outcomes);

    $board = $this->project->statuses()->orderBy('position')->get();
    expect($board->pluck('position')->all())->toBe([0, 1, 2, 3])
        ->and($board->last()?->name)->toBe('Raced')
        ->and($board->first()?->name)->toBe(in_array('reordered', $outcomes, true) ? 'Done' : 'To do');
});

it('refuses to allocate a task number outside a transaction', function () {
    expect(fn () => TaskNumbers::allocate($this->project))->toThrow(LogicException::class)
        ->and($this->project->refresh()->next_task_number)->toBe(1)
        ->and(ProjectStatus::query()->where('project_id', $this->project->id)->count())->toBe(3);
});

<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// CORE-005 under real concurrency (ADR-023 §9). PHP processes race on committed data, released together
// from an advisory-lock barrier, like OwnershipTransferConcurrencyTest (DB-T06). So this file does not
// use RefreshDatabase; it cleans up after itself.
//
// - DATABASE.md §16 DB-T05: simultaneous task creates get distinct, consecutive numbers and distinct
//   positions in their column.
// - API_SPEC.md §15 "versions are atomic under concurrency": two edits with the same If-Match version,
//   exactly one wins and the other gets 412.
// - An assignment racing the assignee's removal from the team never leaves a task assigned to a
//   non-member and never fails with a database error: either the assignment lands first and the
//   removal clears it, or the removal lands first and the assignment is 422.
// - DATABASE.md §5 "reject cycles transactionally … with concurrency tests": two re-parentings that
//   are each valid but together form a cycle, or together go deeper than three levels, never both
//   commit; the project write lock serialises them, so the second sees the first and is 422.

const TASK_RACE_BARRIER_KEY = 640_005;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'RACE']);
});

afterEach(function () {
    $userIds = TeamMembership::query()->where('team_id', $this->team->id)->pluck('user_id')->push($this->member->id, $this->owner->id)->unique()->all();

    DB::transaction(function (): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('teams')->where('id', $this->team->id)->delete();
    });
    User::query()->whereKey($userIds)->delete();
});

/**
 * Runs each PHP snippet in its own `php artisan tinker` process, all released together once every
 * process waits on the barrier, and returns their trimmed outputs, sorted.
 *
 * @param  list<string>  $snippets
 * @return list<string>
 */
function taskRaceInProcesses(array $snippets): array
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
        TASK_RACE_BARRIER_KEY,
        $snippet,
    )), $snippets);

    DB::select('select pg_advisory_lock(?)', [TASK_RACE_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', TASK_RACE_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The racing processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [TASK_RACE_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

function createTaskSnippet(Project $project, User $actor): string
{
    return sprintf(
        'echo app(App\Domain\Tasks\Actions\CreateTask::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), ["title" => "Race"])->task_number;',
        $project->id,
        $actor->id,
    );
}

function updateTaskSnippet(Task $task, User $actor, int $version, string $changes): string
{
    return sprintf(
        'app(App\Domain\Tasks\Actions\UpdateTask::class)->handle(App\Models\Task::with("project")->findOrFail("%s"), App\Models\User::findOrFail("%s"), %d, %s); echo "updated";',
        $task->id,
        $actor->id,
        $version,
        $changes,
    );
}

it('gives simultaneous task creates distinct, consecutive numbers and positions (DB-T05)', function () {
    $create = createTaskSnippet($this->project, $this->member);

    expect(taskRaceInProcesses([$create, $create, $create, $create]))->toBe(['1', '2', '3', '4']);

    $tasks = Task::query()->where('project_id', $this->project->id)->get();

    expect($tasks->pluck('position')->map(fn ($p) => (float) $p)->sort()->values()->all())->toBe([0.0, 1.0, 2.0, 3.0])
        ->and($this->project->refresh()->next_task_number)->toBe(5);
});

it('lets exactly one of two edits with the same version win (TASK-03)', function () {
    $task = taskIn($this->project);

    $outcomes = taskRaceInProcesses([
        updateTaskSnippet($task, $this->member, 1, '["title" => "From A"]'),
        updateTaskSnippet($task, $this->owner, 1, '["title" => "From B"]'),
    ]);

    expect($outcomes)->toBe(['StaleVersionException', 'updated']);

    expect($task->refresh())->version->toBe(2)->title->toBeIn(['From A', 'From B']);
});

it('never leaves a task assigned to someone who left the team, whichever commits first', function () {
    $task = taskIn($this->project);

    $outcomes = taskRaceInProcesses([
        updateTaskSnippet($task, $this->owner, 1, sprintf('["assignee_user_id" => "%s"]', $this->member->id)),
        sprintf(
            'app(App\Domain\Teams\Actions\RemoveMember::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s"), App\Models\TeamMembership::where("team_id", "%s")->where("user_id", "%s")->firstOrFail()); echo "removed";',
            $this->team->id,
            $this->owner->id,
            $this->team->id,
            $this->member->id,
        ),
    ]);

    // Assignment first, then the removal clears it; or the removal first, and the assignment is a 422.
    expect([['removed', 'updated'], ['ValidationException', 'removed']])->toContain($outcomes);

    expect($task->refresh()->assignee_user_id)->toBeNull()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('never commits two re-parentings that together form a cycle (A under B racing B under A)', function () {
    $a = taskIn($this->project);
    $b = taskIn($this->project);

    $outcomes = taskRaceInProcesses([
        updateTaskSnippet($a, $this->owner, 1, sprintf('["parent_task_id" => "%s"]', $b->id)),
        updateTaskSnippet($b, $this->member, 1, sprintf('["parent_task_id" => "%s"]', $a->id)),
    ]);

    expect($outcomes)->toBe(['ValidationException', 'updated']);

    $a->refresh();
    $b->refresh();
    expect($a->parent_task_id === null || $b->parent_task_id === null)->toBeTrue();
});

it('never commits two re-parentings that together go deeper than three levels', function () {
    // r → c1, x → y, and w. Alone, x under c1 reaches depth 3 (y), and r under w reaches depth 2 (c1).
    // Together they would put y at depth 4.
    $r = taskIn($this->project);
    $c1 = taskIn($this->project, ['parent_task_id' => $r->id]);
    $x = taskIn($this->project);
    taskIn($this->project, ['parent_task_id' => $x->id]);
    $w = taskIn($this->project);

    $outcomes = taskRaceInProcesses([
        updateTaskSnippet($x, $this->owner, 1, sprintf('["parent_task_id" => "%s"]', $c1->id)),
        updateTaskSnippet($r, $this->member, 1, sprintf('["parent_task_id" => "%s"]', $w->id)),
    ]);

    expect($outcomes)->toBe(['ValidationException', 'updated']);

    $deepest = DB::selectOne(
        'WITH RECURSIVE down (id, depth) AS (SELECT id, 0 FROM tasks WHERE parent_task_id IS NULL AND project_id = ? '
        .'UNION ALL SELECT t.id, down.depth + 1 FROM tasks AS t JOIN down ON t.parent_task_id = down.id) SELECT max(depth) AS depth FROM down',
        [$this->project->id],
    );
    expect((int) $deepest->depth)->toBeLessThanOrEqual(3);
});

function moveTaskSnippet(Task $task, User $actor, int $version, string $statusId, ?string $afterId): string
{
    return sprintf(
        'app(App\Domain\Tasks\Actions\MoveTask::class)->handle(App\Models\Task::with("project")->findOrFail("%s"), App\Models\User::findOrFail("%s"), %d, "%s", null, %s); echo "moved";',
        $task->id,
        $actor->id,
        $version,
        $statusId,
        $afterId === null ? 'null' : '"'.$afterId.'"',
    );
}

it('serialises simultaneous moves into the same slot: every task gets its own rank (CORE-007)', function () {
    $todo = taskStatusId($this->project, 'todo');
    $doing = taskStatusId($this->project, 'in_progress');
    $anchor = taskIn($this->project, ['status_id' => $doing, 'position' => 0]);
    taskIn($this->project, ['status_id' => $doing, 'position' => 1]);
    $moving = collect(range(1, 4))->map(fn (int $n) => taskIn($this->project, ['status_id' => $todo, 'position' => $n]));

    $outputs = taskRaceInProcesses($moving->map(fn (Task $task) => moveTaskSnippet($task, $this->member, 1, $doing, $anchor->id))->all());

    expect($outputs)->toBe(['moved', 'moved', 'moved', 'moved']);

    $positions = Task::query()->where('project_id', $this->project->id)->where('status_id', $doing)->pluck('position')->map(fn ($p) => (string) (float) $p);
    expect($positions->unique()->count())->toBe(6)
        ->and((float) Task::query()->whereKey($anchor->id)->value('position'))->toBe(0.0);
});

it('lets one of a move and an edit made from the same version win; the other is stale (CORE-007)', function () {
    $task = taskIn($this->project);
    $done = taskStatusId($this->project, 'done');

    $outputs = taskRaceInProcesses([
        moveTaskSnippet($task, $this->member, 1, $done, null),
        updateTaskSnippet($task, $this->member, 1, '["title" => "Edited"]'),
    ]);

    expect(count(array_intersect($outputs, ['moved', 'updated'])))->toBe(1)
        ->and($outputs)->toContain('StaleVersionException')
        ->and($task->fresh()->version)->toBe(2);
});

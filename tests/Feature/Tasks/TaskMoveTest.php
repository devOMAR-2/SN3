<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-007: moving tasks across and within board columns (API_SPEC.md §6.1 `POST /tasks/{id}/move`,
// DATABASE.md §11 "Move task"; ADR-028 §2). TEST-FR-TASK-004 (move), TEST-FR-VIEW-001 (board order);
// acceptance: "reorder and status moves persist".

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB']);
    $this->todo = taskStatusId($this->project, 'todo');
    $this->doing = taskStatusId($this->project, 'in_progress');
    $this->done = taskStatusId($this->project, 'done');
});

/** Tasks created through the API, in order, in the default column. @return list<Task> */
function boardTasks(Project $project, int $count): array
{
    $tasks = [];

    foreach (range(1, $count) as $n) {
        $id = test()->actingAs($project->team->owner)
            ->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => "T{$n}"], taskIdempotencyHeader())
            ->assertCreated()
            ->json('data.id');
        $tasks[] = Task::query()->findOrFail($id);
    }

    return $tasks;
}

/** Task titles of a column in board order, as the API lists them. @return list<string> */
function column(Project $project, string $statusId): array
{
    return test()->actingAs($project->team->owner)
        ->getJson("/api/v1/projects/{$project->id}/tasks?sort=position&limit=100&status_id[]={$statusId}")
        ->assertOk()
        ->json('data.*.title');
}

function move(Task $task, array $body, ?int $version = null)
{
    return test()->postJson("/api/v1/tasks/{$task->id}/move", $body, taskIfMatch($version ?? $task));
}

it('moves a task within its column, before or after another task', function () {
    [$a, $b, $c] = boardTasks($this->project, 3);
    $this->actingAs($this->member);

    move($c, ['status_id' => $this->todo, 'before_task_id' => $a->id])
        ->assertOk()
        ->assertJsonPath('data.status_id', $this->todo)
        ->assertJsonPath('data.version', 2)
        ->assertHeader('ETag', '"2"');
    expect(column($this->project, $this->todo))->toBe(['T3', 'T1', 'T2']);

    $this->actingAs($this->member);
    move($a, ['status_id' => $this->todo, 'after_task_id' => $b->id])->assertOk();
    expect(column($this->project, $this->todo))->toBe(['T3', 'T2', 'T1']);

    $this->actingAs($this->member);
    move($c, ['status_id' => $this->todo])->assertOk();
    expect(column($this->project, $this->todo))->toBe(['T2', 'T1', 'T3']);
});

it('moves a task to another column, at its end or next to an anchor, and the move persists', function () {
    [$a, $b, $c] = boardTasks($this->project, 3);
    $this->actingAs($this->member);

    move($a, ['status_id' => $this->doing])->assertOk()->assertJsonPath('data.status_id', $this->doing);
    move($b, ['status_id' => $this->doing, 'before_task_id' => $a->id])->assertOk();
    move($c, ['status_id' => $this->doing, 'after_task_id' => $b->id, 'before_task_id' => null])->assertOk();

    expect(column($this->project, $this->doing))->toBe(['T2', 'T3', 'T1'])
        ->and(column($this->project, $this->todo))->toBe([]);

    // The board order is also the list's `position` sort across columns.
    $this->actingAs($this->owner)->getJson("/api/v1/tasks/{$a->id}")->assertJsonPath('data.status_id', $this->doing);
});

it('keeps the version when the task is already where it is moved', function () {
    [$a, $b] = boardTasks($this->project, 2);
    $this->actingAs($this->member);

    move($a, ['status_id' => $this->todo, 'before_task_id' => $b->id])->assertOk()->assertJsonPath('data.version', 1);
    move($b, ['status_id' => $this->todo, 'after_task_id' => $a->id])->assertOk()->assertJsonPath('data.version', 1);
    move($b, ['status_id' => $this->todo])->assertOk()->assertJsonPath('data.version', 1);

    expect(column($this->project, $this->todo))->toBe(['T1', 'T2']);
});

it('renumbers a column whose gap has run out, without changing other tasks\' versions', function () {
    [$a, $b, $c] = boardTasks($this->project, 3);
    $this->actingAs($this->member);

    // Repeatedly drop the last task between the first two: the midpoint halves the gap every time.
    $toggle = [$b, $c];
    for ($i = 0; $i < 45; $i++) {
        $moving = $toggle[$i % 2]->fresh();
        $other = $toggle[($i + 1) % 2];
        move($moving, ['status_id' => $this->todo, 'after_task_id' => $a->id])->assertOk();
        expect(column($this->project, $this->todo)[0])->toBe('T1');
        $this->actingAs($this->member);
    }

    $positions = DB::table('tasks')->where('project_id', $this->project->id)->orderBy('position')->pluck('position')->all();
    expect(count(array_unique($positions)))->toBe(3)
        ->and($a->fresh()->version)->toBe(1);
});

it('validates the body: one anchor at most, UUIDs, a status and anchors of this project and column', function (Closure $body, string $field, string $code) {
    [$a, $b] = boardTasks($this->project, 2);
    $other = taskProject($this->team);
    $foreign = taskIn($other);
    $trashed = boardTasks($this->project, 1)[0];
    $trashed->delete();
    $inDone = taskIn($this->project, ['status_id' => $this->done]);

    $this->actingAs($this->member);
    move($a, $body(compact('a', 'b', 'foreign', 'trashed', 'inDone') + ['todo' => $this->todo, 'otherStatus' => taskStatusId($other, 'todo')]))
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect($a->fresh()->version)->toBe(1);
})->with([
    'no status' => [fn (array $x) => [], 'status_id', 'required'],
    'malformed status' => [fn (array $x) => ['status_id' => 'nope'], 'status_id', 'uuid'],
    'status of another project' => [fn (array $x) => ['status_id' => $x['otherStatus']], 'status_id', 'exists'],
    'random status' => [fn (array $x) => ['status_id' => (string) Str::uuid7()], 'status_id', 'exists'],
    'both anchors' => [fn (array $x) => ['status_id' => $x['todo'], 'before_task_id' => $x['b']->id, 'after_task_id' => $x['b']->id], 'after_task_id', 'prohibits'],
    'itself' => [fn (array $x) => ['status_id' => $x['todo'], 'before_task_id' => $x['a']->id], 'before_task_id', 'different'],
    'anchor of another project' => [fn (array $x) => ['status_id' => $x['todo'], 'after_task_id' => $x['foreign']->id], 'after_task_id', 'exists'],
    'random anchor' => [fn (array $x) => ['status_id' => $x['todo'], 'after_task_id' => (string) Str::uuid7()], 'after_task_id', 'exists'],
    'trashed anchor' => [fn (array $x) => ['status_id' => $x['todo'], 'before_task_id' => $x['trashed']->id], 'before_task_id', 'exists'],
    'anchor in another column' => [fn (array $x) => ['status_id' => $x['todo'], 'before_task_id' => $x['inDone']->id], 'before_task_id', 'exists'],
    'malformed anchor' => [fn (array $x) => ['status_id' => $x['todo'], 'before_task_id' => 'x'], 'before_task_id', 'uuid'],
    'unknown field' => [fn (array $x) => ['status_id' => $x['todo'], 'position' => 1], 'position', 'unknown_field'],
]);

it('requires If-Match (428), refuses a stale version (412), and 423 wins over 412', function () {
    [$a] = boardTasks($this->project, 1);
    $this->actingAs($this->member);

    $this->postJson("/api/v1/tasks/{$a->id}/move", ['status_id' => $this->done])->assertStatus(428);
    move($a, ['status_id' => $this->done], 7)->assertStatus(412)->assertJsonPath('error.code', 'version_mismatch');

    $this->project->forceFill(['archived_at' => now()])->save();
    move($a, ['status_id' => $this->done], 7)->assertStatus(423);

    expect($a->fresh()->status_id)->toBe($this->todo);
});

it('answers 403 to a Viewer whatever the body, and 404 for a task of a hidden project', function () {
    [$a] = boardTasks($this->project, 1);
    $viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $private = taskProject($this->team, 'private');
    $hidden = taskIn($private);

    $this->actingAs($viewer);
    move($a, ['status_id' => $this->done])->assertForbidden();
    move($a, ['nonsense' => true])->assertForbidden();

    $this->actingAs($this->member);
    move($hidden, ['status_id' => taskStatusId($private, 'done')])->assertNotFound();
});

it('re-checks the actor on the locked project: hidden is 404, demoted is 409', function () {
    $private = taskProject($this->team, 'private');
    $contributor = taskTeamMember($this->team, TeamRole::Member);
    $membership = taskProjectMember($private, $contributor, ProjectRole::Contributor);
    $task = taskIn($private);
    $done = taskStatusId($private, 'done');

    DB::table('team_memberships')->where('team_id', $this->team->id)->where('user_id', $contributor->id)->update(['role' => 'viewer']);
    expect(fn () => app(MoveTask::class)->handle($task, $contributor, 1, $done, null, null))->toThrow(DomainConflictException::class);

    $membership->delete();
    expect(fn () => app(MoveTask::class)->handle($task, $contributor, 1, $done, null, null))->toThrow(ModelNotFoundException::class);

    expect($task->fresh()->status_id)->not->toBe($done);
});

it('records the move in the activity log, with IDs only, and nothing for a no-op', function () {
    [$a, $b] = boardTasks($this->project, 2);
    $this->actingAs($this->member);

    move($a, ['status_id' => $this->done])->assertOk();
    move($a->fresh(), ['status_id' => $this->done])->assertOk();
    move($b, ['status_id' => $this->todo, 'after_task_id' => null])->assertOk();

    $entries = TaskActivity::query()->where('action', 'task.moved')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->task_id)->toBe($a->id)
        ->and($entries[0]->project_id)->toBe($this->project->id)
        ->and($entries[0]->actor_user_id)->toBe($this->member->id)
        ->and($entries[0]->changes)->toEqual(['from_status_id' => $this->todo, 'status_id' => $this->done]);
});

it('leaves a task that already sits between the requested neighbours where it is', function () {
    [$a, $b, $c] = boardTasks($this->project, 3);
    DB::table('tasks')->where('id', $a->id)->update(['position' => 0]);
    DB::table('tasks')->where('id', $c->id)->update(['position' => 3]);
    DB::table('tasks')->where('id', $b->id)->update(['position' => 10]);
    $this->actingAs($this->member);

    move($c, ['status_id' => $this->todo, 'after_task_id' => $a->id])->assertOk()->assertJsonPath('data.version', 1);
    move($c, ['status_id' => $this->todo, 'before_task_id' => $b->id])->assertOk()->assertJsonPath('data.version', 1);

    expect((float) DB::table('tasks')->where('id', $c->id)->value('position'))->toBe(3.0);
});

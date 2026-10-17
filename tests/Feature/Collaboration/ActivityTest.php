<?php

require_once __DIR__.'/CollaborationHelpers.php';

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-008: the append-only activity and audit log (FR-COL-002, FR-TASK-006, NFR-SEC-005; API_SPEC.md
// §4 project activity, §6.1 task activity, §9 own activity; DATABASE.md §5 task_activities). Entries
// are written in the same transaction as the change, hold IDs and field names only, are readable
// only by those who can see the resource, and cannot be changed or deleted. TEST-FR-COL-002,
// TEST-FR-TASK-006, TEST-NFR-SEC-005; DATABASE.md §16 DB-T01, DB-T02, DB-T08.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team);
});

function activityActions(string $url, $user): array
{
    return collect(test()->actingAs($user)->getJson($url)->assertOk()->json('data'))->pluck('action')->all();
}

it('records the life of a task, newest first, with IDs, enum values, dates and field names only', function () {
    $this->freezeTime();
    $doing = taskStatusId($this->project, 'in_progress');

    $id = $this->actingAs($this->member)->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'Secret launch plan', 'description' => 'Top secret'], taskIdempotencyHeader())
        ->assertCreated()->json('data.id');
    $this->travel(1)->second();
    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$id}", [
        'title' => 'Renamed secret', 'status_id' => $doing, 'priority' => 'high', 'due_date' => '2026-12-01', 'assignee_user_id' => $this->member->id,
    ], taskIfMatch(1))->assertOk();
    $this->travel(1)->second();
    $item = $this->actingAs($this->member)->postJson("/api/v1/tasks/{$id}/checklist", ['text' => 'Private step'])->assertCreated()->json('data.id');
    $this->travel(1)->second();
    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$id}/checklist/{$item}", ['checked' => true])->assertOk();
    $this->travel(1)->second();
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$id}/checklist/{$item}")->assertNoContent();
    $this->travel(1)->second();
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$id}", [], taskIfMatch(2))->assertNoContent();
    $this->travel(1)->second();
    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$id}/restore")->assertOk();

    $response = $this->actingAs($this->viewer)->getJson("/api/v1/tasks/{$id}/activity")->assertOk();

    expect(collect($response->json('data'))->pluck('action')->all())->toBe([
        'task.restored', 'task.trashed', 'task.checklist_item_deleted', 'task.checklist_item_updated',
        'task.checklist_item_created', 'task.updated', 'task.created',
    ]);

    $entries = collect($response->json('data'))->keyBy('action');
    expect(array_keys($entries['task.updated']))->toBe(['id', 'team_id', 'project_id', 'task_id', 'actor_user_id', 'action', 'changes', 'source', 'created_at'])
        ->and($entries['task.updated'])->toMatchArray([
            'team_id' => $this->team->id, 'project_id' => $this->project->id, 'task_id' => $id,
            'actor_user_id' => $this->member->id, 'source' => 'user',
        ])
        ->and($entries['task.updated']['changes']['status_id'])->toBe(['to' => $doing, 'from' => taskDefaultStatusId($this->project)])
        ->and($entries['task.updated']['changes']['priority'])->toEqual(['from' => 'none', 'to' => 'high'])
        ->and($entries['task.updated']['changes']['due_date'])->toEqual(['from' => null, 'to' => '2026-12-01'])
        ->and($entries['task.updated']['changes']['assignee_user_id'])->toEqual(['from' => null, 'to' => $this->member->id])
        ->and($entries['task.updated']['changes']['fields'])->toEqualCanonicalizing(['title', 'status_id', 'priority', 'due_date', 'assignee_user_id'])
        ->and($entries['task.checklist_item_updated']['changes'])->toEqual(['item_id' => $item, 'fields' => ['checked'], 'checked' => true])
        ->and($entries['task.trashed']['changes'])->toEqual([])
        ->and($entries['task.restored']['actor_user_id'])->toBe($this->owner->id);

    // Free text never reaches the log (DATABASE.md §5 "bounded, sanitized allowlist").
    expect(json_encode($response->json()))->not->toContain('secret')->not->toContain('Secret')->not->toContain('Private step');
});

it('writes nothing for a refused or stale change (API_SPEC.md §14.3 "no write or activity event")', function () {
    $task = taskIn($this->project);
    $task->forceFill(['version' => 3])->save();

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'], taskIfMatch(2))->assertStatus(412);
    $this->actingAs($this->viewer)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'], taskIfMatch(3))->assertForbidden();
    // A no-op edit changes nothing and records nothing.
    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'none'], taskIfMatch(3))->assertOk();

    expect(TaskActivity::query()->count())->toBe(0);
});

it('shows a project timeline of project and task entries to its viewers only', function () {
    $private = taskProject($this->team, 'private');
    taskProjectMember($private, $this->member, ProjectRole::Manager);
    $task = taskIn($private);

    $this->actingAs($this->member)->putJson("/api/v1/projects/{$private->id}/members/{$this->viewer->id}", ['role' => 'contributor'])->assertCreated();
    $this->actingAs($this->member)->postJson("/api/v1/tasks/{$task->id}/comments", ['body' => 'Hidden words'])->assertCreated();
    $this->actingAs($this->member)->patchJson("/api/v1/projects/{$private->id}", ['visibility' => 'private', 'name' => 'Still secret'], ['If-Match' => '"1"'])->assertOk();

    expect(activityActions("/api/v1/projects/{$private->id}/activity", $this->viewer))->toBe(['comment.created', 'project.member_added'])
        ->and(activityActions("/api/v1/projects/{$private->id}/activity", $this->owner))->toBe(['comment.created', 'project.member_added']);

    // A Team Admin without a membership, and another team, learn nothing (404, DB-T01, DB-T02).
    $outsider = Team::factory()->create()->owner;
    foreach ([$this->admin, $outsider] as $user) {
        $this->actingAs($user)->getJson("/api/v1/projects/{$private->id}/activity")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($user)->getJson("/api/v1/tasks/{$task->id}/activity")->assertNotFound();
    }

    // Another project's timeline holds nothing of this one.
    expect(activityActions("/api/v1/projects/{$this->project->id}/activity", $this->admin))->toBe([]);
});

it('lists the caller\'s own activity only where they can still see it, and nothing of anyone else (API_SPEC.md §9)', function () {
    $private = taskProject($this->team, 'private');
    taskProjectMember($private, $this->member, ProjectRole::Contributor);
    $privateTask = taskIn($private);
    $openTask = taskIn($this->project);

    $this->actingAs($this->member)->postJson("/api/v1/tasks/{$privateTask->id}/comments", ['body' => 'In private'])->assertCreated();
    $this->actingAs($this->member)->postJson("/api/v1/tasks/{$openTask->id}/comments", ['body' => 'In the open'])->assertCreated();
    $this->actingAs($this->admin)->postJson("/api/v1/tasks/{$openTask->id}/comments", ['body' => 'Someone else'])->assertCreated();

    $mine = fn () => collect($this->actingAs($this->member)->getJson('/api/v1/me/activity')->assertOk()->json('data'));

    expect($mine()->pluck('project_id')->all())->toBe([$this->project->id, $private->id])
        ->and($mine()->pluck('actor_user_id')->unique()->all())->toBe([$this->member->id]);

    // Removed from the private project: its entries disappear from their own feed too (DB-T08).
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$private->id}/members/{$this->member->id}")->assertNoContent();
    expect($mine()->pluck('project_id')->all())->toBe([$this->project->id]);

    // Removed from the team: nothing of it is left.
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->member->id}")->assertNoContent();
    expect($mine()->all())->toBe([]);
});

it('audits team role changes, removals and ownership transfers as team entries seen only by their actor (NFR-SEC-005)', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->member->id}", ['role' => 'admin'])->assertOk();
    $this->actingAs($this->viewer)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->viewer->id}")->assertNoContent();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->admin->id])->assertOk();

    $team = TaskActivity::query()->whereNull('project_id')->where('team_id', $this->team->id)->orderBy('id')->get();
    expect($team->map(fn (TaskActivity $entry) => [$entry->action, $entry->actor_user_id, $entry->changes])->all())->toEqual([
        ['team.member_role_changed', $this->owner->id, ['user_id' => $this->member->id, 'from' => 'member', 'to' => 'admin']],
        ['team.member_removed', $this->viewer->id, ['user_id' => $this->viewer->id]],
        ['team.ownership_transferred', $this->owner->id, ['from_user_id' => $this->owner->id, 'to_user_id' => $this->admin->id]],
    ]);

    expect(activityActions('/api/v1/me/activity', $this->owner))->toBe(['team.ownership_transferred', 'team.member_role_changed'])
        ->and(activityActions('/api/v1/me/activity', $this->member))->toBe([])
        // The viewer left the team, so their own entry about it is gone from their feed.
        ->and(activityActions('/api/v1/me/activity', $this->viewer))->toBe([])
        // Team entries never appear in a project timeline.
        ->and(activityActions("/api/v1/projects/{$this->project->id}/activity", $this->admin))->toBe([]);
});

it('records project creation, visibility and membership changes in the project timeline', function () {
    $id = $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Ops', 'key' => 'OPS'])->assertCreated()->json('data.id');
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$id}/members/{$this->member->id}", ['role' => 'contributor'])->assertCreated();
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$id}/members/{$this->member->id}", ['role' => 'manager'])->assertOk();
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$id}", ['visibility' => 'private'], ['If-Match' => '"1"'])->assertOk();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$id}/members/{$this->member->id}")->assertNoContent();

    expect(activityActions("/api/v1/projects/{$id}/activity", $this->owner))->toBe([
        'project.member_removed', 'project.visibility_changed', 'project.member_role_changed', 'project.member_added', 'project.created',
    ]);
});

it('is append-only: the database refuses updates, deletes and truncation, but lets the purge cascade', function () {
    $task = taskIn($this->project);
    $entry = Activity::task($task, $this->member, 'task.trashed');

    foreach ([
        fn () => DB::table('task_activities')->where('id', $entry->id)->update(['action' => 'task.restored']),
        fn () => DB::table('task_activities')->where('id', $entry->id)->delete(),
        fn () => DB::statement('TRUNCATE task_activities'),
    ] as $tamper) {
        expect(fn () => DB::transaction($tamper))->toThrow(QueryException::class, 'task_activities is append-only');
    }

    expect(TaskActivity::query()->whereKey($entry->id)->value('action'))->toBe('task.trashed');

    // Foreign-key actions pass: a hard-deleted task (the purge) takes its entries with it.
    DB::transaction(function () use ($task): void {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('tasks')->where('id', $task->id)->delete();
    });
    expect(TaskActivity::query()->whereKey($entry->id)->exists())->toBeFalse();
});

it('refuses entries outside their project or team, and unbounded or malformed changes, in the database', function () {
    $task = taskIn($this->project);
    $other = taskProject($this->team);
    $row = fn (array $override) => DB::transaction(fn () => DB::table('task_activities')->insert([
        'id' => (string) Str::uuid7(), 'team_id' => $this->team->id, 'project_id' => $this->project->id,
        'task_id' => $task->id, 'action' => 'task.updated', 'changes' => '{}', 'source' => 'user', 'created_at' => now(), ...$override,
    ]));

    expect(fn () => $row(['project_id' => $other->id]))->toThrow(QueryException::class, 'task_activities_task_id_project_id_foreign')
        ->and(fn () => $row(['team_id' => Team::factory()->create()->id]))->toThrow(QueryException::class, 'task_activities_project_id_team_id_foreign')
        ->and(fn () => $row(['project_id' => null]))->toThrow(QueryException::class, 'task_activities_task_needs_project_check')
        ->and(fn () => $row(['changes' => '[]']))->toThrow(QueryException::class, 'task_activities_changes_check')
        ->and(fn () => $row(['changes' => json_encode(['x' => str_repeat('a', 5000)])]))->toThrow(QueryException::class, 'task_activities_changes_check')
        ->and(fn () => $row(['action' => 'DROP TABLE']))->toThrow(QueryException::class, 'task_activities_action_check')
        ->and(fn () => $row(['source' => 'webhook']))->toThrow(QueryException::class, 'task_activities_source_check');
});

it('rejects unknown actions and change keys, and free text, in the application', function () {
    $task = taskIn($this->project);

    expect(fn () => Activity::task($task, null, 'task.exploded'))->toThrow(LogicException::class)
        ->and(fn () => Activity::task($task, null, 'task.updated', ['title' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => Activity::task($task, null, 'task.updated', ['fields' => [str_repeat('x', 65)]]))->toThrow(LogicException::class)
        ->and(fn () => Activity::task($task, null, 'task.updated', ['status_id' => ['nested' => ['deep']]]))->toThrow(LogicException::class)
        ->and(TaskActivity::query()->count())->toBe(0);
});

it('paginates timelines with the standard cursor', function () {
    $task = taskIn($this->project);
    foreach (range(1, 3) as $i) {
        TaskChecklistItem::factory()->for($task)->create();
        Activity::task($task, $this->member, 'task.checklist_item_created', ['item_id' => (string) Str::uuid7()]);
    }

    $first = $this->actingAs($this->member)->getJson("/api/v1/tasks/{$task->id}/activity?limit=2")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);
    $this->actingAs($this->member)->getJson("/api/v1/tasks/{$task->id}/activity?limit=2&cursor=".$first->json('meta.next_cursor'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
    $this->actingAs($this->member)->getJson("/api/v1/projects/{$this->project->id}/activity?cursor=".$first->json('meta.next_cursor'))->assertStatus(400);
    $this->getJson('/api/v1/me/activity')->assertOk();
    auth()->logout();
    $this->getJson('/api/v1/me/activity')->assertUnauthorized();
});

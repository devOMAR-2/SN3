<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-005: task create, read, edit, delete and restore (API_SPEC.md §6.1, §1.4, §14.1, §14.3).
// TEST-FR-TASK-002 (fields), -003 (stable key), -004 (create, edit, assign, move, complete);
// acceptance scenarios AUTH-04, TASK-03, IDEM-01, PROJ-01; DATABASE.md §16 DB-T04, DB-T05.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->project = taskProject($this->team, attributes: ['project_key' => 'WEB']);
});

function postTask(Project $project, User $actor, array $body, ?string $key = null)
{
    return test()->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/tasks", $body, taskIdempotencyHeader($key));
}

// --- Create ------------------------------------------------------------------------------------------

it('creates a task with a generated key in the default status, version 1 (API_SPEC §14.1)', function () {
    $response = postTask($this->project, $this->member, ['title' => '  إصلاح التحقق من تسجيل الدخول  '])
        ->assertCreated()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('data.key', 'WEB-1')
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.title', 'إصلاح التحقق من تسجيل الدخول')
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.status_id', taskDefaultStatusId($this->project))
        ->assertJsonPath('data.priority', 'none')
        ->assertJsonPath('data.assignee_user_id', null)
        ->assertJsonPath('data.due_date', null)
        ->assertJsonPath('data.label_ids', [])
        ->assertJsonPath('data.parent_task_id', null)
        ->assertJsonPath('data.project_id', $this->project->id)
        ->assertJsonPath('data.created_by', $this->member->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('meta.request_id', fn ($id) => is_string($id));

    expect(array_keys($response->json('data')))->toBe([
        'id', 'project_id', 'key', 'number', 'title', 'description', 'status_id', 'priority', 'assignee_user_id',
        'due_date', 'label_ids', 'parent_task_id', 'created_by', 'version', 'created_at', 'updated_at',
    ]);

    postTask($this->project, $this->member, ['title' => 'Second'])->assertCreated()->assertJsonPath('data.key', 'WEB-2');

    expect($this->project->fresh()->next_task_number)->toBe(3);
});

it('creates a task with every optional field', function () {
    $label = Label::factory()->for($this->project)->create();
    $parent = taskIn($this->project);
    $status = taskStatusId($this->project, 'in_progress');

    postTask($this->project, $this->owner, [
        'title' => 'Fix login validation',
        'description' => "## Steps\n\n- validate inputs",
        'status_id' => strtoupper($status),
        'priority' => 'high',
        'assignee_user_id' => $this->member->id,
        'due_date' => '2026-10-20',
        'label_ids' => [strtoupper($label->id)],
        'parent_task_id' => $parent->id,
        'sprint_id' => null,
        'story_points' => null,
    ])
        ->assertCreated()
        ->assertJsonPath('data.description', "## Steps\n\n- validate inputs")
        ->assertJsonPath('data.status_id', $status)
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.assignee_user_id', $this->member->id)
        ->assertJsonPath('data.due_date', '2026-10-20')
        ->assertJsonPath('data.label_ids', [$label->id])
        ->assertJsonPath('data.parent_task_id', $parent->id);
});

it('lets every project writer create tasks and denies Team Viewers whatever the body (AUTH-04)', function () {
    $admin = taskTeamMember($this->team, TeamRole::Admin);

    foreach ([$this->owner, $admin, $this->member] as $writer) {
        postTask($this->project, $writer, ['title' => 'Mine'])->assertCreated();
    }

    foreach ([['title' => 'Nope'], ['title' => '', 'junk' => 1]] as $body) {
        postTask($this->project, $this->viewer, $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }

    expect(Task::query()->count())->toBe(3)
        ->and($this->project->fresh()->next_task_number)->toBe(4);
});

it('validates the create body with stable codes and writes nothing', function (array $body, string $field, string $code) {
    $fields = postTask($this->project, $this->member, [...['title' => 'Valid'], ...$body])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->json('error.fields');

    // Field paths such as `label_ids.0` contain dots, so they are read as keys, not JSON paths.
    expect($fields[$field][0] ?? null)->toBe($code);

    expect(Task::query()->count())->toBe(0)
        ->and($this->project->fresh()->next_task_number)->toBe(1);
})->with([
    'missing title' => [['title' => null], 'title', 'required'],
    'blank title' => [['title' => '   '], 'title', 'required'],
    'title too long' => [['title' => str_repeat('ع', 201)], 'title', 'max'],
    'title not a string' => [['title' => 5], 'title', 'string'],
    'description too long' => [['description' => str_repeat('a', 50_001)], 'description', 'max'],
    'unknown priority' => [['priority' => 'critical'], 'priority', 'in'],
    'null priority' => [['priority' => null], 'priority', 'required'],
    'malformed status' => [['status_id' => 'todo'], 'status_id', 'uuid'],
    'malformed assignee' => [['assignee_user_id' => '42'], 'assignee_user_id', 'uuid'],
    'malformed due date' => [['due_date' => '20/10/2026'], 'due_date', 'date_format'],
    'impossible due date' => [['due_date' => '2026-02-30'], 'due_date', 'date_format'],
    'due date with a time' => [['due_date' => '2026-10-20T10:00:00Z'], 'due_date', 'date_format'],
    'due date before year 1000' => [['due_date' => '0999-12-31'], 'due_date', 'after_or_equal'],
    'labels not a list' => [['label_ids' => ['a' => 'b']], 'label_ids', 'list'],
    'malformed label' => [['label_ids' => ['x']], 'label_ids.0', 'uuid'],
    'duplicate label' => [['label_ids' => ['018f8c70-0000-7000-8000-000000000001', '018F8C70-0000-7000-8000-000000000001']], 'label_ids.0', 'distinct'],
    'too many labels' => [['label_ids' => array_map(fn () => (string) Str::uuid(), range(1, 21))], 'label_ids', 'max'],
    'malformed parent' => [['parent_task_id' => 'x'], 'parent_task_id', 'uuid'],
    'sprint before FLEX-005' => [['sprint_id' => (string) Str::uuid()], 'sprint_id', 'prohibited'],
    'story points before FLEX-005' => [['story_points' => 3], 'story_points', 'prohibited'],
    'a client-chosen key' => [['key' => 'WEB-99'], 'key', 'unknown_field'],
    'unknown field' => [['assignees' => []], 'assignees', 'unknown_field'],
]);

it('answers a status, label or parent of another project exactly like a random ID (DB-T04)', function (string $field) {
    $other = taskProject($this->team);
    $foreign = [
        'status_id' => taskDefaultStatusId($other),
        'label_ids' => [Label::factory()->for($other)->create()->id],
        'parent_task_id' => taskIn($other)->id,
    ][$field];
    $random = $field === 'label_ids' ? [(string) Str::uuid()] : (string) Str::uuid();

    $answers = [];

    foreach ([$foreign, $random] as $value) {
        $answers[] = postTask($this->project, $this->owner, ['title' => 'X', $field => $value])
            ->assertUnprocessable()
            ->json('error');
    }

    expect($answers[0]['fields'])->toBe([$field => ['exists']])
        ->and($answers[1]['fields'])->toBe($answers[0]['fields'])
        ->and(Task::query()->where('project_id', $this->project->id)->count())->toBe(0)
        ->and($this->project->fresh()->next_task_number)->toBe(1);
})->with(['status_id', 'label_ids', 'parent_task_id']);

it('refuses a trashed parent like a missing one', function () {
    $parent = taskIn($this->project);
    $parent->delete();

    postTask($this->project, $this->owner, ['title' => 'X', 'parent_task_id' => $parent->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.parent_task_id', ['exists']);
});

it('replays a create with the same Idempotency-Key and body, and refuses a different body (IDEM-01)', function () {
    $key = (string) Str::uuid();

    $first = postTask($this->project, $this->member, ['title' => 'Once'], $key)->assertCreated()->json();

    postTask($this->project, $this->member, ['title' => 'Once'], $key)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        // The replay carries the original's ETag, the version the next If-Match needs.
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('data.id', $first['data']['id'])
        ->assertJsonPath('data.key', 'WEB-1');

    postTask($this->project, $this->member, ['title' => 'Twice'], $key)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(Task::query()->count())->toBe(1)
        ->and($this->project->fresh()->next_task_number)->toBe(2)
        ->and(DB::table('api_idempotency_records')->value('team_id'))->toBe($this->team->id);
});

it('requires an Idempotency-Key on create', function (?string $key) {
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    $this->actingAs($this->member)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'X'], $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'bad_request');

    expect(Task::query()->count())->toBe(0);
})->with(['missing' => [null], 'not a UUID' => ['abc']]);

it('freezes the project key with the first task and keeps task keys stable (PROJ-01, FR-TASK-003)', function () {
    $task = Task::query()->findOrFail(postTask($this->project, $this->owner, ['title' => 'First'])->json('data.id'));

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'NEW'], ['If-Match' => '"1"'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    // Deleting the only task does not unfreeze the key; its number is never reissued.
    $this->actingAs($this->owner)->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(1))->assertNoContent();

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'NEW'], ['If-Match' => '"1"'])
        ->assertStatus(409);

    postTask($this->project, $this->owner, ['title' => 'Second'])->assertJsonPath('data.key', 'WEB-2');

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/settings")->assertJsonPath('data.key_frozen', true);
    expect($this->project->fresh()->project_key)->toBe('WEB');
});

it('records task creation in the activity log with IDs only (CORE-008)', function () {
    $id = postTask($this->project, $this->member, ['title' => 'Secret plan', 'description' => 'Very private'])->assertCreated()->json('data.id');

    $entries = TaskActivity::query()->where('task_id', $id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->action)->toBe('task.created')
        ->and($entries[0]->actor_user_id)->toBe($this->member->id)
        ->and($entries[0]->changes)->toBe(['priority' => 'none', 'status_id' => taskDefaultStatusId($this->project)])
        ->and(json_encode($entries[0]->changes))->not->toContain('Secret');
});

// --- Read --------------------------------------------------------------------------------------------

it('shows a task to anyone who can see the project, Viewers included', function () {
    $task = taskIn($this->project, ['title' => 'Readable']);

    foreach ([$this->owner, $this->member, $this->viewer] as $reader) {
        $this->actingAs($reader)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.key', 'WEB-1');
    }
});

it('answers 404 for a foreign, random, malformed or trashed task, with no metadata (AUTH-01, DB-T01)', function () {
    $foreignTeam = Team::factory()->create();
    $foreign = taskIn(taskProject($foreignTeam), ['title' => 'Their secret']);
    $trashed = taskIn($this->project);
    $trashed->delete();

    foreach ([$foreign->id, (string) Str::uuid(), 'not-a-uuid', $trashed->id] as $id) {
        $response = $this->actingAs($this->member)->getJson("/api/v1/tasks/{$id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        expect($response->getContent())->not->toContain('Their secret')->not->toContain($foreign->project_id);
    }
});

// --- Update ------------------------------------------------------------------------------------------

it('edits a task against its version and returns the new version', function () {
    $task = taskIn($this->project);
    $label = Label::factory()->for($this->project)->create();
    $done = taskStatusId($this->project, 'done');

    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", [
            'title' => ' Renamed ',
            'description' => 'Body',
            'priority' => 'urgent',
            'status_id' => $done,
            'assignee_user_id' => $this->member->id,
            'due_date' => '2026-12-31',
            'label_ids' => [$label->id],
        ], taskIfMatch(1))
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.description', 'Body')
        ->assertJsonPath('data.priority', 'urgent')
        ->assertJsonPath('data.status_id', $done)
        ->assertJsonPath('data.assignee_user_id', $this->member->id)
        ->assertJsonPath('data.due_date', '2026-12-31')
        ->assertJsonPath('data.label_ids', [$label->id])
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.key', 'WEB-1');

    // null clears the nullable fields; absent fields stay; [] removes every label.
    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", ['description' => null, 'assignee_user_id' => null, 'due_date' => null, 'label_ids' => []], taskIfMatch(2))
        ->assertOk()
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.assignee_user_id', null)
        ->assertJsonPath('data.due_date', null)
        ->assertJsonPath('data.label_ids', [])
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.version', 3);
});

it('keeps the version on a no-op edit, and counts a label-only change as a change', function () {
    $task = taskIn($this->project, ['title' => 'Same', 'due_date' => '2026-10-20']);
    $label = Label::factory()->for($this->project)->create();

    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Same', 'due_date' => '2026-10-20', 'status_id' => $task->status_id, 'label_ids' => []], taskIfMatch(1))
        ->assertOk()
        ->assertJsonPath('data.version', 1);

    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", ['label_ids' => [$label->id]], taskIfMatch(1))
        ->assertOk()
        ->assertJsonPath('data.version', 2);
});

it('requires If-Match: 428 without it, 412 when stale, and no write either way (TASK-03, §14.3)', function () {
    $task = taskIn($this->project, ['title' => 'Original']);
    $task->forceFill(['version' => 3])->save();

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'precondition_required');

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'], taskIfMatch(2))
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');

    expect($task->fresh())->priority->value->toBe('none')->version->toBe(3);
});

it('moves a task to another status of its project at the end of that column', function () {
    $done = taskStatusId($this->project, 'done');
    $first = taskIn($this->project, ['status_id' => $done]);
    $task = taskIn($this->project);

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['status_id' => $done], taskIfMatch(1))->assertOk();

    expect((float) $task->fresh()->position)->toBeGreaterThan((float) $first->fresh()->position);
});

it('refuses a status or label of another project on edit, with no partial write (DB-T04)', function () {
    $other = taskProject($this->team);
    $task = taskIn($this->project, ['title' => 'Untouched']);

    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Changed', 'status_id' => taskDefaultStatusId($other)], taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.status_id', ['exists']);

    $this->actingAs($this->member)
        ->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Changed', 'label_ids' => [Label::factory()->for($other)->create()->id]], taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.label_ids', ['exists']);

    expect($task->fresh())->title->toBe('Untouched')->version->toBe(1)
        ->and(DB::table('task_labels')->count())->toBe(0);
});

it('validates PATCH bodies with stable codes', function (array $body, string $field, string $code) {
    $task = taskIn($this->project);

    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", $body, taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'null title' => [['title' => null], 'title', 'required'],
    'null status' => [['status_id' => null], 'status_id', 'required'],
    'null labels' => [['label_ids' => null], 'label_ids', 'array'],
    'unknown field' => [['number' => 7], 'number', 'unknown_field'],
    'task key' => [['key' => 'WEB-9'], 'key', 'unknown_field'],
]);

it('denies edits to Viewers whatever the body, before If-Match and validation (AUTH-04)', function (array $body, array $headers) {
    $task = taskIn($this->project, ['title' => 'Kept']);

    $this->actingAs($this->viewer)->patchJson("/api/v1/tasks/{$task->id}", $body, $headers)->assertForbidden();
    $this->actingAs($this->viewer)->deleteJson("/api/v1/tasks/{$task->id}", [], $headers)->assertForbidden();

    expect($task->fresh())->title->toBe('Kept')->deleted_at->toBeNull();
})->with([
    'valid body' => [['title' => 'Mine'], ['If-Match' => '"1"']],
    'invalid body, no If-Match' => [['title' => null, 'junk' => 1], []],
]);

it('answers 423 on an archived project or team before the version check, for every task write', function (string $archived) {
    $task = taskIn($this->project);
    ($archived === 'team' ? $this->team : $this->project)->forceFill(['archived_at' => now()])->save();

    postTask($this->project, $this->member, ['title' => 'X'])->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->member)->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Y'], taskIfMatch(99))->assertStatus(423);
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(99))->assertStatus(423);
    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$task->id}/checklist", ['text' => 'Z'])->assertStatus(423);

    // Reads keep working.
    $this->actingAs($this->viewer)->getJson("/api/v1/tasks/{$task->id}")->assertOk();
    $this->actingAs($this->viewer)->getJson("/api/v1/projects/{$this->project->id}/tasks")->assertOk()->assertJsonCount(1, 'data');

    expect(Task::query()->count())->toBe(1)->and($task->fresh()->version)->toBe(1);
})->with(['project', 'team']);

it('re-checks the actor under the lock: 409 when they lost the right meanwhile, 404 when they lost the project', function () {
    $task = taskIn($this->project);
    $private = taskProject($this->team, 'private');
    $hidden = taskIn($private);
    $membership = taskProjectMember($private, $this->member);
    $hiddenTask = $hidden->fresh();
    $hiddenTask->setRelation('project', app(AccessibleProjects::class)->findOrFail($this->member, $private->id));
    $visibleTask = $task->fresh();
    $visibleTask->setRelation('project', app(AccessibleProjects::class)->findOrFail($this->member, $this->project->id));

    // The request was authorised; then the member became a Viewer, or lost the private project.
    DB::table('team_memberships')->where('team_id', $this->team->id)->where('user_id', $this->member->id)->update(['role' => 'viewer']);

    expect(fn () => app(UpdateTask::class)->handle($visibleTask, $this->member, 1, ['title' => 'Late']))
        ->toThrow(DomainConflictException::class);

    DB::table('team_memberships')->where('team_id', $this->team->id)->where('user_id', $this->member->id)->update(['role' => 'member']);
    $membership->delete();

    expect(fn () => app(UpdateTask::class)->handle($hiddenTask, $this->member, 1, ['title' => 'Late']))
        ->toThrow(ModelNotFoundException::class);

    expect($task->fresh()->title)->not->toBe('Late')->and($hidden->fresh()->title)->not->toBe('Late');
});

// --- Delete and restore ------------------------------------------------------------------------------

it('lets any project writer delete any task, against its version (API_SPEC §6.1 "Project writer")', function () {
    $task = taskIn($this->project, ['created_by' => $this->owner->id]);

    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}")->assertStatus(428);
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(2))->assertStatus(412);
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}", ['force' => true], taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.force', ['unknown_field']);

    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(1))->assertNoContent();

    $this->actingAs($this->member)->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
    $this->actingAs($this->member)->getJson("/api/v1/projects/{$this->project->id}/tasks")->assertJsonCount(0, 'data');
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(2))->assertNotFound();

    expect(Task::withTrashed()->findOrFail($task->id))->deleted_at->not->toBeNull()->version->toBe(2);
});

it('refuses to delete a task that still has live subtasks (409)', function () {
    $parent = taskIn($this->project);
    $child = Task::factory()->childOf($parent)->create();

    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$parent->id}", [], taskIfMatch(1))->assertStatus(409);

    $child->delete();

    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$parent->id}", [], taskIfMatch(1))->assertNoContent();
});

it('restores a trashed task for the Owner and project managers only, others get 404', function () {
    $task = taskIn($this->project, ['title' => 'Back']);
    $task->forceFill(['version' => 2])->save();
    $task->delete();

    $manager = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->project, $manager, ProjectRole::Manager);
    $admin = taskTeamMember($this->team, TeamRole::Admin);
    $viewerManager = taskTeamMember($this->team, TeamRole::Viewer);
    taskProjectMember($this->project, $viewerManager, ProjectRole::Manager);

    // The trash is invisible to anyone who cannot restore from it (ADR-022 §4): members, Admins
    // without the manager role and Viewers get the 404 of a missing task.
    foreach ([$this->member, $admin, $this->viewer, $viewerManager] as $other) {
        $this->actingAs($other)->postJson("/api/v1/tasks/{$task->id}/restore")->assertNotFound();
    }

    $this->actingAs($manager)->postJson("/api/v1/tasks/{$task->id}/restore", ['x' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.x', ['unknown_field']);

    $this->actingAs($manager)->postJson("/api/v1/tasks/{$task->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.title', 'Back')
        ->assertJsonPath('data.version', 3);

    // A live task is not in the trash: 404 for the restore route.
    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$task->id}/restore")->assertNotFound();
    $this->actingAs($this->member)->getJson("/api/v1/tasks/{$task->id}")->assertOk();
});

it('refuses to restore a subtask whose parent is still in the trash (409)', function () {
    $parent = taskIn($this->project);
    $child = Task::factory()->childOf($parent)->create();
    $child->delete();
    $parent->delete();

    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$child->id}/restore")->assertStatus(409);
    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$parent->id}/restore")->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$child->id}/restore")->assertOk();
});

it('answers 423 to a restore in an archived project', function () {
    $task = taskIn($this->project);
    $task->delete();
    $this->project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$task->id}/restore")->assertStatus(423);
});

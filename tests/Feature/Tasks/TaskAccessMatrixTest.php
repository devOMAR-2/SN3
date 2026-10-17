<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\TaskAssignments;
use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// CORE-005: who may read, write, restore and be assigned tasks, for every team role × visibility ×
// explicit project role (API_SPEC.md §6.1, §12, §17; DATABASE.md §19; DECIDED-003, DECIDED-006), and
// non-disclosure of tasks in hidden projects on every task endpoint and in "my tasks".
// TEST-FR-TASK-004, TEST-FR-TASK-008; acceptance scenarios AUTH-01, AUTH-02, AUTH-04;
// DATABASE.md §16 DB-T01, DB-T02, DB-T03.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
});

/**
 * [team role, visibility, project role, can read, can write, can restore, can manage labels, eligible assignee]
 */
dataset('task matrix', [
    'Owner, team, none' => ['owner', 'team', null, true, true, true, true, true],
    'Owner, private, none' => ['owner', 'private', null, true, true, true, true, true],
    'Owner, private, contributor' => ['owner', 'private', 'contributor', true, true, true, true, true],
    'Admin, team, none' => ['admin', 'team', null, true, true, false, false, true],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true, true, true, true],
    'Admin, private, none' => ['admin', 'private', null, false, false, false, false, false],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, true, false, false, true],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true, true, true, true],
    'Member, team, none' => ['member', 'team', null, true, true, false, false, true],
    'Member, team, contributor' => ['member', 'team', 'contributor', true, true, false, false, true],
    'Member, team, manager' => ['member', 'team', 'manager', true, true, true, true, true],
    'Member, private, none' => ['member', 'private', null, false, false, false, false, false],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, true, false, false, true],
    'Member, private, manager' => ['member', 'private', 'manager', true, true, true, true, true],
    'Viewer, team, none' => ['viewer', 'team', null, true, false, false, false, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false, false, false, false],
    'Viewer, private, none' => ['viewer', 'private', null, false, false, false, false, false],
    'Viewer, private, contributor' => ['viewer', 'private', 'contributor', true, false, false, false, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false, false, false, false],
]);

function taskMatrixActor(Team $team, Project $project, string $teamRole, ?string $projectRole): User
{
    $user = $teamRole === 'owner' ? $team->owner : taskTeamMember($team, TeamRole::from($teamRole));

    if ($projectRole !== null) {
        taskProjectMember($project, $user, ProjectRole::from($projectRole));
    }

    return $user;
}

it('enforces the task permission matrix on every endpoint', function (string $teamRole, string $visibility, ?string $projectRole, bool $read, bool $write, bool $restore, bool $labels, bool $eligible) {
    $project = taskProject($this->team, $visibility);
    $user = taskMatrixActor($this->team, $project, $teamRole, $projectRole);
    $task = taskIn($project);
    $item = TaskChecklistItem::factory()->for($task)->create();
    $label = Label::factory()->for($project)->create();
    $trashed = taskIn($project);
    $trashed->delete();

    $hidden = fn ($response) => $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->actingAs($user);

    // Reads.
    $reads = [
        $this->getJson("/api/v1/tasks/{$task->id}"),
        $this->getJson("/api/v1/projects/{$project->id}/tasks"),
        $this->getJson("/api/v1/tasks/{$task->id}/checklist"),
        $this->getJson("/api/v1/projects/{$project->id}/labels"),
    ];

    foreach ($reads as $response) {
        $read ? $response->assertOk() : $hidden($response);
    }

    // Writes: a hidden project is 404, a visible one without the right 403, whatever the body.
    $writes = [
        fn () => $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'New'], taskIdempotencyHeader()),
        fn () => $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Edited'], taskIfMatch(1)),
        fn () => $this->postJson("/api/v1/tasks/{$task->id}/move", ['status_id' => taskStatusId($project, 'done')], taskIfMatch($task)),
        fn () => $this->postJson("/api/v1/tasks/{$task->id}/checklist", ['text' => 'Step']),
        fn () => $this->patchJson("/api/v1/tasks/{$task->id}/checklist/{$item->id}", ['checked' => true]),
        fn () => $this->deleteJson("/api/v1/tasks/{$task->id}/checklist/{$item->id}"),
    ];

    foreach ($writes as $call) {
        $response = $call();

        if (! $read) {
            $hidden($response);
        } elseif (! $write) {
            $response->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        } else {
            $response->assertSuccessful();
        }
    }

    // Label management is Owner / project manager only.
    $labelResponse = $this->postJson("/api/v1/projects/{$project->id}/labels", ['name' => 'Bug']);
    ! $read ? $hidden($labelResponse) : ($labels ? $labelResponse->assertCreated() : $labelResponse->assertForbidden());

    $labelPatch = $this->patchJson("/api/v1/projects/{$project->id}/labels/{$label->id}", ['name' => 'Renamed']);
    ! $read ? $hidden($labelPatch) : ($labels ? $labelPatch->assertOk() : $labelPatch->assertForbidden());

    // Restore: the trash is invisible to anyone who may not restore from it.
    $restoreResponse = $this->postJson("/api/v1/tasks/{$trashed->id}/restore");
    $restore ? $restoreResponse->assertOk() : $hidden($restoreResponse);

    // Delete last, so the task is still there for the checks above.
    $deleteResponse = $this->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch($task));
    ! $read ? $hidden($deleteResponse) : ($write ? $deleteResponse->assertNoContent() : $deleteResponse->assertForbidden());

    // Eligibility as an assignee is exactly the right to write tasks (TaskAssignments vs the policy).
    expect(TaskAssignments::eligible($project->id, $user->id))->toBe($eligible)
        ->and(Gate::forUser($user)->allows('contribute', $project->fresh()))->toBe($eligible);
})->with('task matrix');

it('accepts exactly the eligible users as assignees and answers everyone else like an unknown user', function (string $teamRole, string $visibility, ?string $projectRole, bool $read, bool $write, bool $restore, bool $labels, bool $eligible) {
    $project = taskProject($this->team, $visibility);
    $candidate = taskMatrixActor($this->team, $project, $teamRole, $projectRole);
    $task = taskIn($project);

    $response = $this->actingAs($this->owner)->patchJson("/api/v1/tasks/{$task->id}", ['assignee_user_id' => $candidate->id], taskIfMatch(1));

    if ($eligible) {
        $response->assertOk()->assertJsonPath('data.assignee_user_id', $candidate->id);

        return;
    }

    $unknown = $this->actingAs($this->owner)->patchJson("/api/v1/tasks/{$task->id}", ['assignee_user_id' => (string) Str::uuid()], taskIfMatch(1));

    $response->assertUnprocessable()->assertJsonPath('error.fields.assignee_user_id', ['exists']);
    expect($response->json('error.fields'))->toBe($unknown->json('error.fields'))
        ->and($response->json('error.message'))->toBe($unknown->json('error.message'))
        ->and($task->fresh()->assignee_user_id)->toBeNull();
})->with('task matrix');

it('refuses as assignee a member of another team, a disabled member, and a user unknown to everyone', function () {
    $project = taskProject($this->team);
    $task = taskIn($project);
    $stranger = Team::factory()->create()->owner;
    $disabled = taskTeamMember($this->team);
    $disabled->forceFill(['disabled_at' => now()])->save();

    foreach ([$stranger->id, $disabled->id, (string) Str::uuid()] as $id) {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/tasks/{$task->id}", ['assignee_user_id' => $id], taskIfMatch(1))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.assignee_user_id', ['exists']);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'X', 'assignee_user_id' => $id], taskIdempotencyHeader())
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.assignee_user_id', ['exists']);
    }

    expect(Task::query()->count())->toBe(1);
});

it('hides every task route of a private project from a Team Admin who is not a member (DB-T02)', function () {
    $admin = taskTeamMember($this->team, TeamRole::Admin);
    $project = taskProject($this->team, 'private', ['name' => 'Hiring']);
    $task = taskIn($project, ['title' => 'Confidential offer']);
    $item = TaskChecklistItem::factory()->for($task)->create();
    $label = Label::factory()->for($project)->create();

    $this->actingAs($admin);

    $responses = [
        $this->getJson("/api/v1/tasks/{$task->id}"),
        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'X'], taskIfMatch(1)),
        $this->deleteJson("/api/v1/tasks/{$task->id}", [], taskIfMatch(1)),
        $this->getJson("/api/v1/projects/{$project->id}/tasks"),
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'X'], taskIdempotencyHeader()),
        $this->getJson("/api/v1/tasks/{$task->id}/checklist"),
        $this->patchJson("/api/v1/tasks/{$task->id}/checklist/{$item->id}", ['checked' => true]),
        $this->getJson("/api/v1/projects/{$project->id}/labels"),
        $this->deleteJson("/api/v1/projects/{$project->id}/labels/{$label->id}"),
        $this->getJson('/api/v1/me/tasks'),
    ];

    foreach (array_slice($responses, 0, -1) as $response) {
        $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
        expect($response->getContent())->not->toContain('Confidential')->not->toContain('Hiring')->not->toContain($project->id);
    }

    end($responses)->assertOk()->assertJsonCount(0, 'data');

    // The Owner sees it without a membership row.
    $this->actingAs($this->owner)->getJson("/api/v1/tasks/{$task->id}")->assertOk();
    expect($task->fresh()->title)->toBe('Confidential offer');
});

it('answers a cross-team task ID like a random one on every route (DB-T01, AUTH-01)', function () {
    $foreignTeam = Team::factory()->create();
    $foreignProject = taskProject($foreignTeam);
    $task = taskIn($foreignProject, ['title' => 'Theirs']);
    $item = TaskChecklistItem::factory()->for($task)->create();
    $member = taskTeamMember($this->team);
    $mine = taskIn(taskProject($this->team));

    $this->actingAs($member);

    foreach ([$task->id, (string) Str::uuid()] as $id) {
        $this->getJson("/api/v1/tasks/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/tasks/{$id}", ['title' => 'X'], taskIfMatch(1))->assertNotFound();
        $this->getJson("/api/v1/tasks/{$id}/checklist")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$id}/restore")->assertNotFound();
    }

    // Another task's checklist item through my task: 404 as well.
    $this->patchJson("/api/v1/tasks/{$mine->id}/checklist/{$item->id}", ['checked' => true])->assertNotFound();
    $this->deleteJson("/api/v1/tasks/{$mine->id}/checklist/{$item->id}")->assertNotFound();

    expect($item->fresh()->is_done)->toBeFalse()->and($task->fresh()->title)->toBe('Theirs');
});

it('lists my assigned tasks across teams, only where I can still see the project (GET /me/tasks)', function () {
    $me = taskTeamMember($this->team);
    $otherTeam = Team::factory()->create();
    TeamMembership::factory()->for($otherTeam)->create(['user_id' => $me->id]);

    $open = taskProject($this->team, attributes: ['project_key' => 'OPEN']);
    $private = taskProject($this->team, 'private', ['project_key' => 'PRIV']);
    taskProjectMember($private, $me);
    $elsewhere = taskProject($otherTeam, attributes: ['project_key' => 'ELSE']);
    $trashedProject = taskProject($this->team);

    $a = taskIn($open, ['assignee_user_id' => $me->id, 'due_date' => '2026-11-01']);
    $b = taskIn($private, ['assignee_user_id' => $me->id]);
    $c = taskIn($elsewhere, ['assignee_user_id' => $me->id, 'due_date' => '2026-10-25']);
    taskIn($open);
    $gone = taskIn($open, ['assignee_user_id' => $me->id]);
    $gone->delete();
    taskIn($trashedProject, ['assignee_user_id' => $me->id]);
    $trashedProject->delete();

    $response = $this->actingAs($me)->getJson('/api/v1/me/tasks')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$c->id, $b->id, $a->id])
        ->and(collect($response->json('data'))->pluck('key')->all())->toBe(['ELSE-1', 'PRIV-1', 'OPEN-1']);

    $this->actingAs($me)->getJson('/api/v1/me/tasks?sort=due_date')->assertOk()
        ->assertJsonPath('data.*.id', [$c->id, $a->id, $b->id]);

    $this->actingAs($me)->getJson('/api/v1/me/tasks?sort=position')->assertUnprocessable()
        ->assertJsonPath('error.fields.sort', ['in']);

    // A cursor only fits the sort it was issued for: another sort is a 400, never a 500.
    $cursor = $this->actingAs($me)->getJson('/api/v1/me/tasks?limit=1')->json('meta.next_cursor');
    $this->actingAs($me)->getJson('/api/v1/me/tasks?limit=1&sort=priority&cursor='.urlencode($cursor))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'bad_request');
    // A priority cursor also carries created_at and id: only the bound sort tells them apart.
    $priorityCursor = $this->actingAs($me)->getJson('/api/v1/me/tasks?limit=1&sort=priority')->json('meta.next_cursor');
    $this->actingAs($me)->getJson('/api/v1/me/tasks?limit=1&cursor='.urlencode($priorityCursor))
        ->assertStatus(400);
    $this->actingAs($me)->getJson('/api/v1/me/tasks?limit=1&sort=created_at&cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$b->id]);

    // Visibility is re-evaluated on every request: made private without me, the task disappears.
    $open->forceFill(['visibility' => 'private'])->save();

    $this->actingAs($me)->getJson('/api/v1/me/tasks')->assertJsonPath('data.*.id', [$c->id, $b->id]);
});

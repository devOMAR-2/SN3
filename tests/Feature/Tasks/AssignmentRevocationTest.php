<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// CORE-005: what happens to a task's assignee who loses access or write capability (ADR-023 §5).
// The specs only say who may be assigned (API_SPEC.md §6.1, DATABASE.md §19); the assignment is
// cleared in the same transaction as the change, the task's version moves, and other tasks are left
// alone. TEST-FR-TEAM-006 (removal revokes project access); DATABASE.md §16 DB-T03, DB-T08.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->open = taskProject($this->team);
    $this->private = taskProject($this->team, 'private');
    $this->user = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->private, $this->user);
    $this->other = taskTeamMember($this->team, TeamRole::Member);
    taskProjectMember($this->private, $this->other);

    $this->openTask = taskIn($this->open, ['assignee_user_id' => $this->user->id]);
    $this->privateTask = taskIn($this->private, ['assignee_user_id' => $this->user->id]);
    $this->trashedTask = taskIn($this->private, ['assignee_user_id' => $this->user->id]);
    $this->trashedTask->delete();
    $this->othersTask = taskIn($this->private, ['assignee_user_id' => $this->other->id]);
});

function taskAssigneeOf(Task $task): ?string
{
    return Task::withTrashed()->findOrFail($task->id)->assignee_user_id;
}

function taskVersionOf(Task $task): int
{
    return Task::withTrashed()->findOrFail($task->id)->version;
}

it('unassigns a member removed from the team, everywhere in the team, in the same transaction', function () {
    $membership = TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $this->user->id)->firstOrFail();

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->user->id}")->assertNoContent();

    expect(taskAssigneeOf($this->openTask))->toBeNull()
        ->and(taskAssigneeOf($this->privateTask))->toBeNull()
        ->and(taskAssigneeOf($this->trashedTask))->toBeNull()
        ->and(taskVersionOf($this->openTask))->toBe(2)
        ->and(taskAssigneeOf($this->othersTask))->toBe($this->other->id)
        ->and(taskVersionOf($this->othersTask))->toBe(1)
        ->and(TeamMembership::query()->whereKey($membership->id)->exists())->toBeFalse();
});

it('unassigns a member who leaves the team', function () {
    $this->actingAs($this->user)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->user->id}")->assertNoContent();

    expect(taskAssigneeOf($this->openTask))->toBeNull()->and(taskAssigneeOf($this->privateTask))->toBeNull();
});

it('is backed by the database: a team membership with assigned tasks cannot be deleted (DB-T03)', function () {
    DB::table('project_memberships')->where('user_id', $this->user->id)->delete();

    expect(fn () => DB::table('team_memberships')->where('team_id', $this->team->id)->where('user_id', $this->user->id)->delete())
        ->toThrow(QueryException::class);
});

it('unassigns a member removed from a private project there only', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->private->id}/members/{$this->user->id}")->assertNoContent();

    expect(taskAssigneeOf($this->privateTask))->toBeNull()
        ->and(taskAssigneeOf($this->trashedTask))->toBeNull()
        ->and(taskAssigneeOf($this->openTask))->toBe($this->user->id)
        ->and(taskAssigneeOf($this->othersTask))->toBe($this->other->id);
});

it('keeps the assignment when a team-visible project membership is removed: access remains', function () {
    taskProjectMember($this->open, $this->user, ProjectRole::Manager);

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->open->id}/members/{$this->user->id}")->assertNoContent();

    expect(taskAssigneeOf($this->openTask))->toBe($this->user->id)->and(taskVersionOf($this->openTask))->toBe(1);
});

it('unassigns a member demoted to Viewer, who may no longer write tasks', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->user->id}", ['role' => 'viewer'])->assertOk();

    expect(taskAssigneeOf($this->openTask))->toBeNull()
        ->and(taskAssigneeOf($this->privateTask))->toBeNull()
        ->and(taskAssigneeOf($this->othersTask))->toBe($this->other->id);
});

it('keeps assignments on a role change that keeps write access', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->user->id}", ['role' => 'admin'])->assertOk();

    expect(taskAssigneeOf($this->openTask))->toBe($this->user->id)->and(taskAssigneeOf($this->privateTask))->toBe($this->user->id);
});

it('unassigns non-members when a project is made private, and keeps its members', function () {
    $outsider = taskTeamMember($this->team, TeamRole::Member);
    $task = taskIn($this->open, ['assignee_user_id' => $outsider->id]);
    $ownerTask = taskIn($this->open, ['assignee_user_id' => $this->owner->id]);
    taskProjectMember($this->open, $this->user);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->open->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])->assertOk();

    expect(taskAssigneeOf($task))->toBeNull()
        ->and(taskAssigneeOf($this->openTask))->toBe($this->user->id)
        ->and(taskAssigneeOf($ownerTask))->toBe($this->owner->id);
});

it('unassigns the former Owner from private projects they are not a member of after a transfer', function () {
    $ownersPrivate = taskIn($this->private, ['assignee_user_id' => $this->owner->id]);
    $ownersOpen = taskIn($this->open, ['assignee_user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->other->id])->assertOk();

    expect(taskAssigneeOf($ownersPrivate))->toBeNull()->and(taskAssigneeOf($ownersOpen))->toBe($this->owner->id);
});

it('logs the release with IDs and a reason only', function () {
    Log::spy();

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->private->id}/members/{$this->user->id}")->assertNoContent();

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context) => $event === 'task.assignments_released'
        && $context === ['team_id' => $this->team->id, 'project_id' => $this->private->id, 'user_id' => $this->user->id, 'reason' => 'project_member_removed', 'tasks' => 2])->once();
});

it('does not restore an assignee who lost access while the task was in the trash', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->private->id}/members/{$this->user->id}")->assertNoContent();

    $this->actingAs($this->owner)->postJson("/api/v1/tasks/{$this->trashedTask->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.assignee_user_id', null);
});

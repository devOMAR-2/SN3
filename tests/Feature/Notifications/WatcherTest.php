<?php

require_once __DIR__.'/NotificationHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// CORE-009: watching a task (API_SPEC.md §6.1 "POST /tasks/{id}/watchers — Project viewer with team
// membership", "DELETE /tasks/{id}/watchers/me — Watcher"; DECIDED-006 watchers are separate from the
// assignee; ADR-029 §1). Hidden, trashed and foreign tasks are 404 (AUTH-01), archived 423.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->member = taskTeamMember($this->team);
    $this->viewer = taskTeamMember($this->team, TeamRole::Viewer);
    $this->admin = taskTeamMember($this->team, TeamRole::Admin);
    $this->project = taskProject($this->team);
    $this->task = taskIn($this->project);
    $this->private = taskProject($this->team, 'private');
    $this->privateTask = taskIn($this->private);
});

it('lets anyone who can see the task watch it, a Team Viewer included, once', function () {
    foreach ([$this->owner, $this->member, $this->viewer, $this->admin] as $user) {
        $first = notifyWatch($user, $this->task)->assertCreated();

        expect($first->json('data'))->toMatchArray(['task_id' => $this->task->id, 'user_id' => $user->id])
            ->and($first->json('data.created_at'))->toBeString();

        notifyWatch($user, $this->task)->assertOk()->assertJsonPath('data.created_at', $first->json('data.created_at'));
    }

    expect(DB::table('task_watchers')->where('task_id', $this->task->id)->count())->toBe(4);
});

it('lets private-project members and the Owner watch its tasks, and hides them from everyone else', function () {
    taskProjectMember($this->private, $this->viewer, ProjectRole::Manager);

    notifyWatch($this->owner, $this->privateTask)->assertCreated();
    notifyWatch($this->viewer, $this->privateTask)->assertCreated();

    foreach ([$this->admin, $this->member, Team::factory()->create()->owner] as $user) {
        notifyWatch($user, $this->privateTask)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($user)->deleteJson("/api/v1/tasks/{$this->privateTask->id}/watchers/me")->assertNotFound();
    }
});

it('unwatches with 204, and answers 404 to someone who does not watch', function () {
    notifyWatch($this->member, $this->task)->assertCreated();

    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$this->task->id}/watchers/me")->assertNoContent();
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$this->task->id}/watchers/me")->assertNotFound();

    expect(DB::table('task_watchers')->count())->toBe(0);
});

it('answers 404 for trashed, random and malformed tasks', function () {
    $trashed = taskIn($this->project);
    $trashed->delete();

    foreach ([$trashed->id, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
        $this->actingAs($this->member)->postJson("/api/v1/tasks/{$id}/watchers")->assertNotFound();
        $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$id}/watchers/me")->assertNotFound();
    }
});

it('refuses watching on an archived project or team (423)', function (string $what) {
    notifyWatch($this->member, $this->task)->assertCreated();

    $what === 'project'
        ? DB::table('projects')->where('id', $this->project->id)->update(['archived_at' => now()])
        : DB::table('teams')->where('id', $this->team->id)->update(['archived_at' => now()]);

    notifyWatch($this->viewer, $this->task)->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->member)->deleteJson("/api/v1/tasks/{$this->task->id}/watchers/me")->assertStatus(423);
})->with(['project', 'team']);

it('rejects a body, and guests', function () {
    notifyWatch($this->member, $this->task)->assertCreated();

    $this->actingAs($this->member)->postJson("/api/v1/tasks/{$this->task->id}/watchers", ['user_id' => $this->viewer->id])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.user_id.0', 'unknown_field');

    expect(DB::table('task_watchers')->where('user_id', $this->viewer->id)->exists())->toBeFalse();

    auth()->logout();
    $this->postJson("/api/v1/tasks/{$this->task->id}/watchers")->assertUnauthorized();
    $this->deleteJson("/api/v1/tasks/{$this->task->id}/watchers/me")->assertUnauthorized();
});

it('tells the task page whether the viewer watches the task', function () {
    $this->actingAs($this->viewer)->get("/tasks/{$this->task->id}")->assertInertia(fn (Assert $page) => $page->where('watching', false));

    notifyWatch($this->viewer, $this->task)->assertCreated();

    $this->actingAs($this->viewer)->get("/tasks/{$this->task->id}")->assertInertia(fn (Assert $page) => $page->where('watching', true));
    $this->actingAs($this->member)->get("/tasks/{$this->task->id}")->assertInertia(fn (Assert $page) => $page->where('watching', false));
});

it('sends nothing to a watcher who lost access, while keeping their watch', function () {
    taskProjectMember($this->private, $this->member);
    notifyWatch($this->member, $this->privateTask)->assertCreated();

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->private->id}/members/{$this->member->id}")->assertNoContent();
    notifyEditTask($this->owner, $this->privateTask, ['title' => 'Changed after removal'])->assertOk();
    notifyComment($this->owner, $this->privateTask);

    expect(storedNotificationsOf($this->member))->toBeEmpty()
        ->and(DB::table('task_watchers')->where('user_id', $this->member->id)->count())->toBe(1);
});

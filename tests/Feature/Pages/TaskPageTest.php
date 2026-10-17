<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PageHelpers.php';

// CORE-006 task detail (`/tasks/{task}`, ADR-026 §5): `{task}` resolves through AccessibleTasks, so a task
// of a hidden, trashed or foreign project, a trashed task and a random or malformed ID are the same 404
// page (AUTH-01, FR-TASK-008 deep links). Writers get `can.edit`; Viewers and archived projects do not.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->other = Team::factory()->create();
});

/**
 * [team role, visibility, project role, can view, can edit]
 */
dataset('task page matrix', [
    'Owner, private' => ['owner', 'private', null, true, true],
    'Admin, team' => ['admin', 'team', null, true, true],
    'Admin, private' => ['admin', 'private', null, false, false],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, true],
    'Member, team' => ['member', 'team', null, true, true],
    'Member, private' => ['member', 'private', null, false, false],
    'Viewer, team' => ['viewer', 'team', null, true, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false],
    'other team' => ['outsider', 'team', null, false, false],
]);

it('renders task detail by access and 404s for hidden tasks', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $edit) {
    $project = pageProject($this->team, $visibility);
    $task = Task::factory()->for($project)->create(['title' => 'Fix login validation', 'description' => 'Private body']);
    $actor = pageActor($this->team, $role, $this->other);

    if ($projectRole !== null) {
        pageProjectMember($project, $actor, ProjectRole::from($projectRole));
    }

    $response = $this->actingAs($actor)->get("/tasks/{$task->id}");

    if (! $view) {
        $response->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error'));
        expect($response->getContent())->not->toContain('Fix login validation')->not->toContain('Private body');

        // Hidden in the UI, refused by the server.
        $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'x'], ['If-Match' => '"1"'])->assertNotFound();

        return;
    }

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tasks/Show')
        ->where('task.id', $task->id)
        ->where('task.key', $project->project_key.'-'.$task->task_number)
        ->where('project.id', $project->id)
        ->where('team.id', $this->team->id)
        ->has('statuses', 3)
        ->where('can.edit', $edit)
        ->where('can.delete', $edit)
        ->where('lock', null));

    $patch = $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'], ['If-Match' => '"'.$task->fresh()?->version.'"']);
    $edit ? $patch->assertOk() : $patch->assertForbidden();
})->with('task page matrix');

it('offers exactly the eligible assignees', function () {
    $project = pageProject($this->team, 'private');
    $task = Task::factory()->for($project)->create();
    $owner = $this->team->owner;
    $contributor = pageTeamMember($this->team);
    pageProjectMember($project, $contributor);
    $viewerManager = pageTeamMember($this->team, TeamRole::Viewer);
    pageProjectMember($project, $viewerManager, ProjectRole::Manager);
    pageTeamMember($this->team, TeamRole::Admin); // no membership: not eligible on a private project
    $disabled = pageTeamMember($this->team);
    pageProjectMember($project, $disabled);
    $disabled->forceFill(['disabled_at' => now()])->save();

    $ids = collect($this->actingAs($owner)->get("/tasks/{$task->id}")->viewData('page')['props']['assignees'])->pluck('user_id')->sort()->values()->all();

    expect($ids)->toBe(collect([$owner->id, $contributor->id])->sort()->values()->all());
});

it('carries the parent, subtasks, labels and checklist of the task', function () {
    $project = pageProject($this->team);
    $parent = Task::factory()->for($project)->create();
    $task = Task::factory()->childOf($parent)->create();
    $child = Task::factory()->childOf($task)->create();
    $trashedChild = Task::factory()->childOf($task)->create();
    $trashedChild->delete();
    $label = Label::factory()->for($project)->create(['name' => 'Bug']);
    TaskChecklistItem::factory()->for($task)->create();

    $this->actingAs($this->team->owner)->get("/tasks/{$task->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('parent.id', $parent->id)
            ->has('subtasks', 1)
            ->where('subtasks.0.id', $child->id)
            ->has('labels', 1)
            ->where('labels.0.id', $label->id)
            ->has('checklist', 1));
});

it('answers trashed tasks, random and malformed IDs with the same 404', function () {
    $project = pageProject($this->team);
    $trashed = Task::factory()->for($project)->create(['title' => 'Trashed one']);
    $trashed->delete();
    $owner = $this->team->owner;

    foreach ([$trashed->id, '0192a000-0000-7000-8000-00000000ffff', 'not-a-uuid', strtoupper($trashed->id)] as $id) {
        $response = $this->actingAs($owner)->get("/tasks/{$id}")->assertNotFound();
        expect($response->getContent())->not->toContain('Trashed one');
    }
});

it('makes the task read-only in an archived project', function () {
    $project = pageProject($this->team);
    $task = Task::factory()->for($project)->create();
    $project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->team->owner)->get("/tasks/{$task->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('lock', 'project_archived')->where('can.edit', false)->where('can.manage_labels', false));

    $this->actingAs($this->team->owner)->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'x'], ['If-Match' => '"1"'])->assertStatus(423);
});

it('keeps another team’s data out of the page', function () {
    $foreignProject = pageProject($this->other, 'team', ['name' => 'Foreign project']);
    Task::factory()->for($foreignProject)->create(['title' => 'Foreign task']);
    $project = pageProject($this->team);
    $task = Task::factory()->for($project)->create();
    $shared = User::factory()->create();

    $content = $this->actingAs($this->team->owner)->get("/tasks/{$task->id}")->getContent();

    expect($content)->not->toContain('Foreign project')->not->toContain('Foreign task')->not->toContain($this->other->name);
    expect($shared->exists)->toBeTrue();
});

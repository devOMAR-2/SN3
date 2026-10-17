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
 * [team role, visibility, project role, can view, can edit, can manage labels]
 */
dataset('task page matrix', [
    'Owner, private' => ['owner', 'private', null, true, true, true],
    'Admin, team' => ['admin', 'team', null, true, true, false],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true, true],
    'Admin, private' => ['admin', 'private', null, false, false, false],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, true, false],
    'Member, team' => ['member', 'team', null, true, true, false],
    'Member, private, manager' => ['member', 'private', 'manager', true, true, true],
    'Member, private' => ['member', 'private', null, false, false, false],
    'Viewer, team' => ['viewer', 'team', null, true, false, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false, false],
    'other team' => ['outsider', 'team', null, false, false, false],
]);

it('renders task detail by access and 404s for hidden tasks', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $edit, bool $labels) {
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
        $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/labels", ['name' => 'Probe'])->assertNotFound();

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
        ->where('can.manage_labels', $labels)
        ->where('lock', null));

    $patch = $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->id}", ['priority' => 'high'], ['If-Match' => '"'.$task->fresh()?->version.'"']);
    $edit ? $patch->assertOk() : $patch->assertForbidden();

    // Creating a label from the task's picker: offered only with `manage_labels`, refused otherwise.
    $label = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/labels", ['name' => 'Probe']);
    $labels ? $label->assertCreated() : $label->assertForbidden();
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

it('caps the assignee list, says so, and keeps the current assignee selectable', function () {
    $project = pageProject($this->team);
    $this->team->owner->forceFill(['name' => 'B owner'])->save();
    // 201 eligible members besides the Owner, named so that the last one sorts past the cap.
    foreach (range(1, 200) as $n) {
        pageTeamMember($this->team)->forceFill(['name' => sprintf('A member %03d', $n)])->save();
    }
    $last = pageTeamMember($this->team);
    $last->forceFill(['name' => 'Zz last member'])->save();
    $task = Task::factory()->for($project)->assignedTo($last)->create();

    $props = $this->actingAs($this->team->owner)->get("/tasks/{$task->id}")->viewData('page')['props'];
    $ids = collect($props['assignees'])->pluck('user_id');

    expect($props['assigneesTruncated'])->toBeTrue()
        ->and($ids)->toHaveCount(201)
        ->and($ids->last())->toBe($last->id);

    $small = Task::factory()->for(pageProject($this->other))->create();
    $this->actingAs($this->other->owner)->get("/tasks/{$small->id}")
        ->assertInertia(fn (Assert $page) => $page->where('assigneesTruncated', false)->has('assignees', 1));
});

it('does not offer a current assignee past the cap who is no longer eligible', function () {
    $project = pageProject($this->team);
    $this->team->owner->forceFill(['name' => 'B owner'])->save();
    foreach (range(1, 200) as $n) {
        pageTeamMember($this->team)->forceFill(['name' => sprintf('A member %03d', $n)])->save();
    }
    $last = pageTeamMember($this->team);
    $last->forceFill(['name' => 'Zz last member'])->save();
    $task = Task::factory()->for($project)->assignedTo($last)->create();
    $last->forceFill(['disabled_at' => now()])->save();

    $ids = collect($this->actingAs($this->team->owner)->get("/tasks/{$task->id}")->viewData('page')['props']['assignees'])->pluck('user_id');

    expect($ids)->toHaveCount(200)->not->toContain($last->id);
});

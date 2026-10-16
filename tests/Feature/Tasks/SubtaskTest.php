<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Models\Task;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

// CORE-005: subtasks through `parent_task_id` (API_SPEC.md §6.1; DATABASE.md §5, §19): same project,
// at most three levels below the root, no cycles. Acceptance scenarios TASK-01 and TASK-02;
// TEST-FR-TASK-007 (subtasks).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = taskProject($this->team);

    // root (depth 0) → child (1) → grandchild (2) → great-grandchild (3)
    $this->root = taskIn($this->project);
    $this->child = Task::factory()->childOf($this->root)->create();
    $this->grandchild = Task::factory()->childOf($this->child)->create();
    $this->great = Task::factory()->childOf($this->grandchild)->create();
});

it('refuses a fourth descendant level and writes no row (TASK-01)', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'Too deep', 'parent_task_id' => $this->great->id], taskIdempotencyHeader())
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.parent_task_id', ['max_depth']);

    expect(Task::query()->count())->toBe(4)->and($this->project->fresh()->next_task_number)->toBe(5);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/projects/{$this->project->id}/tasks", ['title' => 'Deepest allowed', 'parent_task_id' => $this->grandchild->id], taskIdempotencyHeader())
        ->assertCreated()
        ->assertJsonPath('data.parent_task_id', $this->grandchild->id);
});

it('refuses a parent that would create a cycle, and leaves the tree unchanged (TASK-02)', function (string $parent) {
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->root->id}", ['parent_task_id' => $this->{$parent}->id], taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.parent_task_id', ['cycle']);

    expect($this->root->fresh())->parent_task_id->toBeNull()->version->toBe(1);
})->with(['root', 'child', 'grandchild', 'great']);

it('counts the moved subtree, trashed subtasks included, against the depth limit', function () {
    $other = taskIn($this->project);
    $otherChild = Task::factory()->childOf($other)->create();

    // Moving `child` (with two levels below it) under `otherChild` (depth 1) would reach depth 4.
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->child->id}", ['parent_task_id' => $otherChild->id], taskIfMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.parent_task_id', ['max_depth']);

    // With the deepest level in the trash the move is still refused: restoring it must stay legal.
    $this->great->delete();

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->child->id}", ['parent_task_id' => $otherChild->id], taskIfMatch(1))
        ->assertUnprocessable();

    // Under `other` (depth 0) it fits: child 1, grandchild 2, great-grandchild 3.
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->child->id}", ['parent_task_id' => $other->id], taskIfMatch(1))
        ->assertOk()
        ->assertJsonPath('data.parent_task_id', $other->id);
});

it('makes a subtask a root again with parent_task_id null, and refuses a parent from another project', function () {
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->great->id}", ['parent_task_id' => null], taskIfMatch(1))
        ->assertOk()
        ->assertJsonPath('data.parent_task_id', null);

    $foreign = taskIn(taskProject($this->team));

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->great->id}", ['parent_task_id' => $foreign->id], taskIfMatch(2))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.parent_task_id', ['exists']);
});

it('keeps subtasks independent: their own status and assignee', function () {
    $done = taskStatusId($this->project, 'done');

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/tasks/{$this->child->id}", ['status_id' => $done, 'assignee_user_id' => $this->owner->id], taskIfMatch(1))
        ->assertOk();

    expect($this->root->fresh())->status_id->not->toBe($done)->assignee_user_id->toBeNull();
});

<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// CORE-005 completes WF-01 (API_SPEC.md §5 DELETE status, ADR-021 §4 step 4): deleting a status that
// still has tasks needs a replacement (409 without one, no change); with one, every task moves to it
// atomically.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = taskProject($this->team);
    $this->doomed = ProjectStatus::factory()->for($this->project)->create(['name' => 'Review']);
});

it('refuses to delete a status that still has tasks without a replacement, and changes nothing (WF-01)', function () {
    $task = taskIn($this->project, ['status_id' => $this->doomed->id]);
    $workflowVersion = $this->project->fresh()->workflow_version;

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$this->doomed->id}", [], ['If-Match' => '"1"'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect($this->doomed->fresh())->not->toBeNull()
        ->and($task->fresh()->status_id)->toBe($this->doomed->id)
        ->and($this->project->fresh()->workflow_version)->toBe($workflowVersion);
});

it('counts trashed tasks too: they keep their status until restored', function () {
    taskIn($this->project, ['status_id' => $this->doomed->id])->delete();

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$this->doomed->id}", [], ['If-Match' => '"1"'])
        ->assertStatus(409);
});

it('moves every task to the replacement atomically, after its tasks and in their order (WF-01)', function () {
    $done = taskStatusId($this->project, 'done');
    $already = taskIn($this->project, ['status_id' => $done]);
    $first = taskIn($this->project, ['status_id' => $this->doomed->id]);
    $second = taskIn($this->project, ['status_id' => $this->doomed->id]);
    $trashed = taskIn($this->project, ['status_id' => $this->doomed->id]);
    $trashed->delete();
    $elsewhere = taskIn($this->project);

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$this->doomed->id}", ['replacement_status_id' => $done], ['If-Match' => '"1"'])
        ->assertNoContent();

    $moved = Task::withTrashed()->where('status_id', $done)->orderBy('position')->pluck('id')->all();

    expect($moved)->toBe([$already->id, $first->id, $second->id, $trashed->id])
        ->and($first->fresh()->version)->toBe(2)
        ->and($already->fresh()->version)->toBe(1)
        ->and($elsewhere->fresh()->status_id)->toBe(taskDefaultStatusId($this->project))
        ->and(DB::table('project_statuses')->where('id', $this->doomed->id)->exists())->toBeFalse();

    $this->actingAs($this->owner)->getJson("/api/v1/tasks/{$first->id}")->assertJsonPath('data.status_id', $done);
});

it('deletes an empty status without a replacement, as before', function () {
    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/projects/{$this->project->id}/statuses/{$this->doomed->id}", [], ['If-Match' => '"1"'])
        ->assertNoContent();
});

it('is backed by the database: a status that tasks use cannot be deleted directly', function () {
    taskIn($this->project, ['status_id' => $this->doomed->id]);

    expect(fn () => DB::table('project_statuses')->where('id', $this->doomed->id)->delete())
        ->toThrow(QueryException::class);
});

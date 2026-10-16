<?php

require_once __DIR__.'/TaskHelpers.php';

use App\Domain\Projects\ProjectRole;
use App\Domain\Tasks\Actions\CreateChecklistItem;
use App\Domain\Tasks\Actions\CreateLabel;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\DeleteChecklistItem;
use App\Domain\Tasks\Actions\DeleteLabel;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Actions\TrashTask;
use App\Domain\Tasks\Actions\UpdateChecklistItem;
use App\Domain\Tasks\Actions\UpdateLabel;
use App\Domain\Teams\TeamRole;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use App\Support\Tenancy\AccessibleTasks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// CORE-005: every task, label and checklist writer re-checks the actor on the locked rows (ADR-020 §7,
// ADR-023 §4): the request was authorised, then the actor's role changed while it waited for the
// lock. Still seeing the project but without the right is a 409; no longer seeing it is a 404.
// Nothing is written either way.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->member = taskTeamMember($this->team, TeamRole::Member);
    $this->project = taskProject($this->team);
    taskProjectMember($this->project, $this->member, ProjectRole::Manager);
    $this->task = taskIn($this->project);
    $this->item = TaskChecklistItem::factory()->for($this->task)->create(['content' => 'Kept']);
    $this->label = Label::factory()->for($this->project)->create(['name' => 'Kept']);
    $this->trashed = taskIn($this->project);
    $this->trashed->delete();

    // What the route bindings resolved before the change.
    $this->resolvedProject = app(AccessibleProjects::class)->findOrFail($this->member, $this->project->id);
    $this->resolvedTask = app(AccessibleTasks::class)->findOrFail($this->member, $this->task->id);
    $this->resolvedTrashed = app(AccessibleTasks::class)->findTrashedOrFail($this->member, $this->trashed->id);
});

function recheckDemoteTo(object $test, string $teamRole, ?string $projectRole = null): void
{
    DB::table('team_memberships')->where('team_id', $test->team->id)->where('user_id', $test->member->id)->update(['role' => $teamRole]);

    if ($projectRole !== null) {
        DB::table('project_memberships')->where('project_id', $test->project->id)->where('user_id', $test->member->id)->update(['project_role' => $projectRole]);
    }
}

$contributeWriters = [
    'create task' => [fn () => app(CreateTask::class)->handle(test()->resolvedProject, test()->member, ['title' => 'Late'])],
    'trash task' => [fn () => app(TrashTask::class)->handle(test()->resolvedTask, test()->member, 1)],
    'add checklist item' => [fn () => app(CreateChecklistItem::class)->handle(test()->resolvedTask, test()->member, 'Late')],
    'edit checklist item' => [fn () => app(UpdateChecklistItem::class)->handle(test()->resolvedTask, test()->item, test()->member, ['text' => 'Late'])],
    'delete checklist item' => [fn () => app(DeleteChecklistItem::class)->handle(test()->resolvedTask, test()->item, test()->member)],
];

$managerWriters = [
    'restore task' => [fn () => app(RestoreTask::class)->handle(test()->resolvedTrashed, test()->member)],
    'create label' => [fn () => app(CreateLabel::class)->handle(test()->resolvedProject, test()->member, 'Late', null)],
    'rename label' => [fn () => app(UpdateLabel::class)->handle(test()->resolvedProject, test()->label, test()->member, ['name' => 'Late'])],
    'delete label' => [fn () => app(DeleteLabel::class)->handle(test()->resolvedProject, test()->label, test()->member)],
];

function recheckNothingChanged(object $test): void
{
    expect(Task::query()->where('project_id', $test->project->id)->count())->toBe(1)
        ->and($test->project->fresh()->next_task_number)->toBe(3)
        ->and($test->task->fresh()->version)->toBe(1)
        ->and(Task::withTrashed()->findOrFail($test->trashed->id)->deleted_at)->not->toBeNull()
        ->and(TaskChecklistItem::query()->pluck('content')->all())->toBe(['Kept'])
        ->and(Label::query()->pluck('name')->all())->toBe(['Kept']);
}

it('answers 409 to a task or checklist write by someone demoted to Viewer meanwhile', function (Closure $write) {
    recheckDemoteTo($this, 'viewer');

    expect($write)->toThrow(DomainConflictException::class);
    recheckNothingChanged($this);
})->with($contributeWriters);

it('answers 409 to a restore or label write by a manager demoted to contributor meanwhile', function (Closure $write) {
    recheckDemoteTo($this, 'member', 'contributor');

    expect($write)->toThrow(DomainConflictException::class);
    recheckNothingChanged($this);
})->with($managerWriters);

it('answers 404 to every writer when the project became invisible meanwhile', function (Closure $write) {
    DB::table('project_memberships')->where('project_id', $this->project->id)->delete();
    DB::table('projects')->where('id', $this->project->id)->update(['visibility' => 'private']);

    expect($write)->toThrow(ModelNotFoundException::class);
    recheckNothingChanged($this);
})->with([...$contributeWriters, ...$managerWriters]);

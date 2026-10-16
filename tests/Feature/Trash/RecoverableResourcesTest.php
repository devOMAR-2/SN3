<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Domain\Trash\TrashRetention;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Tenancy\RecoverableResources;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// CORE-010: each layer of the trash resolver and the 30-day window on its own (ADR-022 §4), below the
// HTTP tests, which also pass through the actions' re-checks. Each test isolates one filter, so
// removing that filter alone makes it fail.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->admin = TeamMembership::factory()->for($this->team)->role(TeamRole::Admin)->create()->user;
    $this->resolver = app(RecoverableResources::class);
});

function trashAt(Team|Project $model, DateTimeInterface $at): void
{
    $model->forceFill(['deleted_at' => $at])->save();
}

// --- TrashRetention ----------------------------------------------------------------------------------

it('puts the cutoff exactly 30 days before now and treats it as expired', function () {
    expect(TrashRetention::cutoff()->equalTo(now()->subDays(30)))->toBeTrue()
        ->and(TrashRetention::isRecoverable(now()->subDays(30)))->toBeFalse()
        ->and(TrashRetention::isRecoverable(now()->subDays(30)->addSecond()))->toBeTrue()
        ->and(TrashRetention::isRecoverable(now()->subDays(30)->subSecond()))->toBeFalse()
        ->and(TrashRetention::isRecoverable(null))->toBeFalse();
});

it('measures the window from an explicit instant', function () {
    $at = CarbonImmutable::parse('2026-03-01T12:00:00Z');

    expect(TrashRetention::cutoff($at)->toIso8601ZuluString())->toBe('2026-01-30T12:00:00Z')
        ->and(TrashRetention::isRecoverable($at->subDays(30), $at))->toBeFalse()
        ->and(TrashRetention::isRecoverable($at->subDays(30)->addSecond(), $at))->toBeTrue();
});

// --- RecoverableResources::team() --------------------------------------------------------------------

it('resolves a trashed team for its Owner up to one second before the 30 days, not at the boundary', function () {
    trashAt($this->team, now()->subDays(30)->addSecond());
    expect($this->resolver->team($this->owner, $this->team->id)->id)->toBe($this->team->id);

    trashAt($this->team, now()->subDays(30));
    expect(fn () => $this->resolver->team($this->owner, $this->team->id))->toThrow(ModelNotFoundException::class);
});

it('filters trashed teams to their Owner in the query itself', function () {
    trashAt($this->team, now()->subDay());
    // With every policy check allowing, only the query's Owner filter keeps the Admin out.
    Gate::before(fn () => true);

    expect(fn () => $this->resolver->team($this->admin, $this->team->id))->toThrow(ModelNotFoundException::class)
        ->and($this->resolver->team($this->owner, $this->team->id)->id)->toBe($this->team->id);
});

it('also asks TeamPolicy::restore for a trashed team', function () {
    trashAt($this->team, now()->subDay());
    // The query lets the Owner through; a policy that says no must still win.
    Gate::before(fn (User $user, string $ability) => $ability === 'restore' ? false : null);

    expect(fn () => $this->resolver->team($this->owner, $this->team->id))->toThrow(ModelNotFoundException::class);
});

it('resolves no live team and no malformed team ID', function () {
    expect(fn () => $this->resolver->team($this->owner, $this->team->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->resolver->team($this->owner, 'not-a-uuid'))->toThrow(ModelNotFoundException::class);
});

// --- RecoverableResources::project() -----------------------------------------------------------------

it('resolves a trashed project up to one second before the 30 days, not at the boundary', function () {
    $project = Project::factory()->for($this->team)->create();

    trashAt($project, now()->subDays(30)->addSecond());
    expect($this->resolver->project($this->owner, $project->id)->id)->toBe($project->id);

    trashAt($project, now()->subDays(30));
    expect(fn () => $this->resolver->project($this->owner, $project->id))->toThrow(ModelNotFoundException::class);
});

it('resolves no live project', function () {
    $project = Project::factory()->for($this->team)->create();
    Gate::before(fn () => true);

    expect(fn () => $this->resolver->project($this->owner, $project->id))->toThrow(ModelNotFoundException::class);
});

it('applies the AccessibleProjects visibility predicate to trashed projects in the query itself', function () {
    $private = Project::factory()->for($this->team)->private()->create();
    trashAt($private, now()->subDay());
    // With every policy check allowing, only the visibility predicate keeps a non-member Admin out.
    Gate::before(fn () => true);

    expect(fn () => $this->resolver->project($this->admin, $private->id))->toThrow(ModelNotFoundException::class);

    ProjectMembership::query()->insert([
        'id' => (string) Str::uuid7(),
        'project_id' => $private->id,
        'team_id' => $this->team->id,
        'user_id' => $this->admin->id,
        'project_role' => ProjectRole::Manager->value,
        'added_by' => $this->owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->resolver->project($this->admin, $private->id)->id)->toBe($private->id);
});

it('resolves no project of a trashed team, even for the Owner', function () {
    $project = Project::factory()->for($this->team)->create();
    trashAt($project, now()->subDay());
    trashAt($this->team, now()->subDay());
    Gate::before(fn () => true);

    expect(fn () => $this->resolver->project($this->owner, $project->id))->toThrow(ModelNotFoundException::class);
});

it('also asks ProjectPolicy::restoreDeleted for a trashed project', function () {
    $project = Project::factory()->for($this->team)->create();
    trashAt($project, now()->subDay());
    Gate::before(fn (User $user, string $ability) => $ability === 'restoreDeleted' ? false : null);

    expect(fn () => $this->resolver->project($this->owner, $project->id))->toThrow(ModelNotFoundException::class);
});

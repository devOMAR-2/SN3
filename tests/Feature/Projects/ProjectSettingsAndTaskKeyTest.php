<?php

use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Actions\UpdateProjectSettings;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\TaskNumbers;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// CORE-004: project settings (API_SPEC.md §5 /settings), task-number allocation and the project key
// freeze (DATABASE.md §5, §11, §19; ADR-021). Acceptance scenario PROJ-01; DATABASE.md §16 DB-T05
// (sequential part; the real-process race is in TaskNumberConcurrencyTest); TEST-FR-TASK-003 (stable
// task keys: the allocator and the key freeze; tasks themselves arrive with CORE-005).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = Project::factory()->for($this->team)->withDefaultStatuses()->create(['project_key' => 'WEB', 'name' => 'Website']);
});

/** Issues the next task number of a project the way CORE-005's task creation will. */
function wfAllocate(Project $project): int
{
    return DB::transaction(fn () => TaskNumbers::allocate(Project::query()->whereKey($project->id)->lock(Project::WRITE_LOCK)->firstOrFail()));
}

// --- Settings ----------------------------------------------------------------------------------------

it('shows the effective settings to anyone who can see the project', function (TeamRole $role) {
    $user = $role === TeamRole::Owner ? $this->owner : TeamMembership::factory()->for($this->team)->role($role)->create()->user;

    $this->actingAs($user)->getJson("/api/v1/projects/{$this->project->id}/settings")
        ->assertOk()
        ->assertJsonPath('data', ['project_id' => $this->project->id, 'sprints_enabled' => false, 'key_frozen' => false, 'version' => 1])
        ->assertJsonPath('meta.request_id', fn ($id) => is_string($id));
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member, TeamRole::Viewer]);

it('toggles sprints against the project version and increments it', function () {
    Log::spy();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", ['sprints_enabled' => true], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.sprints_enabled', true)
        ->assertJsonPath('data.version', 2);

    // No change keeps the version; an empty body is a no-op too.
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", ['sprints_enabled' => true], ['If-Match' => '"2"'])
        ->assertOk()
        ->assertJsonPath('data.version', 2);
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", [], ['If-Match' => '"2"'])
        ->assertOk()
        ->assertJsonPath('data.version', 2);

    // Settings and metadata share the project version (one Project resource, API_SPEC.md §1.4).
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['name' => 'X'], ['If-Match' => '"1"'])
        ->assertStatus(412);
    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}")->assertJsonPath('data.version', 2);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", ['sprints_enabled' => false], ['If-Match' => '"2"'])
        ->assertOk()
        ->assertJsonPath('data.sprints_enabled', false)
        ->assertJsonPath('data.version', 3);

    Log::shouldHaveReceived('info')->with('project.settings_changed', [
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'actor_id' => $this->owner->id,
        'sprints_enabled' => true,
    ])->once();
});

it('requires If-Match on settings (428) and refuses a stale version (412)', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", ['sprints_enabled' => true])
        ->assertStatus(428);
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", ['sprints_enabled' => true], ['If-Match' => '"2"'])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');

    expect($this->project->refresh())->sprints_enabled->toBeFalse()->version->toBe(1);
});

it('validates settings with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/settings", $body, ['If-Match' => '"1"'])
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect($this->project->refresh())->sprints_enabled->toBeFalse()->version->toBe(1);
})->with([
    'null' => [['sprints_enabled' => null], 'sprints_enabled', 'required'],
    'string' => [['sprints_enabled' => 'yes'], 'sprints_enabled', 'boolean'],
    'integer' => [['sprints_enabled' => 1], 'sprints_enabled', 'boolean'],
    'no story points setting yet' => [['story_points_enabled' => true], 'story_points_enabled', 'unknown_field'],
    'no views setting yet' => [['enabled_views' => ['board']], 'enabled_views', 'unknown_field'],
    'metadata belongs to PATCH /projects' => [['name' => 'X'], 'name', 'unknown_field'],
]);

it('re-checks the actor on the locked rows before changing settings', function () {
    $manager = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create()->user;
    ProjectMembership::factory()->for($this->project)->member($manager)->role(ProjectRole::Manager)->create();
    $stale = app(AccessibleProjects::class)->findOrFail($manager, $this->project->id);

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$this->project->id}/members/{$manager->id}", ['role' => 'contributor'])->assertOk();

    expect(fn () => app(UpdateProjectSettings::class)->handle($stale, $manager, 1, ['sprints_enabled' => true]))
        ->toThrow(DomainConflictException::class)
        ->and($this->project->refresh()->sprints_enabled)->toBeFalse();
});

// --- Task numbers (DB-T05, FR-TASK-003) ---------------------------------------------------------------

it('issues consecutive task numbers per project and formats task keys', function () {
    $other = Project::factory()->for($this->team)->create(['project_key' => 'OPS']);

    expect([wfAllocate($this->project), wfAllocate($this->project), wfAllocate($other), wfAllocate($this->project)])->toBe([1, 2, 1, 3])
        ->and($this->project->refresh()->next_task_number)->toBe(4)
        ->and($other->refresh()->next_task_number)->toBe(2)
        ->and(TaskNumbers::key($this->project, 3))->toBe('WEB-3')
        ->and($this->project->version)->toBe(1);
});

it('keeps the locked model in step with the counter', function () {
    DB::transaction(function () {
        $locked = Project::query()->whereKey($this->project->id)->lock(Project::WRITE_LOCK)->firstOrFail();

        expect(TaskNumbers::allocate($locked))->toBe(1)
            ->and($locked->next_task_number)->toBe(2)
            ->and($locked->isDirty())->toBeFalse()
            ->and($locked->keyIsFrozen())->toBeTrue();
    });
});

it('returns the number when the task transaction rolls back', function () {
    try {
        DB::transaction(function () {
            TaskNumbers::allocate(Project::query()->whereKey($this->project->id)->lock(Project::WRITE_LOCK)->firstOrFail());

            throw new RuntimeException('task insert failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->project->refresh()->next_task_number)->toBe(1)
        ->and(wfAllocate($this->project))->toBe(1);
});

// --- Key freeze (PROJ-01) -------------------------------------------------------------------------------

it('keeps the key editable until the first task number is issued', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'site'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.key', 'SITE');

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/settings")->assertJsonPath('data.key_frozen', false);
});

it('freezes the project key after the first task (PROJ-01)', function () {
    expect(wfAllocate($this->project))->toBe(1);

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/settings")->assertJsonPath('data.key_frozen', true);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'SITE'], ['If-Match' => '"1"'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'SITE', 'name' => 'Renamed'], ['If-Match' => '"1"'])
        ->assertStatus(409);

    expect($this->project->refresh())->project_key->toBe('WEB')->name->toBe('Website')->version->toBe(1)
        ->and(TaskNumbers::key($this->project, 1))->toBe('WEB-1');

    // Sending the current key (in any case) is not a change, and the rest of the project stays editable.
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}", ['key' => 'web', 'name' => 'Renamed'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.key', 'WEB')
        ->assertJsonPath('data.name', 'Renamed');
});

it('stays frozen however many numbers were issued, and checks the freeze on the locked row', function () {
    $stale = app(AccessibleProjects::class)->findOrFail($this->owner, $this->project->id);
    wfAllocate($this->project);
    wfAllocate($this->project);

    // The caller's copy still shows next_task_number = 1; the locked row decides.
    expect($stale->keyIsFrozen())->toBeFalse()
        ->and(fn () => app(UpdateProject::class)->handle($stale, $this->owner, 1, ['key' => 'SITE']))->toThrow(DomainConflictException::class)
        ->and($this->project->refresh()->project_key)->toBe('WEB');
});

it('refuses a frozen key change and a lower task counter in the database itself', function () {
    $direct = fn (array $values) => function () use ($values) {
        DB::table('projects')->where('id', $this->project->id)->update($values);
    };
    $rejected = function (Closure $statement): string {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return 'accepted';
    };

    // Before the first task the key may change.
    expect($rejected($direct(['project_key' => 'SITE'])))->toBe('accepted');

    wfAllocate($this->project);

    expect($rejected($direct(['project_key' => 'WEB'])))->toContain('projects_project_key_frozen')
        ->and($rejected($direct(['next_task_number' => 1])))->toContain('projects_next_task_number_monotonic')
        ->and($rejected($direct(['next_task_number' => 9])))->toBe('accepted')
        ->and($rejected($direct(['name' => 'Renamed', 'project_key' => 'SITE'])))->toBe('accepted')
        ->and($this->project->refresh())->project_key->toBe('SITE')->next_task_number->toBe(9);
});

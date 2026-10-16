<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// CORE-003: the projects / project_memberships constraints of DATABASE.md §4, enforced by
// PostgreSQL itself (direct SQL bypasses the application on purpose).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->create(['project_key' => 'WEB']);
    $this->member = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create()->user;
});

/**
 * Inserts a project row directly, bypassing the application.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertProjectRow(Team $team, array $overrides = []): void
{
    DB::table('projects')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $team->id,
        'name' => 'Direct',
        'project_key' => 'DIR',
        'visibility' => 'team',
        'created_by' => $team->owner_user_id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertProjectMembershipRow(Project $project, User $user, array $overrides = []): void
{
    DB::table('project_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'project_id' => $project->id,
        'team_id' => $project->team_id,
        'user_id' => $user->id,
        'project_role' => 'contributor',
        'added_by' => $project->created_by,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/** Runs $statement in a savepoint so the failure does not abort the test transaction. */
function rejectedBy(Closure $statement): string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return 'accepted';
}

it('generates UUIDv7 keys and starts version and task counter at 1', function () {
    $membership = ProjectMembership::factory()->for($this->project)->member($this->member)->create();
    $project = $this->project->refresh();

    foreach ([$project->id, $membership->id] as $id) {
        expect(Uuid::fromString($id)->getFields()->getVersion())->toBe(7);
    }

    expect($project)->version->toBe(1)->next_task_number->toBe(1)->sprints_enabled->toBeFalse()
        ->and($project->visibility->value)->toBe('team');
});

it('enforces the key format, visibility, name, counter and version checks', function (array $overrides, string $constraint) {
    expect(rejectedBy(fn () => insertProjectRow($this->team, $overrides)))->toContain($constraint);
})->with([
    'lower-case key' => [['project_key' => 'dir'], 'projects_project_key_format_check'],
    'one-character key' => [['project_key' => 'D'], 'projects_project_key_format_check'],
    'key with a dash' => [['project_key' => 'D-1'], 'projects_project_key_format_check'],
    'unknown visibility' => [['visibility' => 'public'], 'projects_visibility_check'],
    'blank name' => [['name' => '  '], 'projects_name_not_blank_check'],
    'zero task counter' => [['next_task_number' => 0], 'projects_next_task_number_check'],
    'zero version' => [['version' => 0], 'projects_version_check'],
]);

it('keeps project keys unique per team, including trashed projects, but not across teams', function () {
    expect(rejectedBy(fn () => insertProjectRow($this->team, ['project_key' => 'WEB'])))->toContain('projects_team_id_project_key_unique');

    $this->project->delete();
    expect(rejectedBy(fn () => insertProjectRow($this->team, ['project_key' => 'WEB'])))->toContain('projects_team_id_project_key_unique');

    expect(rejectedBy(fn () => insertProjectRow(Team::factory()->create(), ['project_key' => 'WEB'])))->toBe('accepted');
});

it('accepts only manager and contributor project roles', function () {
    expect(rejectedBy(fn () => insertProjectMembershipRow($this->project, $this->member, ['project_role' => 'viewer'])))
        ->toContain('project_memberships_project_role_check')
        ->and(rejectedBy(fn () => insertProjectMembershipRow($this->project, $this->member, ['project_role' => 'manager'])))
        ->toBe('accepted')
        ->and(rejectedBy(fn () => insertProjectMembershipRow($this->project, $this->member, ['project_role' => 'contributor'])))
        ->toContain('project_memberships_project_id_user_id_unique');
});

it('refuses a project member who is not in the project team', function () {
    $stranger = User::factory()->create();
    $otherTeamOwner = Team::factory()->create()->owner;

    expect(rejectedBy(fn () => insertProjectMembershipRow($this->project, $stranger)))
        ->toContain('project_memberships_team_id_user_id_foreign')
        ->and(rejectedBy(fn () => insertProjectMembershipRow($this->project, $otherTeamOwner)))
        ->toContain('project_memberships_team_id_user_id_foreign');
});

it('refuses a membership whose team is not the project team, even for a member of both teams', function () {
    $otherTeam = Team::factory()->create();
    TeamMembership::factory()->for($otherTeam)->create(['user_id' => $this->member->id]);

    // The user belongs to the other team, but the project does not: (project_id, team_id) fails.
    expect(rejectedBy(fn () => insertProjectMembershipRow($this->project, $this->member, ['team_id' => $otherTeam->id])))
        ->toContain('project_memberships_project_id_team_id_foreign');
});

it('refuses to delete a team membership that still has project memberships', function () {
    ProjectMembership::factory()->for($this->project)->member($this->member)->role(ProjectRole::Manager)->create();

    expect(rejectedBy(fn () => TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $this->member->id)->delete()))
        ->toContain('project_memberships_team_id_user_id_foreign');
});

it('purges projects and their memberships with the team once the purge defers the membership key', function () {
    ProjectMembership::factory()->for($this->project)->member($this->member)->create();
    $other = Project::factory()->create();

    // Without deferring, the cascade to team_memberships is checked before projects are gone.
    expect(rejectedBy(fn () => DB::table('teams')->where('id', $this->team->id)->delete()))
        ->toContain('project_memberships_team_id_user_id_foreign');

    DB::transaction(function () {
        DB::statement('SET CONSTRAINTS project_memberships_team_id_user_id_foreign DEFERRED');
        DB::table('teams')->where('id', $this->team->id)->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });

    expect(Project::withTrashed()->pluck('id')->all())->toBe([$other->id])
        ->and(ProjectMembership::query()->count())->toBe(0);
});

it('has the indexes DATABASE.md §4 requires', function () {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename in ('projects', 'project_memberships')"))
        ->pluck('indexdef')
        ->map(fn (string $def) => preg_replace('/^.* USING btree /', '', $def))
        ->all();

    expect($indexes)->toContain('(team_id, project_key)')
        ->toContain('(id, team_id)')
        ->toContain('(team_id, visibility, archived_at)')
        ->toContain('(project_id, user_id)')
        ->toContain('(user_id, project_id)')
        ->toContain('(team_id, project_id)');
});

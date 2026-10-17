<?php

use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\SetProjectMember;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// CORE-003: project create/update, visibility changes and explicit project membership.
// TEST-FR-PROJ-001 (create), -002 (visibility modes, default team), -004/-005 (private access),
// -006 (managers add/remove members), -007 (visibility change re-evaluated on the next request);
// TEST-FR-TEAM-006 (team removal revokes project access); acceptance scenario AUTH-04;
// DATABASE.md §16 DB-T08.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Website']);
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->otherTeam = Team::factory()->create();
    $this->outsider = $this->otherTeam->owner;
});

function addToProject(Project $project, User $user, ProjectRole $role = ProjectRole::Contributor): ProjectMembership
{
    return ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

function addToTeam(Team $team, TeamRole $role = TeamRole::Member): User
{
    return TeamMembership::factory()->for($team)->role($role)->create()->user;
}

function projectRolesOf(Project $project): array
{
    return $project->memberships()->get()->mapWithKeys(fn (ProjectMembership $m) => [$m->user_id => $m->project_role->value])->all();
}

// --- Create (FR-PROJ-001, FR-PROJ-002) -------------------------------------------------------------

it('creates a team-visible project by default and makes the creator its manager', function (string $actor) {
    $user = $actor === 'owner' ? $this->owner : $this->members[$actor];

    $response = $this->actingAs($user)
        ->postJson("/api/v1/teams/{$this->team->id}/projects", [
            'name' => '  موقع سنع  ',
            'key' => ' web ',
            'description' => 'إعادة تصميم الموقع',
            'template_id' => null,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'موقع سنع')
        ->assertJsonPath('data.key', 'WEB')
        ->assertJsonPath('data.description', 'إعادة تصميم الموقع')
        ->assertJsonPath('data.visibility', 'team')
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.team_id', $this->team->id)
        ->assertJsonPath('data.created_by', $user->id)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('meta.request_id', fn ($id) => is_string($id));

    $project = Project::query()->findOrFail($response->json('data.id'));

    expect(projectRolesOf($project))->toBe([$user->id => 'manager'])
        ->and($project->sprints_enabled)->toBeFalse()
        ->and($project->next_task_number)->toBe(1);
})->with(['owner', 'admin']);

it('creates a private project that only its creator and the Owner can see', function () {
    $admin = $this->members['admin'];

    $id = $this->actingAs($admin)
        ->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Hiring', 'key' => 'HR', 'visibility' => 'private'])
        ->assertCreated()
        ->assertJsonPath('data.visibility', 'private')
        ->json('data.id');

    $this->actingAs($admin)->getJson("/api/v1/projects/{$id}")->assertOk();
    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$id}")->assertOk()->assertJsonPath('data.role', null);
    $this->actingAs($this->members['member'])->getJson("/api/v1/projects/{$id}")->assertNotFound();
    $this->actingAs($this->members['viewer'])->getJson("/api/v1/projects/{$id}")->assertNotFound();
});

it('denies project creation to Members and Viewers whatever the body (AUTH-04)', function (string $actor, array $body) {
    $this->actingAs($this->members[$actor])
        ->postJson("/api/v1/teams/{$this->team->id}/projects", $body)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(Project::query()->count())->toBe(0);
})->with(['member', 'viewer'])->with([
    'valid body' => [['name' => 'Ops', 'key' => 'OPS']],
    'invalid body' => [['key' => 'not valid', 'junk' => 1]],
]);

it('keeps project keys unique per team, trashed projects included, but not across teams', function () {
    Project::factory()->for($this->team)->create(['project_key' => 'WEB']);

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Again', 'key' => 'web'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    Project::factory()->for($this->team)->create(['project_key' => 'OLD'])->delete();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Again', 'key' => 'OLD'])
        ->assertStatus(409);

    $this->actingAs($this->outsider)->postJson("/api/v1/teams/{$this->otherTeam->id}/projects", ['name' => 'Theirs', 'key' => 'WEB'])
        ->assertCreated();

    expect(Project::withTrashed()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and(ProjectMembership::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('validates the create body with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/projects", [...['name' => 'Website', 'key' => 'WEB'], ...$body])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect(Project::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => null], 'name', 'required'],
    'blank name' => [['name' => '   '], 'name', 'required'],
    'long name' => [['name' => str_repeat('a', 181)], 'name', 'max'],
    'missing key' => [['key' => null], 'key', 'required'],
    'one-character key' => [['key' => 'W'], 'key', 'regex'],
    'key starting with a digit' => [['key' => '1WEB'], 'key', 'regex'],
    'key with a dash' => [['key' => 'WEB-1'], 'key', 'regex'],
    'eleven-character key' => [['key' => 'ABCDEFGHIJK'], 'key', 'regex'],
    'Arabic key' => [['key' => 'موقع'], 'key', 'regex'],
    'unknown visibility' => [['visibility' => 'public'], 'visibility', 'in'],
    'null visibility' => [['visibility' => null], 'visibility', 'required'],
    'long description' => [['description' => str_repeat('a', 5001)], 'description', 'max'],
    'template (FLEX-004)' => [['template_id' => (string) Str::uuid7()], 'template_id', 'prohibited'],
    'unknown field' => [['team_id' => 'x'], 'team_id', 'unknown_field'],
]);

it('accepts the longest valid name, key and description', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/projects", [
            'name' => str_repeat('م', 180),
            'key' => 'A123456789',
            'description' => str_repeat('d', 5000),
        ])
        ->assertCreated()
        ->assertJsonPath('data.key', 'A123456789');
});

it('requires authentication for every project endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with(fn () => [
    ['GET', '/api/v1/teams/'.Str::uuid7().'/projects'],
    ['POST', '/api/v1/teams/'.Str::uuid7().'/projects'],
    ['GET', '/api/v1/projects/'.Str::uuid7()],
    ['PATCH', '/api/v1/projects/'.Str::uuid7()],
    ['GET', '/api/v1/projects/'.Str::uuid7().'/members'],
    ['PUT', '/api/v1/projects/'.Str::uuid7().'/members/'.Str::uuid7()],
    ['DELETE', '/api/v1/projects/'.Str::uuid7().'/members/'.Str::uuid7()],
]);

// --- Update and visibility (If-Match, FR-PROJ-007) ---------------------------------------------------------

it('updates metadata against the current version and increments it', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Website', 'project_key' => 'WEB', 'description' => 'Old']);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Site', 'key' => 'site', 'description' => null], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Site')
        ->assertJsonPath('data.key', 'SITE')
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.version', 2);

    // A request that changes nothing succeeds and keeps the version.
    $this->actingAs($this->owner)
        ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Site'], ['If-Match' => '"2"'])
        ->assertOk()
        ->assertJsonPath('data.version', 2);

    expect($project->refresh())->name->toBe('Site')->project_key->toBe('SITE')->version->toBe(2);
});

it('requires If-Match (428) and refuses a stale version (412), with no change', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Website']);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'])
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'precondition_required');

    $project->forceFill(['version' => 3])->save();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ['If-Match' => '"2"'])
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');

    expect($project->refresh())->name->toBe('Website')->version->toBe(3);
});

it('answers 409 when the new key is taken in the team', function () {
    Project::factory()->for($this->team)->create(['project_key' => 'TAKEN']);
    $project = Project::factory()->for($this->team)->create(['project_key' => 'MINE']);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['key' => 'taken'], ['If-Match' => '"1"'])
        ->assertStatus(409);

    expect($project->refresh())->project_key->toBe('MINE')->version->toBe(1);
});

it('validates the update body with stable codes', function (array $body, string $field, string $code) {
    $project = Project::factory()->for($this->team)->create();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", $body, ['If-Match' => '"1"'])
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'null name' => [['name' => null], 'name', 'required'],
    'bad key' => [['key' => 'W W'], 'key', 'regex'],
    'unknown visibility' => [['visibility' => 'hidden'], 'visibility', 'in'],
    'immutable team' => [['team_id' => 'x'], 'team_id', 'unknown_field'],
    'settings belong to /settings' => [['sprints_enabled' => true], 'sprints_enabled', 'unknown_field'],
]);

it('re-evaluates access on the very next request when visibility changes', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->create(['name' => 'Roadmap']);
    $manager = addToTeam($this->team);
    addToProject($project, $manager, ProjectRole::Manager);
    $bystander = $this->members['member'];
    $admin = $this->members['admin'];

    $this->actingAs($bystander)->getJson("/api/v1/projects/{$project->id}")->assertOk();

    $this->actingAs($manager)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])
        ->assertOk()
        ->assertJsonPath('data.visibility', 'private');

    foreach ([$bystander, $admin, $this->members['viewer']] as $user) {
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}/members")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertJsonCount(0, 'data');
    }

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$project->id}")->assertOk();

    // Audited in the project's activity timeline (NFR-SEC-005; CORE-008, ADR-027 §3).
    expect(TaskActivity::query()->where('project_id', $project->id)->where('action', 'project.visibility_changed')->get()
        ->map(fn (TaskActivity $entry) => [$entry->team_id, $entry->task_id, $entry->actor_user_id, $entry->changes])->all())
        ->toEqual([[$this->team->id, null, $manager->id, ['from' => 'team', 'to' => 'private']]]);

    $this->actingAs($manager)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'team'], ['If-Match' => '"2"'])
        ->assertOk();

    $this->actingAs($bystander)->getJson("/api/v1/projects/{$project->id}")->assertOk();
});

it('lets a Team Admin make a team-visible project private, after which they lose access', function () {
    $project = Project::factory()->for($this->team)->create();
    $admin = $this->members['admin'];

    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])
        ->assertOk();

    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'team'], ['If-Match' => '"2"'])
        ->assertNotFound();
});

it('answers 403 to a Viewer project manager whatever the body (AUTH-04)', function (string $method, string $uri, array $body) {
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Payroll']);
    $viewer = $this->members['viewer'];
    addToProject($project, $viewer, ProjectRole::Manager);
    $uri = str_replace(['{project}', '{member}', '{self}'], [$project->id, $this->members['member']->id, $viewer->id], $uri);

    $this->actingAs($viewer)->json($method, $uri, $body, ['If-Match' => '"1"'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($project->refresh())->name->toBe('Payroll')->visibility->value->toBe('private')
        ->and(projectRolesOf($project))->toBe([$viewer->id => 'manager']);
})->with([
    'patch, valid' => ['PATCH', '/api/v1/projects/{project}', ['visibility' => 'team']],
    'patch, invalid' => ['PATCH', '/api/v1/projects/{project}', ['visibility' => 'nope', 'junk' => 1]],
    'put member, valid' => ['PUT', '/api/v1/projects/{project}/members/{member}', ['role' => 'manager']],
    'put member, empty' => ['PUT', '/api/v1/projects/{project}/members/{member}', []],
    'delete own membership' => ['DELETE', '/api/v1/projects/{project}/members/{self}', []],
]);

// --- Members (FR-PROJ-006) ----------------------------------------------------------------------------

it('adds a team member (201), changes their role (200) and treats a repeat as a no-op (200)', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->private()->create();
    $member = $this->members['member'];

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['role' => 'contributor'])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $member->id)
        ->assertJsonPath('data.role', 'contributor')
        ->assertJsonPath('data.added_by', $this->owner->id);

    $this->actingAs($member)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.role', 'contributor');

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['role' => 'manager'])
        ->assertOk()
        ->assertJsonPath('data.role', 'manager');
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/".strtoupper($member->id), ['role' => 'manager'])
        ->assertOk();

    expect(projectRolesOf($project))->toBe([$member->id => 'manager'])
        ->and($project->refresh()->version)->toBe(1);

    // Audited in the project's activity timeline (NFR-SEC-005; CORE-008, ADR-027 §3).
    expect(TaskActivity::query()->where('project_id', $project->id)->whereIn('action', ['project.member_added', 'project.member_role_changed'])->orderBy('id')->get()
        ->map(fn (TaskActivity $entry) => [$entry->action, $entry->actor_user_id, $entry->changes])->all())
        ->toEqual([
            ['project.member_added', $this->owner->id, ['role' => 'contributor', 'user_id' => $member->id]],
            ['project.member_role_changed', $this->owner->id, ['to' => 'manager', 'from' => 'contributor', 'user_id' => $member->id]],
        ]);
});

it('lets a project manager who is a Team Member manage a private project', function () {
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Payroll']);
    $manager = $this->members['member'];
    addToProject($project, $manager, ProjectRole::Manager);
    $viewer = $this->members['viewer'];

    $this->actingAs($manager)->putJson("/api/v1/projects/{$project->id}/members/{$viewer->id}", ['role' => 'contributor'])->assertCreated();
    $this->actingAs($manager)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Payroll 2'], ['If-Match' => '"1"'])->assertOk();
    $this->actingAs($manager)->deleteJson("/api/v1/projects/{$project->id}/members/{$viewer->id}")->assertNoContent();

    // A manager may step down; the Team Owner keeps implicit manager authority.
    $this->actingAs($manager)->deleteJson("/api/v1/projects/{$project->id}/members/{$manager->id}")->assertNoContent();
    $this->actingAs($manager)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$manager->id}", ['role' => 'manager'])->assertCreated();
});

it('grants project access only to members of the project team', function () {
    $project = Project::factory()->for($this->team)->create();

    foreach ([$this->outsider->id, (string) Str::uuid7(), 'not-a-uuid'] as $userId) {
        $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$userId}", ['role' => 'contributor'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$userId}")
            ->assertNotFound();
    }

    // A team member without a project membership: nothing to remove.
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$this->members['member']->id}")
        ->assertNotFound();

    expect($project->memberships()->count())->toBe(0);
});

it('validates the member body with stable codes', function (array $body, string $field, string $code) {
    $project = Project::factory()->for($this->team)->create();

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$this->members['member']->id}", $body)
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);

    expect($project->memberships()->count())->toBe(0);
})->with([
    'missing role' => [[], 'role', 'required'],
    'no project viewer role' => [['role' => 'viewer'], 'role', 'in'],
    'team role' => [['role' => 'owner'], 'role', 'in'],
    'unknown field' => [['role' => 'manager', 'team_id' => 'x'], 'team_id', 'unknown_field'],
]);

it('lists explicit members, with email addresses only for the Owner and Admins', function (string $actor, bool $withEmail) {
    $project = Project::factory()->for($this->team)->create();
    foreach (['admin', 'member', 'viewer'] as $role) {
        addToProject($project, $this->members[$role], $role === 'admin' ? ProjectRole::Manager : ProjectRole::Contributor);
    }

    $user = $actor === 'owner' ? $this->owner : $this->members[$actor];
    $members = $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}/members")->assertOk()->json('data');

    expect($members)->toHaveCount(3)
        ->and(collect($members)->pluck('role')->sort()->values()->all())->toBe(['contributor', 'contributor', 'manager'])
        ->and(array_key_exists('email', $members[0]))->toBe($withEmail);
})->with([['owner', true], ['admin', true], ['member', false], ['viewer', false]]);

it('ends private access as soon as the project membership is removed (DB-T08)', function () {
    $project = Project::factory()->for($this->team)->private()->create();
    $member = $this->members['member'];
    addToProject($project, $member);

    $this->actingAs($member)->getJson("/api/v1/projects/{$project->id}")->assertOk();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertNoContent();

    $this->actingAs($member)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    $this->actingAs($member)->getJson("/api/v1/projects/{$project->id}/members")->assertNotFound();
    $this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertJsonCount(0, 'data');
});

// --- Team changes cascade into projects (FR-TEAM-006, ADR-018 §6) -----------------------------------------

it('revokes every project membership in the team when a member is removed or leaves', function (string $how) {
    $member = $this->members['member'];
    $private = Project::factory()->for($this->team)->private()->create();
    $public = Project::factory()->for($this->team)->create();
    $trashed = Project::factory()->for($this->team)->private()->create();
    addToProject($private, $member, ProjectRole::Manager);
    addToProject($public, $member);
    addToProject($trashed, $member);
    $trashed->delete();

    // Their membership in another team's project is untouched.
    TeamMembership::factory()->for($this->otherTeam)->role(TeamRole::Member)->create(['user_id' => $member->id]);
    $elsewhere = Project::factory()->for($this->otherTeam)->private()->create();
    addToProject($elsewhere, $member);

    $actor = $how === 'removed' ? $this->owner : $member;
    $this->actingAs($actor)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$member->id}")->assertNoContent();

    expect(ProjectMembership::query()->where('team_id', $this->team->id)->where('user_id', $member->id)->count())->toBe(0)
        ->and(ProjectMembership::query()->where('user_id', $member->id)->pluck('project_id')->all())->toBe([$elsewhere->id]);

    $this->actingAs($member)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();
    $this->actingAs($member)->getJson("/api/v1/projects/{$public->id}")->assertNotFound();
    $this->actingAs($member)->getJson("/api/v1/projects/{$elsewhere->id}")->assertOk();

    // Re-joining the team does not bring private access back.
    TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create(['user_id' => $member->id]);
    $this->actingAs($member)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();
})->with(['removed', 'leaves']);

it('applies a team role change to every project on the next request', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Ops']);
    $admin = $this->members['admin'];
    addToProject($project, $admin, ProjectRole::Manager);

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$admin->id}", ['role' => 'viewer'])->assertOk();

    // Now a Viewer: still sees the project, can no longer change anything despite the manager role.
    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.role', 'manager');
    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Mine'], ['If-Match' => '"1"'])->assertForbidden();

    expect($project->refresh()->name)->toBe('Ops');
});

it('moves implicit private access with ownership', function () {
    $private = Project::factory()->for($this->team)->private()->create();
    $newOwner = $this->members['member'];

    $this->actingAs($newOwner)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $newOwner->id])
        ->assertOk();
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    $this->actingAs($newOwner)->getJson("/api/v1/projects/{$private->id}")->assertOk();
    // The previous Owner is now an Admin without a membership row.
    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();
});

// --- Archived team or project: read-only (423) -------------------------------------------------------------

it('refuses every project write in an archived team (423) but still serves reads', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Ops']);
    $member = $this->members['member'];
    addToProject($project, $member);
    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'New', 'key' => 'NEW'])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ['If-Match' => '"1"'])->assertStatus(423);
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['role' => 'manager'])->assertStatus(423);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertStatus(423);

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$project->id}")->assertOk();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertJsonCount(1, 'data');

    expect(Project::query()->count())->toBe(1)
        ->and($project->refresh())->name->toBe('Ops')->version->toBe(1)
        ->and(projectRolesOf($project))->toBe([$member->id => 'contributor']);
});

it('refuses every write to an archived project (423)', function () {
    $project = Project::factory()->for($this->team)->archived()->create(['name' => 'Ops']);
    $member = $this->members['member'];
    addToProject($project, $member);

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ['If-Match' => '"1"'])->assertStatus(423);
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['role' => 'manager'])->assertStatus(423);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertStatus(423);

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.archived_at', fn ($at) => is_string($at));

    expect($project->refresh()->name)->toBe('Ops')
        ->and(projectRolesOf($project))->toBe([$member->id => 'contributor']);
});

// --- Re-checks under the lock ---------------------------------------------------------------------------

it('re-checks the actor on the locked rows, so a demoted manager cannot act on a stale check', function () {
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Payroll']);
    $manager = $this->members['member'];
    addToProject($project, $manager, ProjectRole::Manager);
    $stale = app(AccessibleProjects::class)->findOrFail($manager, $project->id);

    // The demotion commits after the manager's request passed its policy check.
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$manager->id}", ['role' => 'contributor'])->assertOk();

    expect(fn () => app(SetProjectMember::class)->handle($stale, $manager, $this->members['viewer']->id, ProjectRole::Manager))
        ->toThrow(DomainConflictException::class)
        ->and(fn () => app(UpdateProject::class)->handle($stale, $manager, 1, ['visibility' => 'team']))
        ->toThrow(DomainConflictException::class)
        ->and(projectRolesOf($project))->toBe([$manager->id => 'contributor'])
        ->and($project->refresh()->isPrivate())->toBeTrue();
});

it('re-checks the creator on the locked team row, so a demoted Admin cannot create a project', function () {
    $admin = $this->members['admin'];

    // The demotion commits after the Admin's create request passed its policy check.
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$admin->id}", ['role' => 'member'])->assertOk();

    expect(fn () => app(CreateProject::class)->handle($this->team, $admin, 'Late', 'LATE'))
        ->toThrow(DomainConflictException::class)
        ->and(Project::query()->count())->toBe(0);
});

it('audits membership changes in the activity log, and logs no project names', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Secret merger']);
    $member = $this->members['member'];

    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['role' => 'contributor'])->assertCreated();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertNoContent();

    expect(TaskActivity::query()->where('project_id', $project->id)->where('action', 'project.member_removed')->get()
        ->map(fn (TaskActivity $entry) => [$entry->team_id, $entry->actor_user_id, $entry->changes])->all())
        ->toEqual([[$this->team->id, $this->owner->id, ['role' => 'contributor', 'user_id' => $member->id]]]);
    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []) => str_contains(json_encode($context), 'Secret merger'));
});

// --- Review follow-ups ------------------------------------------------------------------------------

it('lets a Team Admin manage a team-visible project\'s settings but not its membership (ADR-020 §5)', function () {
    $admin = $this->members['admin'];
    $project = Project::factory()->for($this->team)->create(['name' => 'Ops']);

    // Pre-adding themselves would keep their access after the project is made private.
    $this->actingAs($admin)->putJson("/api/v1/projects/{$project->id}/members/{$admin->id}", ['role' => 'manager'])
        ->assertForbidden();
    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])
        ->assertOk();
    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();

    expect(projectRolesOf($project))->toBe([]);
});

it('answers 404, not 409, when the project became invisible while the request waited', function () {
    $admin = $this->members['admin'];
    $project = Project::factory()->for($this->team)->create(['name' => 'Ops']);
    $stale = app(AccessibleProjects::class)->findOrFail($admin, $project->id);

    // The Owner makes the project private after the Admin's request passed its policy check.
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['visibility' => 'private'], ['If-Match' => '"1"'])->assertOk();

    expect(fn () => app(UpdateProject::class)->handle($stale, $admin, 2, ['name' => 'Renamed']))
        ->toThrow(ModelNotFoundException::class)
        ->and($project->refresh()->name)->toBe('Ops');
});

it('answers 423 before 412 for an archived project with a stale version', function () {
    $project = Project::factory()->for($this->team)->archived()->create();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ['If-Match' => '"7"'])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'resource_locked');
});

it('lists projects with a constant number of queries', function () {
    $admin = $this->members['admin'];
    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson("/api/v1/teams/{$this->team->id}/projects?limit=100")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Project::factory()->for($this->team)->count(2)->create();
    $few = $countQueries();

    Project::factory()->for($this->team)->count(20)->create();

    expect($countQueries())->toBe($few);
});

it('answers a taken key with a generic 409 that names no project (ADR-020 §10)', function () {
    $private = Project::factory()->for($this->team)->private()->create(['name' => 'Secret merger', 'project_key' => 'SECRET']);
    $admin = $this->members['admin'];

    $response = $this->actingAs($admin)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Mine', 'key' => 'SECRET'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.fields', null);

    expect($response->getContent())->not->toContain($private->id)
        ->not->toContain('Secret merger')
        ->not->toContain('private');
});

it('audits key conflicts without naming the project that holds the key', function () {
    Project::factory()->for($this->team)->private()->create(['project_key' => 'SECRET']);
    $admin = $this->members['admin'];
    Log::spy();

    $this->actingAs($admin)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Mine', 'key' => 'SECRET'])
        ->assertStatus(409);

    Log::shouldHaveReceived('warning')->with('project.key_conflict', ['team_id' => $this->team->id, 'actor_id' => $admin->id])->once();
});

it('rate-limits project create and update per user to slow key probing', function () {
    config(['sn3.rate_limits.project_writes_per_minute' => 3]);
    $admin = $this->members['admin'];

    foreach (['AAA', 'BBB', 'CCC'] as $key) {
        $this->actingAs($admin)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => $key, 'key' => $key])->assertCreated();
    }

    $this->actingAs($admin)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'DDD', 'key' => 'DDD'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');

    $project = Project::query()->where('project_key', 'AAA')->firstOrFail();
    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['key' => 'ZZZ'], ['If-Match' => '"1"'])->assertStatus(429);

    // Reads and other users are unaffected.
    $this->actingAs($admin)->getJson("/api/v1/projects/{$project->id}")->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Own', 'key' => 'OWN'])->assertCreated();
});

it('audits key conflicts on update too', function () {
    Project::factory()->for($this->team)->private()->create(['project_key' => 'SECRET']);
    $project = Project::factory()->for($this->team)->create(['project_key' => 'OPEN']);
    $admin = $this->members['admin'];
    Log::spy();

    $this->actingAs($admin)->patchJson("/api/v1/projects/{$project->id}", ['key' => 'SECRET'], ['If-Match' => '"1"'])
        ->assertStatus(409);

    Log::shouldHaveReceived('warning')->with('project.key_conflict', ['team_id' => $this->team->id, 'actor_id' => $admin->id])->once();
    expect($project->refresh()->project_key)->toBe('OPEN');
});

it('supports scalar queries such as pluck on the visibility query', function () {
    $visible = Project::factory()->for($this->team)->create();
    Project::factory()->for($this->team)->private()->create();

    expect(app(AccessibleProjects::class)->inTeam($this->team, $this->members['admin'])->pluck('projects.id')->all())
        ->toBe([$visible->id]);
});

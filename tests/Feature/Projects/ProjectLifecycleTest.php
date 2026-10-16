<?php

use App\Domain\Projects\Actions\ArchiveProject;
use App\Domain\Projects\Actions\RestoreDeletedProject;
use App\Domain\Projects\Actions\TrashProject;
use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// CORE-010: project archive, unarchive, 30-day trash and recovery (API_SPEC.md §4, FR-PROJ-008,
// DECIDED-011, ADR-022). Owner or Project Manager; archived projects are read-only and readable;
// trashed projects vanish from every path and come back intact within 30 days; private projects stay
// hidden from non-members in the trash too.
// TEST-FR-PROJ-008; acceptance scenarios AUTH-02 (trash), AUTH-04, DEL-01; DATABASE.md §16 DB-T01, DB-T02.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Website']);
    $this->owner = $this->team->owner;
    $this->otherTeam = Team::factory()->create();
    $this->outsider = $this->otherTeam->owner;
});

function lifecycleMember(Team $team, TeamRole $role): User
{
    return TeamMembership::factory()->for($team)->role($role)->create()->user;
}

function lifecycleProjectRole(Project $project, User $user, ProjectRole $role): ProjectMembership
{
    return ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

function ifMatch(int $version): array
{
    return ['If-Match' => "\"{$version}\""];
}

/**
 * [team role, visibility, explicit project role, can view, may archive / delete / recover]
 *
 * The lifecycle follows API_SPEC.md §4 and DECIDED-011, "Owner or Project Manager", like project
 * membership: the Team Admin's settings exception for team-visible projects does not apply, and a
 * Team Viewer stays read-only even as a manager (ADR-022 §3).
 */
dataset('lifecycle matrix', [
    'Owner, team, no membership' => ['owner', 'team', null, true, true],
    'Owner, private, no membership' => ['owner', 'private', null, true, true],
    'Owner, private, contributor' => ['owner', 'private', 'contributor', true, true],
    'Admin, team, no membership' => ['admin', 'team', null, true, false],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true],
    'Admin, team, contributor' => ['admin', 'team', 'contributor', true, false],
    'Admin, private, no membership' => ['admin', 'private', null, false, false],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, false],
    'Member, team, no membership' => ['member', 'team', null, true, false],
    'Member, team, manager' => ['member', 'team', 'manager', true, true],
    'Member, team, contributor' => ['member', 'team', 'contributor', true, false],
    'Member, private, no membership' => ['member', 'private', null, false, false],
    'Member, private, manager' => ['member', 'private', 'manager', true, true],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, false],
    'Viewer, team, no membership' => ['viewer', 'team', null, true, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false],
    'Viewer, private, no membership' => ['viewer', 'private', null, false, false],
    'other team, team' => ['outsider', 'team', null, false, false],
    'other team, private' => ['outsider', 'private', null, false, false],
]);

it('applies the lifecycle permission matrix to archive, unarchive, delete and restore-deleted', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $allowed) {
    $project = Project::factory()->for($this->team)->create(['name' => 'Payroll revamp', 'visibility' => $visibility]);
    $actor = match ($role) {
        'owner' => $this->owner,
        'outsider' => $this->outsider,
        default => lifecycleMember($this->team, TeamRole::from($role)),
    };

    if ($projectRole !== null) {
        lifecycleProjectRole($project, $actor, ProjectRole::from($projectRole));
    }

    // Hidden is 404 (never 403); visible but not allowed is 403.
    $deniedStatus = $view ? 403 : 404;

    $archive = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/archive", [], ifMatch(1));
    $allowed ? $archive->assertOk()->assertJsonPath('data.version', 2) : $archive->assertStatus($deniedStatus);
    expect($project->fresh()->isArchived())->toBe($allowed);
    expect($archive->getContent() !== false && str_contains($archive->getContent(), 'Payroll revamp'))->toBe($allowed);

    if (! $allowed) {
        $project->forceFill(['archived_at' => now(), 'version' => 2])->save();
    }

    $unarchive = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/restore", [], ifMatch(2));
    $allowed ? $unarchive->assertOk()->assertJsonPath('data.archived_at', null)->assertJsonPath('data.version', 3) : $unarchive->assertStatus($deniedStatus);
    expect($project->fresh()->isArchived())->toBe(! $allowed);

    if (! $allowed) {
        $project->forceFill(['archived_at' => null, 'version' => 3])->save();
    }

    $delete = $this->actingAs($actor)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(3));
    $allowed ? $delete->assertNoContent() : $delete->assertStatus($deniedStatus);
    expect(Project::withTrashed()->findOrFail($project->id)->trashed())->toBe($allowed);

    if (! $allowed) {
        $project->forceFill(['deleted_at' => now(), 'version' => 4])->save();
    }

    // In the trash, everyone who may not recover the project gets 404, even those who could see it.
    $restore = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/restore-deleted");
    $allowed ? $restore->assertOk()->assertJsonPath('data.id', $project->id)->assertJsonPath('data.role', $projectRole)->assertJsonPath('data.version', 5) : $restore->assertNotFound()->assertJsonPath('error.code', 'not_found');
    expect(str_contains((string) $restore->getContent(), 'Payroll revamp'))->toBe($allowed)
        ->and(Project::withTrashed()->findOrFail($project->id)->trashed())->toBe(! $allowed);
})->with('lifecycle matrix');

it('authorises before validating, so a caller without the right gets 403 whatever the body (AUTH-04)', function (array $body) {
    $project = Project::factory()->for($this->team)->create();
    $member = lifecycleMember($this->team, TeamRole::Member);

    $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/archive", $body, ifMatch(1))->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/v1/projects/{$project->id}", $body, ifMatch(1))->assertForbidden();

    expect($project->fresh()->version)->toBe(1);
})->with([
    'no body' => [[]],
    'junk body' => [['junk' => 1]],
]);

it('rejects body fields on lifecycle requests', function (string $method, string $path) {
    $project = Project::factory()->for($this->team)->create();
    $project->forceFill(['deleted_at' => $path === '/restore-deleted' ? now() : null])->save();

    $this->actingAs($this->owner)->json($method, "/api/v1/projects/{$project->id}{$path}", ['name' => 'x'], ifMatch(1))
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.name.0', 'unknown_field');

    expect($project->fresh()->version)->toBe(1);
})->with([
    'archive' => ['POST', '/archive'],
    'unarchive' => ['POST', '/restore'],
    'delete' => ['DELETE', ''],
    'restore-deleted' => ['POST', '/restore-deleted'],
]);

it('answers 404 for random and malformed project IDs', function (string $id) {
    foreach (['archive', 'restore', 'restore-deleted'] as $action) {
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$id}/{$action}", [], ifMatch(1))->assertNotFound();
    }

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$id}", [], ifMatch(1))->assertNotFound();
})->with([(string) Str::uuid7(), 'not-a-uuid']);

// --- Archive ----------------------------------------------------------------------------------------

it('archives a project read-only, keeps it readable and listed, and unarchives it (FR-PROJ-008)', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->create(['name' => 'Roadmap']);
    $member = lifecycleMember($this->team, TeamRole::Member);
    lifecycleProjectRole($project, $member, ProjectRole::Manager);
    $contributor = lifecycleMember($this->team, TeamRole::Member);

    $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/archive", [], ifMatch(1))
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn ($at) => is_string($at))
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.role', 'manager');

    // Read-only for every writer, readable for everyone who could see it.
    $this->actingAs($member)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ifMatch(2))->assertStatus(423);
    $this->actingAs($member)->putJson("/api/v1/projects/{$project->id}/members/{$contributor->id}", ['role' => 'contributor'])->assertStatus(423);
    $this->actingAs($member)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(2))->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');

    $this->actingAs($contributor)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.name', 'Roadmap');
    $this->actingAs($contributor)->getJson("/api/v1/projects/{$project->id}/members")->assertOk();
    expect(collect($this->actingAs($contributor)->getJson("/api/v1/teams/{$this->team->id}/projects")->json('data'))->pluck('id')->all())->toContain($project->id);

    // Archiving again is a no-op that keeps the version.
    $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/archive", [], ifMatch(2))->assertOk()->assertJsonPath('data.version', 2);

    $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/restore", [], ifMatch(2))
        ->assertOk()
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.version', 3);
    $this->actingAs($member)->postJson("/api/v1/projects/{$project->id}/restore", [], ifMatch(3))->assertOk()->assertJsonPath('data.version', 3);

    $this->actingAs($member)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Roadmap 2'], ifMatch(3))->assertOk();

    $context = ['team_id' => $this->team->id, 'project_id' => $project->id, 'actor_id' => $member->id];
    Log::shouldHaveReceived('info')->with('project.archived', $context)->once();
    Log::shouldHaveReceived('info')->with('project.unarchived', $context)->once();
});

it('requires If-Match for archive, unarchive and delete: 428 without, 412 when stale, with no change', function (string $method, string $path, bool $archived) {
    $project = Project::factory()->for($this->team)->create(['archived_at' => $archived ? now() : null]);
    $project->forceFill(['version' => 4])->save();

    $this->actingAs($this->owner)->json($method, "/api/v1/projects/{$project->id}{$path}")
        ->assertStatus(428)
        ->assertJsonPath('error.code', 'precondition_required');

    $this->actingAs($this->owner)->json($method, "/api/v1/projects/{$project->id}{$path}", [], ifMatch(3))
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'version_mismatch');

    $fresh = Project::withTrashed()->findOrFail($project->id);
    expect($fresh->version)->toBe(4)
        ->and($fresh->isArchived())->toBe($archived)
        ->and($fresh->trashed())->toBeFalse();
})->with([
    'archive' => ['POST', '/archive', false],
    'unarchive' => ['POST', '/restore', true],
    'delete' => ['DELETE', '', false],
]);

it('answers 423 before 412 when deleting an archived project with a stale version', function () {
    $project = Project::factory()->for($this->team)->archived()->create();

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(9))->assertStatus(423);
});

it('refuses every lifecycle change to a project of an archived team (423)', function () {
    $project = Project::factory()->for($this->team)->create();
    $archived = Project::factory()->for($this->team)->archived()->create();
    $trashed = Project::factory()->for($this->team)->create();
    $trashed->forceFill(['deleted_at' => now()])->save();
    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/archive", [], ifMatch(1))->assertStatus(423);
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$archived->id}/restore", [], ifMatch(1))->assertStatus(423);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(1))->assertStatus(423);
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$trashed->id}/restore-deleted")->assertStatus(423);

    expect($project->fresh()->isArchived())->toBeFalse()
        ->and($archived->fresh()->isArchived())->toBeTrue()
        ->and($project->fresh()->trashed())->toBeFalse()
        ->and($trashed->fresh()->trashed())->toBeTrue();
});

it('re-checks access on the locked rows: 404 when the project became hidden, 409 when the right was lost', function () {
    $project = Project::factory()->for($this->team)->private()->create();
    $manager = lifecycleMember($this->team, TeamRole::Member);
    $membership = lifecycleProjectRole($project, $manager, ProjectRole::Manager);
    $resolved = Project::query()->findOrFail($project->id);

    // Demoted to contributor while the request waited: still sees the project, lost the right.
    $membership->forceFill(['project_role' => ProjectRole::Contributor])->save();
    expect(fn () => app(ArchiveProject::class)->handle($resolved, $manager, 1, true))->toThrow(DomainConflictException::class)
        ->and(fn () => app(TrashProject::class)->handle($resolved, $manager, 1))->toThrow(DomainConflictException::class);

    // Removed from the private project: it is hidden now.
    $membership->delete();
    expect(fn () => app(ArchiveProject::class)->handle($resolved, $manager, 1, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(TrashProject::class)->handle($resolved, $manager, 1))->toThrow(ModelNotFoundException::class);

    expect($project->fresh()->version)->toBe(1)->and($project->fresh()->trashed())->toBeFalse();
});

// --- Trash ------------------------------------------------------------------------------------------

it('moves a project to the trash, hiding it from every read and write path but deleting nothing', function (string $visibility) {
    Log::spy();
    $project = Project::factory()->for($this->team)->create(['visibility' => $visibility]);
    $member = lifecycleMember($this->team, TeamRole::Member);
    lifecycleProjectRole($project, $member, ProjectRole::Contributor);
    $newcomer = lifecycleMember($this->team, TeamRole::Member);

    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(1))->assertNoContent();

    foreach ([$this->owner, $member] as $user) {
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->id}/members")->assertNotFound();
        expect(collect($this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertOk()->json('data'))->pluck('id')->all())->not->toContain($project->id);
    }

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ifMatch(2))->assertNotFound();
    $this->actingAs($this->owner)->putJson("/api/v1/projects/{$project->id}/members/{$newcomer->id}", ['role' => 'contributor'])->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertNotFound();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/archive", [], ifMatch(2))->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(2))->assertNotFound();

    $trashed = Project::withTrashed()->findOrFail($project->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->version)->toBe(2)
        ->and(ProjectMembership::query()->where('project_id', $project->id)->count())->toBe(1);

    Log::shouldHaveReceived('info')->with('project.trashed', ['team_id' => $this->team->id, 'project_id' => $project->id, 'actor_id' => $this->owner->id])->once();
})->with(['team', 'private']);

it('keeps a trashed project\'s key reserved in its team', function () {
    $project = Project::factory()->for($this->team)->create(['project_key' => 'WEB']);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(1))->assertNoContent();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Again', 'key' => 'WEB'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    $other = Project::factory()->for($this->team)->create(['project_key' => 'OPS']);
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$other->id}", ['key' => 'WEB'], ifMatch(1))->assertStatus(409);
});

// --- Recovery (restore-deleted) ---------------------------------------------------------------------

it('recovers a trashed project with its memberships within 30 days (DEL-01)', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Hiring']);
    $manager = lifecycleMember($this->team, TeamRole::Member);
    lifecycleProjectRole($project, $manager, ProjectRole::Manager);
    $contributor = lifecycleMember($this->team, TeamRole::Member);
    lifecycleProjectRole($project, $contributor, ProjectRole::Contributor);

    $this->actingAs($manager)->deleteJson("/api/v1/projects/{$project->id}", [], ifMatch(1))->assertNoContent();
    $this->travel(29)->days();

    $this->actingAs($manager)->postJson("/api/v1/projects/{$project->id}/restore-deleted")
        ->assertOk()
        ->assertJsonPath('data.name', 'Hiring')
        ->assertJsonPath('data.visibility', 'private')
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.version', 3);

    $this->actingAs($contributor)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.role', 'contributor');
    expect(ProjectMembership::query()->where('project_id', $project->id)->count())->toBe(2);

    // A representation from before the deletion is stale now.
    $this->actingAs($manager)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ifMatch(1))->assertStatus(412);

    // Live again, so the trash no longer has it.
    $this->actingAs($manager)->postJson("/api/v1/projects/{$project->id}/restore-deleted")->assertNotFound();

    Log::shouldHaveReceived('info')->with('project.restored_from_trash', ['team_id' => $this->team->id, 'project_id' => $project->id, 'actor_id' => $manager->id])->once();
});

it('keeps a trashed private project hidden from non-members, Team Admins included (DB-T02)', function () {
    $project = Project::factory()->for($this->team)->private()->create(['name' => 'Secret merger']);
    $admin = lifecycleMember($this->team, TeamRole::Admin);
    $project->forceFill(['deleted_at' => now()])->save();

    $response = $this->actingAs($admin)->postJson("/api/v1/projects/{$project->id}/restore-deleted")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect((string) $response->getContent())->not->toContain('Secret merger')->not->toContain($project->id);

    // The Owner keeps access to private projects, in the trash too.
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/restore-deleted")->assertOk();
});

it('only recovers within 30 days of the deletion', function (int $secondsAgo, bool $recoverable) {
    $this->freezeTime();
    $project = Project::factory()->for($this->team)->create();
    $project->forceFill(['deleted_at' => now()->subSeconds($secondsAgo)])->save();

    $response = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/restore-deleted");

    $recoverable ? $response->assertOk() : $response->assertNotFound();
    expect(Project::withTrashed()->findOrFail($project->id)->trashed())->toBe(! $recoverable);
})->with([
    'one minute ago' => [60, true],
    'one second before the 30 days' => [30 * 86400 - 1, true],
    'exactly 30 days ago' => [30 * 86400, false],
    'a year ago, not yet purged' => [365 * 86400, false],
]);

it('cannot recover a project on its own while its team is in the trash; recovering the team comes first', function () {
    $project = Project::factory()->for($this->team)->create();
    $project->forceFill(['deleted_at' => now()])->save();
    $this->team->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/restore-deleted")->assertNotFound();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$project->id}/restore-deleted")->assertOk();
});

it('keeps two teams apart in the trash (DB-T01)', function () {
    $theirs = Project::factory()->for($this->otherTeam)->create();
    $theirs->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($this->owner)->postJson("/api/v1/projects/{$theirs->id}/restore-deleted")->assertNotFound();
    expect($theirs->fresh()->trashed())->toBeTrue();
});

it('re-checks the window and the right to recover under the lock', function () {
    $project = Project::factory()->for($this->team)->create();
    $manager = lifecycleMember($this->team, TeamRole::Member);
    $membership = lifecycleProjectRole($project, $manager, ProjectRole::Manager);
    $project->forceFill(['deleted_at' => now()->subDay()])->save();
    $resolved = Project::withTrashed()->findOrFail($project->id);

    $membership->forceFill(['project_role' => ProjectRole::Contributor])->save();
    expect(fn () => app(RestoreDeletedProject::class)->handle($resolved, $manager))->toThrow(ModelNotFoundException::class);

    $project->forceFill(['deleted_at' => now()->subDays(31)])->save();
    expect(fn () => app(RestoreDeletedProject::class)->handle($resolved, $this->owner))->toThrow(ModelNotFoundException::class)
        ->and(Project::withTrashed()->findOrFail($project->id)->trashed())->toBeTrue();
});

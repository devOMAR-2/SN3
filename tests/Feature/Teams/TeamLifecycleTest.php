<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\Actions\ArchiveTeam;
use App\Domain\Teams\Actions\RestoreDeletedTeam;
use App\Domain\Teams\Actions\TrashTeam;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// CORE-010: team archive, unarchive, 30-day trash and recovery (API_SPEC.md §3, DECIDED-011,
// ADR-022). Owner only; an archived team is read-only with its projects; a trashed team disappears
// from every read and write path and comes back intact within 30 days.
// TEST-FR-PROJ-008 (team side), acceptance scenarios AUTH-04, DEL-01 (team); DATABASE.md §16 DB-T01.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Website', 'slug' => 'website']);
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->otherTeam = Team::factory()->create();
    $this->outsider = $this->otherTeam->owner;
});

function trashTeamAt(Team $team, DateTimeInterface $at): void
{
    $team->forceFill(['deleted_at' => $at])->save();
}

dataset('non-owner team roles', ['admin', 'member', 'viewer']);

// --- Archive and unarchive --------------------------------------------------------------------------

it('lets the Owner archive and unarchive the team, idempotently', function () {
    Log::spy();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.id', $this->team->id)
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.archived_at', fn ($at) => is_string($at));

    $archivedAt = $this->team->fresh()->archived_at;
    expect($archivedAt)->not->toBeNull();

    // Archiving again changes nothing.
    $this->travel(5)->minutes();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/archive")->assertOk();
    expect($this->team->fresh()->archived_at->equalTo($archivedAt))->toBeTrue();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived_at', null);
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore")->assertOk();

    expect($this->team->fresh()->archived_at)->toBeNull();

    $context = ['team_id' => $this->team->id, 'actor_id' => $this->owner->id];
    Log::shouldHaveReceived('info')->with('team.archived', $context)->once();
    Log::shouldHaveReceived('info')->with('team.unarchived', $context)->once();
});

it('denies archive, unarchive and deletion to everyone but the Owner, whatever the body (AUTH-04)', function (string $role, array $body) {
    $actor = $this->members[$role];

    $this->actingAs($actor)->postJson("/api/v1/teams/{$this->team->id}/archive", $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $this->actingAs($actor)->deleteJson("/api/v1/teams/{$this->team->id}", $body)->assertForbidden();

    expect($this->team->fresh()->archived_at)->toBeNull()->and($this->team->fresh()->trashed())->toBeFalse();

    $this->team->forceFill(['archived_at' => now()])->save();
    $this->actingAs($actor)->postJson("/api/v1/teams/{$this->team->id}/restore", $body)->assertForbidden();

    expect($this->team->fresh()->archived_at)->not->toBeNull();
})->with('non-owner team roles')->with([
    'no body' => [[]],
    'junk body' => [['junk' => 1]],
]);

it('answers 404 to non-members and for random or malformed team IDs', function (string $id) {
    $id = $id === 'team' ? $this->team->id : $id;

    foreach (['archive', 'restore', 'restore-deleted'] as $action) {
        $this->actingAs($this->outsider)->postJson("/api/v1/teams/{$id}/{$action}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    $this->actingAs($this->outsider)->deleteJson("/api/v1/teams/{$id}")->assertNotFound();

    expect($this->team->fresh()->archived_at)->toBeNull()->and($this->team->fresh()->trashed())->toBeFalse();
})->with(['team', (string) Str::uuid7(), 'not-a-uuid']);

it('requires authentication', function () {
    $this->postJson("/api/v1/teams/{$this->team->id}/archive")->assertUnauthorized();
    $this->deleteJson("/api/v1/teams/{$this->team->id}")->assertUnauthorized();
    $this->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")->assertUnauthorized();
});

it('rejects body fields on lifecycle requests', function (string $method, string $path) {
    $this->actingAs($this->owner)->json($method, "/api/v1/teams/{$this->team->id}{$path}", ['name' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.name.0', 'unknown_field');

    expect($this->team->fresh()->archived_at)->toBeNull()->and($this->team->fresh()->trashed())->toBeFalse();
})->with([
    'archive' => ['POST', '/archive'],
    'delete' => ['DELETE', ''],
]);

it('makes an archived team and all its projects read-only while every read keeps working', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Roadmap']);
    $private = Project::factory()->for($this->team)->private()->create();
    $member = $this->members['member'];
    ProjectMembership::factory()->for($private)->member($member)->role(ProjectRole::Manager)->create();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/archive")->assertOk();

    // Writes: team, its members and every project in it (including what a Project Manager could do).
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'X'])->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'New', 'key' => 'NEW'])->assertStatus(423);
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'], ['If-Match' => '"1"'])->assertStatus(423);
    $this->actingAs($member)->postJson("/api/v1/projects/{$private->id}/archive", [], ['If-Match' => '"1"'])->assertStatus(423);
    $this->actingAs($this->owner)->deleteJson("/api/v1/projects/{$project->id}", [], ['If-Match' => '"1"'])->assertStatus(423);
    $this->actingAs($member)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$member->id}")->assertStatus(423);

    // Reads.
    $this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}")->assertOk()->assertJsonPath('data.archived_at', fn ($at) => is_string($at));
    $this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}/members")->assertOk();
    expect(collect($this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertOk()->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$project->id, $private->id])->sort()->values()->all());
    $this->actingAs($member)->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.name', 'Roadmap');

    expect(Project::query()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and($project->fresh()->name)->toBe('Roadmap');
});

it('leaves each project\'s own archive state alone, so unarchiving the team restores it as it was', function () {
    $active = Project::factory()->for($this->team)->create();
    $archived = Project::factory()->for($this->team)->archived()->create();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/archive")->assertOk();

    expect($active->fresh()->archived_at)->toBeNull()
        ->and($active->fresh()->version)->toBe(1);

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore")->assertOk();

    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$active->id}", ['name' => 'Writable again'], ['If-Match' => '"1"'])->assertOk();
    $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$archived->id}", ['name' => 'Still locked'], ['If-Match' => '"1"'])->assertStatus(423);
});

it('re-checks ownership on the locked team row', function () {
    $admin = $this->members['admin'];

    // A former Owner (after a transfer) still sees the team but lost the right: 409.
    $this->team->memberships()->where('user_id', $this->owner->id)->update(['role' => TeamRole::Admin->value]);
    $this->team->memberships()->where('user_id', $admin->id)->update(['role' => TeamRole::Owner->value]);
    $this->team->forceFill(['owner_user_id' => $admin->id])->save();

    expect(fn () => app(ArchiveTeam::class)->handle($this->team, $this->owner, true))->toThrow(DomainConflictException::class)
        ->and(fn () => app(TrashTeam::class)->handle($this->team, $this->owner))->toThrow(DomainConflictException::class);

    // Someone no longer in the team: 404.
    expect(fn () => app(ArchiveTeam::class)->handle($this->team, $this->outsider, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(TrashTeam::class)->handle($this->team, $this->outsider))->toThrow(ModelNotFoundException::class);

    expect($this->team->fresh()->archived_at)->toBeNull()->and($this->team->fresh()->trashed())->toBeFalse();
});

// --- Trash ------------------------------------------------------------------------------------------

it('moves the team to the trash, hiding it and all its projects from every read and write path', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->create();
    $private = Project::factory()->for($this->team)->private()->create();
    $member = $this->members['member'];
    ProjectMembership::factory()->for($private)->member($member)->role(ProjectRole::Manager)->create();

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    foreach ([$this->owner, $member] as $user) {
        expect(collect($this->actingAs($user)->getJson('/api/v1/teams')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($this->team->id);
        $this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}/members")->assertNotFound();
        $this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertNotFound();

        foreach ([$project, $private] as $p) {
            $this->actingAs($user)->getJson("/api/v1/projects/{$p->id}")->assertNotFound();
            $this->actingAs($user)->getJson("/api/v1/projects/{$p->id}/members")->assertNotFound();
            $this->actingAs($user)->patchJson("/api/v1/projects/{$p->id}", ['name' => 'X'], ['If-Match' => '"1"'])->assertNotFound();
        }
    }

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'X'])->assertNotFound();
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/archive")->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNotFound();

    // Nothing was deleted: the trash is recoverable.
    expect(Team::withTrashed()->find($this->team->id)?->trashed())->toBeTrue()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->count())->toBe(4)
        ->and(Project::query()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and(ProjectMembership::query()->where('team_id', $this->team->id)->count())->toBe(1);

    Log::shouldHaveReceived('info')->with('team.trashed', ['team_id' => $this->team->id, 'actor_id' => $this->owner->id])->once();
});

it('refuses to trash an archived team (423) until it is unarchived', function () {
    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertStatus(423)->assertJsonPath('error.code', 'resource_locked');
    expect($this->team->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore")->assertOk();
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();
});

it('keeps a trashed team\'s slug reserved', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    $this->actingAs($this->outsider)->postJson('/api/v1/teams', ['name' => 'Website', 'slug' => 'website'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    $this->actingAs($this->outsider)->patchJson("/api/v1/teams/{$this->otherTeam->id}", ['slug' => 'website'])->assertStatus(409);

    // A derived slug steps around it.
    $this->actingAs($this->outsider)->postJson('/api/v1/teams', ['name' => 'Website'])->assertCreated()->assertJsonPath('data.slug', fn ($slug) => $slug !== 'website');
});

// --- Recovery (restore-deleted) ---------------------------------------------------------------------

it('lets the Owner recover the team within 30 days, with every project, membership and state intact (DEL-01)', function () {
    Log::spy();
    $project = Project::factory()->for($this->team)->create();
    $archived = Project::factory()->for($this->team)->archived()->create();
    $private = Project::factory()->for($this->team)->private()->create();
    $member = $this->members['member'];
    ProjectMembership::factory()->for($private)->member($member)->create();
    $trashedBefore = Project::factory()->for($this->team)->create();
    $trashedBefore->forceFill(['deleted_at' => now()->subDays(3)])->save();

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();
    $this->travel(29)->days();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")
        ->assertOk()
        ->assertJsonPath('data.id', $this->team->id)
        ->assertJsonPath('data.slug', 'website')
        ->assertJsonPath('data.role', 'owner');

    expect($this->team->fresh()->trashed())->toBeFalse();
    expect(collect($this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertOk()->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$project->id, $archived->id, $private->id])->sort()->values()->all());
    $this->actingAs($member)->getJson("/api/v1/projects/{$private->id}")->assertOk()->assertJsonPath('data.role', 'contributor');
    $this->actingAs($member)->getJson("/api/v1/projects/{$archived->id}")->assertOk()->assertJsonPath('data.archived_at', fn ($at) => is_string($at));

    // A project trashed on its own stays in the trash.
    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$trashedBefore->id}")->assertNotFound();

    // Restoring twice: the team is live again, so the trash no longer has it.
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")->assertNotFound();

    Log::shouldHaveReceived('info')->with('team.restored_from_trash', ['team_id' => $this->team->id, 'actor_id' => $this->owner->id])->once();
});

it('hides a trashed team from everyone but its Owner (404, not 403)', function (string $role) {
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    $this->actingAs($this->members[$role])->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    expect($this->team->fresh()->trashed())->toBeTrue();
})->with('non-owner team roles');

it('only recovers within 30 days of the deletion', function (int $secondsAgo, bool $recoverable) {
    $this->freezeTime();
    trashTeamAt($this->team, now()->subSeconds($secondsAgo));

    $response = $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/restore-deleted");

    $recoverable ? $response->assertOk() : $response->assertNotFound();
    expect(Team::withTrashed()->findOrFail($this->team->id)->trashed())->toBe(! $recoverable);
})->with([
    'one minute ago' => [60, true],
    'one second before the 30 days' => [30 * 86400 - 1, true],
    'exactly 30 days ago' => [30 * 86400, false],
    'a year ago, not yet purged' => [365 * 86400, false],
]);

it('answers 404 for restore-deleted of a live team, a random ID or a malformed ID', function (string $id) {
    $id = $id === 'team' ? $this->team->id : $id;

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$id}/restore-deleted")->assertNotFound();
})->with(['team', (string) Str::uuid7(), 'not-a-uuid']);

it('re-checks the window and ownership under the lock when recovering', function () {
    trashTeamAt($this->team, now()->subDay());
    $trashed = Team::withTrashed()->findOrFail($this->team->id);

    expect(fn () => app(RestoreDeletedTeam::class)->handle($trashed, $this->members['admin']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RestoreDeletedTeam::class)->handle($trashed, $this->outsider))->toThrow(ModelNotFoundException::class);

    trashTeamAt($this->team, now()->subDays(31));

    expect(fn () => app(RestoreDeletedTeam::class)->handle($trashed, $this->owner))->toThrow(ModelNotFoundException::class)
        ->and(Team::withTrashed()->findOrFail($this->team->id)->trashed())->toBeTrue();
});

it('keeps two teams apart: one team\'s Owner cannot act on another team\'s trash (DB-T01)', function () {
    $this->actingAs($this->outsider)->deleteJson("/api/v1/teams/{$this->otherTeam->id}")->assertNoContent();
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}")->assertNoContent();

    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->otherTeam->id}/restore-deleted")->assertNotFound();
    $this->actingAs($this->outsider)->postJson("/api/v1/teams/{$this->team->id}/restore-deleted")->assertNotFound();

    $this->actingAs($this->outsider)->postJson("/api/v1/teams/{$this->otherTeam->id}/restore-deleted")->assertOk();
    expect(Team::withTrashed()->findOrFail($this->team->id)->trashed())->toBeTrue();
});

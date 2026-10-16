<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// FND-005: UUIDv7 identifiers and the team/membership constraints of DATABASE.md §3, enforced by
// PostgreSQL itself (direct SQL bypasses the application on purpose).

uses(RefreshDatabase::class);

/**
 * Runs $change in a savepoint and fires the deferred constraint triggers before it ends, exactly as
 * COMMIT would. (Each test runs inside a transaction that is never committed.)
 */
function withCommitChecks(Closure $change): void
{
    DB::transaction(function () use ($change): void {
        $change();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function insertMembership(Team $team, User $user, string $role): void
{
    DB::table('team_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => $role,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('generates UUIDv7 primary keys for users, teams and memberships', function () {
    $team = Team::factory()->create();
    $membership = $team->memberships()->sole();

    foreach ([$team->owner_user_id, $team->id, $membership->id] as $id) {
        expect(Uuid::fromString($id)->getFields()->getVersion())->toBe(7);
    }
});

it('creates a team together with exactly one owner membership', function () {
    $team = Team::factory()->create();

    $membership = $team->memberships()->sole();

    expect($membership->user_id)->toBe($team->owner_user_id)
        ->and($membership->role)->toBe(TeamRole::Owner)
        ->and($team->roleOf($team->owner))->toBe(TeamRole::Owner);

    withCommitChecks(fn () => null);
});

it('rejects a team that commits without an owner membership', function () {
    $owner = User::factory()->create();

    expect(fn () => withCommitChecks(fn () => DB::table('teams')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'Orphan',
        'slug' => 'orphan-team',
        'owner_user_id' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'must have exactly one owner membership');
});

it('rejects an owner membership that belongs to someone other than teams.owner_user_id', function () {
    $team = Team::factory()->create();
    $other = User::factory()->create();

    expect(fn () => withCommitChecks(fn () => DB::table('teams')->where('id', $team->id)->update(['owner_user_id' => $other->id])))
        ->toThrow(QueryException::class, 'must have exactly one owner membership');
});

it('rejects removing or demoting the owner membership', function (string $change) {
    $team = Team::factory()->create();
    $owner = DB::table('team_memberships')->where('team_id', $team->id)->where('role', 'owner');

    expect(fn () => withCommitChecks(fn () => $change === 'delete' ? $owner->delete() : $owner->update(['role' => 'admin'])))
        ->toThrow(QueryException::class, 'must have exactly one owner membership');
})->with(['delete', 'demote']);

it('rejects a second owner membership immediately', function () {
    $team = Team::factory()->create();

    expect(fn () => insertMembership($team, User::factory()->create(), 'owner'))
        ->toThrow(QueryException::class, 'team_memberships_one_owner_unique');
});

it('accepts an ownership swap made in one transaction, in any statement order', function () {
    $team = Team::factory()->create();
    $oldOwner = $team->owner;
    $newOwner = User::factory()->create();
    insertMembership($team, $newOwner, 'admin');

    withCommitChecks(function () use ($team, $oldOwner, $newOwner): void {
        DB::table('teams')->where('id', $team->id)->update(['owner_user_id' => $newOwner->id]);
        DB::table('team_memberships')->where('team_id', $team->id)->where('user_id', $oldOwner->id)->update(['role' => 'admin']);
        DB::table('team_memberships')->where('team_id', $team->id)->where('user_id', $newOwner->id)->update(['role' => 'owner']);
    });

    $team->refresh();

    expect($team->owner_user_id)->toBe($newOwner->id)
        ->and($team->roleOf($newOwner))->toBe(TeamRole::Owner)
        ->and($team->roleOf($oldOwner))->toBe(TeamRole::Admin);
});

it('rejects moving the owner membership to another team', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();

    expect(fn () => withCommitChecks(fn () => DB::table('team_memberships')
        ->where('team_id', $first->id)
        ->where('role', 'owner')
        ->update(['team_id' => $second->id, 'role' => 'member'])))
        ->toThrow(QueryException::class, 'must have exactly one owner membership');
});

it('allows one membership per user and team, with a known role', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    insertMembership($team, $user, 'member');

    expect(fn () => DB::transaction(fn () => insertMembership($team, $user, 'viewer')))
        ->toThrow(QueryException::class, 'team_memberships_team_id_user_id_unique')
        ->and(fn () => DB::transaction(fn () => insertMembership($team, User::factory()->create(), 'superuser')))
        ->toThrow(QueryException::class, 'team_memberships_role_check');
});

it('lets a user belong to several teams with different roles', function () {
    $user = User::factory()->create();
    $owned = Team::factory()->ownedBy($user)->create();
    $joined = Team::factory()->create();
    TeamMembership::factory()->for($joined)->for($user)->role(TeamRole::Viewer)->create();

    expect($user->teamMemberships()->count())->toBe(2)
        ->and($owned->roleOf($user))->toBe(TeamRole::Owner)
        ->and($joined->roleOf($user))->toBe(TeamRole::Viewer);

    withCommitChecks(fn () => null);
});

it('enforces the slug format', function (string $slug) {
    $team = Team::factory()->create(['slug' => 'taken-slug']);

    expect(fn () => DB::transaction(fn () => DB::table('teams')->where('id', $team->id)->update(['slug' => $slug])))
        ->toThrow(QueryException::class);
})->with(['ab', 'Upper-Case', 'has space', 'فريق', str_repeat('a', 41)]);

it('keeps slugs unique across teams', function () {
    Team::factory()->create(['slug' => 'taken-slug']);

    expect(fn () => Team::factory()->create(['slug' => 'taken-slug']))
        ->toThrow(QueryException::class, 'teams_slug_unique');
});

it('removes memberships when a team is purged, but never a user who still owns a team', function () {
    $team = Team::factory()->create();
    $member = TeamMembership::factory()->for($team)->create();

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $team->owner_user_id)->delete()))
        ->toThrow(QueryException::class);

    withCommitChecks(fn () => DB::table('teams')->where('id', $team->id)->delete());

    expect(TeamMembership::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($member->user_id)->exists())->toBeTrue();
});

it('treats user emails case-insensitively at the database level', function () {
    User::factory()->create(['email' => 'sara@example.com']);

    expect(fn () => DB::transaction(fn () => DB::table('users')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'Sara',
        'email' => 'SARA@Example.COM',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'users_email_unique')
        ->and(User::query()->where('email', 'Sara@EXAMPLE.com')->exists())->toBeTrue();
});

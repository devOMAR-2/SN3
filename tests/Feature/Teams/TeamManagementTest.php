<?php

use App\Domain\Teams\Actions\ChangeMemberRole;
use App\Domain\Teams\Actions\RemoveMember;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// CORE-001: team create/update, roles, member removal and ownership transfer.
// TEST-FR-TEAM-001 (create), -004 (fixed roles), -005 (transfer, last Owner), -006 (removal revokes
// access), -007 (server-side team boundaries); acceptance scenarios AUTH-04 and TEAM-01.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Website', 'slug' => 'website-team']);
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->outsider = Team::factory()->create()->owner;
});

function ownerMembershipsOf(Team $team): array
{
    return TeamMembership::query()->where('team_id', $team->id)->where('role', 'owner')->pluck('user_id')->all();
}

// --- Create (FR-TEAM-001) -----------------------------------------------------------------------

it('creates a team with the creator as its only Owner', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teams', ['name' => '  فريق التصميم  ', 'description' => 'واجهات سنع'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'فريق التصميم')
        ->assertJsonPath('data.description', 'واجهات سنع')
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.owner_user_id', $user->id);

    $team = Team::query()->findOrFail($response->json('data.id'));

    expect(ownerMembershipsOf($team))->toBe([$user->id])
        ->and($team->slug)->toMatch('/^[a-z0-9-]{3,40}$/');

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('derives a valid, unique slug when none is given', function (string $name) {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson('/api/v1/teams', ['name' => $name])->assertCreated()->json('data.slug');
    $second = $this->actingAs($user)->postJson('/api/v1/teams', ['name' => $name])->assertCreated()->json('data.slug');

    expect($first)->toMatch('/^[a-z0-9-]{3,40}$/')
        ->and($second)->toMatch('/^[a-z0-9-]{3,40}$/')
        ->and($second)->not->toBe($first);
})->with(['Website', 'فريق', 'A', '!!!', str_repeat('Long name ', 9)]);

it('keeps a requested slug, and answers 409 when it is taken (trashed teams included)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/teams', ['name' => 'Ops', 'slug' => 'ops-team'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'ops-team');

    $this->actingAs($user)->postJson('/api/v1/teams', ['name' => 'Ops 2', 'slug' => 'website-team'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    $this->team->delete();
    $this->actingAs($user)->postJson('/api/v1/teams', ['name' => 'Again', 'slug' => 'website-team'])->assertStatus(409);
});

it('validates the create body with stable codes', function (array $body, string $field, string $code) {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/teams', $body)
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'missing name' => [[], 'name', 'required'],
    'blank name' => [['name' => '   '], 'name', 'required'],
    'long name' => [['name' => str_repeat('a', 101)], 'name', 'max'],
    'bad slug' => [['name' => 'X', 'slug' => 'Not Valid'], 'slug', 'regex'],
    'long description' => [['name' => 'X', 'description' => str_repeat('a', 1001)], 'description', 'max'],
    'unknown field' => [['name' => 'X', 'owner_user_id' => 'someone'], 'owner_user_id', 'unknown_field'],
]);

it('requires a verified email to create a team', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->postJson('/api/v1/teams', ['name' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(Team::query()->count())->toBe(2);
});

it('requires authentication for every team mutation', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with(fn () => [
    ['POST', '/api/v1/teams'],
    ['PATCH', '/api/v1/teams/'.Str::uuid7()],
    ['GET', '/api/v1/teams/'.Str::uuid7().'/members'],
    ['DELETE', '/api/v1/teams/'.Str::uuid7().'/members/'.Str::uuid7()],
    ['POST', '/api/v1/teams/'.Str::uuid7().'/ownership-transfer'],
]);

// --- Update ---------------------------------------------------------------------------------------

it('lets the Owner and Admin update name, slug and description', function (string $actor) {
    $user = $actor === 'owner' ? $this->owner : $this->members[$actor];

    $this->actingAs($user)
        ->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'Web', 'slug' => 'web-squad', 'description' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Web')
        ->assertJsonPath('data.slug', 'web-squad');

    $this->actingAs($user)->patchJson("/api/v1/teams/{$this->team->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.name', 'Web');
})->with(['owner', 'admin']);

it('denies Members and Viewers any team change, with no mutation (AUTH-04)', function (string $actor) {
    $this->actingAs($this->members[$actor])
        ->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'Hijacked', 'slug' => 'bad slug!'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($this->team->refresh()->name)->toBe('Website');
})->with(['member', 'viewer']);

it('answers 409 for a taken slug and 423 for an archived team', function () {
    Team::factory()->create(['slug' => 'taken-slug']);

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}", ['slug' => 'taken-slug'])->assertStatus(409);

    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'Renamed'])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'resource_locked');
    expect($this->team->refresh()->name)->toBe('Website');
});

// --- Members and roles (FR-TEAM-004) ------------------------------------------------------------------

it('lists members, showing email addresses only to the Owner and Admin', function (string $actor, bool $withEmail) {
    $user = $actor === 'owner' ? $this->owner : $this->members[$actor];

    $members = $this->actingAs($user)->getJson("/api/v1/teams/{$this->team->id}/members")->assertOk()->json('data');

    expect($members)->toHaveCount(4)
        ->and(collect($members)->pluck('role')->sort()->values()->all())->toBe(['admin', 'member', 'owner', 'viewer'])
        ->and(array_key_exists('email', $members[0]))->toBe($withEmail);
})->with([['owner', true], ['admin', true], ['member', false], ['viewer', false]]);

it('lets only the Owner change roles, never to or from Owner', function () {
    $member = $this->members['member'];

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$member->id}", ['role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');
    expect($this->team->roleOf($member))->toBe(TeamRole::Admin);

    $this->actingAs($this->members['admin'])->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->members['viewer']->id}", ['role' => 'admin'])
        ->assertForbidden();
    $this->actingAs($this->members['viewer'])->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->members['viewer']->id}", ['role' => 'admin'])
        ->assertForbidden();
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->owner->id}", ['role' => 'member'])
        ->assertForbidden();
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$member->id}", ['role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.role.0', 'in');

    expect(ownerMembershipsOf($this->team))->toBe([$this->owner->id])
        ->and($this->team->roleOf($this->members['viewer']))->toBe(TeamRole::Viewer);
});

// --- Removal and leaving (FR-TEAM-006, TEAM-01) ---------------------------------------------------------

it('lets the Owner remove a member, whose access ends immediately', function () {
    $member = $this->members['member'];

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$member->id}")->assertNoContent();

    $this->actingAs($member)->getJson("/api/v1/teams/{$this->team->id}")->assertNotFound();
    $this->actingAs($member)->getJson('/api/v1/me/teams')->assertJsonCount(0, 'data');
});

it('lets any member leave, but not remove others unless they are the Owner', function () {
    $this->actingAs($this->members['admin'])->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->members['member']->id}")
        ->assertForbidden();

    $this->actingAs($this->members['viewer'])->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->members['viewer']->id}")
        ->assertNoContent();

    expect($this->team->roleOf($this->members['viewer']))->toBeNull()
        ->and($this->team->roleOf($this->members['member']))->toBe(TeamRole::Member);
});

it('refuses to let the Owner leave without a transfer (TEAM-01)', function () {
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->owner->id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    $this->actingAs($this->members['admin'])->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->owner->id}")
        ->assertForbidden();

    expect(ownerMembershipsOf($this->team))->toBe([$this->owner->id]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

// --- Ownership transfer (FR-TEAM-005) -------------------------------------------------------------------

it('transfers ownership to a member and makes the previous Owner an Admin', function () {
    $new = $this->members['viewer'];

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $new->id])
        ->assertOk()
        ->assertJsonPath('data.owner_user_id', $new->id)
        ->assertJsonPath('data.role', 'admin');

    $team = $this->team->refresh();

    expect($team->owner_user_id)->toBe($new->id)
        ->and(ownerMembershipsOf($team))->toBe([$new->id])
        ->and($team->roleOf($this->owner))->toBe(TeamRole::Admin);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    // The former Owner has lost every Owner-only power.
    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->owner->id])
        ->assertForbidden();
});

it('lets only the Owner transfer, and only to another current member', function () {
    $this->actingAs($this->members['admin'])
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->members['admin']->id])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->outsider->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.new_owner_user_id.0', 'not_member');

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->owner->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.new_owner_user_id.0', 'different');

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.new_owner_user_id.0', 'uuid');

    expect($this->team->refresh()->owner_user_id)->toBe($this->owner->id);
});

it('does not transfer ownership of an archived team', function () {
    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->members['admin']->id])
        ->assertStatus(423);
});

// --- Team boundaries (FR-TEAM-007) ----------------------------------------------------------------------

it('hides every team endpoint from non-members, with no mutation', function (string $method, Closure $uri, array $body) {
    $response = $this->actingAs($this->outsider)->json($method, $uri($this->team, $this->members), $body);

    $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
    expect($response->getContent())->not->toContain('Website');

    $team = $this->team->refresh();
    expect($team->name)->toBe('Website')
        ->and($team->owner_user_id)->toBe($this->owner->id)
        ->and($team->memberships()->count())->toBe(4);
})->with([
    'update team' => ['PATCH', fn ($t) => "/api/v1/teams/{$t->id}", ['name' => 'X']],
    'list members' => ['GET', fn ($t) => "/api/v1/teams/{$t->id}/members", []],
    'change role' => ['PATCH', fn ($t, $m) => "/api/v1/teams/{$t->id}/members/{$m['member']->id}", ['role' => 'admin']],
    'remove member' => ['DELETE', fn ($t, $m) => "/api/v1/teams/{$t->id}/members/{$m['member']->id}", []],
    'transfer' => ['POST', fn ($t, $m) => "/api/v1/teams/{$t->id}/ownership-transfer", ['new_owner_user_id' => Str::uuid7()->toString()]],
]);

it('treats a user from another team as no member of this one', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->outsider->id}", ['role' => 'admin'])
        ->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->outsider->id}")
        ->assertNotFound();
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/not-a-uuid")
        ->assertNotFound();
});

// --- Archived teams, authorisation order, re-checks under lock ---------------------------------------

it('refuses role changes, removals and leaving on an archived team (423), with no mutation', function () {
    $this->team->forceFill(['archived_at' => now()])->save();
    $member = $this->members['member'];

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$member->id}", ['role' => 'admin'])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'resource_locked');
    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$member->id}")
        ->assertStatus(423);
    $this->actingAs($member)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$member->id}")
        ->assertStatus(423);

    expect($this->team->roleOf($member))->toBe(TeamRole::Member);
});

it('answers 403 to a Viewer whatever the body, before validation (AUTH-04)', function (string $uri, array $body) {
    $viewer = $this->members['viewer'];
    $target = $this->members['member'];
    $uri = str_replace(['{team}', '{member}'], [$this->team->id, $target->id], $uri);

    $this->actingAs($viewer)->json(str_contains($uri, 'ownership') ? 'POST' : 'PATCH', $uri, $body)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($this->team->refresh()->owner_user_id)->toBe($this->owner->id)
        ->and($this->team->roleOf($target))->toBe(TeamRole::Member);
})->with([
    'role change, invalid role' => ['/api/v1/teams/{team}/members/{member}', ['role' => 'owner', 'junk' => 1]],
    'role change, empty body' => ['/api/v1/teams/{team}/members/{member}', []],
    'transfer, invalid id' => ['/api/v1/teams/{team}/ownership-transfer', ['new_owner_user_id' => 'nope']],
]);

it('rejects unknown body fields but ignores query-string parameters', function () {
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}?_=123", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}", ['name' => 'Again', 'owner_user_id' => $this->outsider->id])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.owner_user_id.0', 'unknown_field');
});

it('logs role changes as a structured audit event', function () {
    Log::spy();
    $member = $this->members['member'];

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$member->id}", ['role' => 'viewer'])
        ->assertOk();

    Log::shouldHaveReceived('info')->with('team.member_role_changed', [
        'team_id' => $this->team->id,
        'user_id' => $member->id,
        'actor_id' => $this->owner->id,
        'from' => 'member',
        'to' => 'viewer',
    ])->once();
});

it('re-checks ownership on the locked team row, so a former Owner cannot act after a transfer', function () {
    $admin = $this->members['admin'];
    $member = $this->members['member'];
    $staleTeam = $this->team->fresh();
    $membership = $this->team->memberships()->where('user_id', $member->id)->firstOrFail();

    // The transfer commits after the former Owner's request passed its policy check.
    $this->actingAs($this->owner)->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $admin->id])
        ->assertOk();

    expect(fn () => app(ChangeMemberRole::class)->handle($staleTeam, $this->owner, $membership, TeamRole::Viewer))
        ->toThrow(DomainConflictException::class)
        ->and(fn () => app(RemoveMember::class)->handle($staleTeam, $this->owner, $membership))
        ->toThrow(DomainConflictException::class)
        ->and($this->team->roleOf($member))->toBe(TeamRole::Member);
});

it('never changes the Owner membership, even when called without the policy', function () {
    $ownerMembership = $this->team->memberships()->where('user_id', $this->owner->id)->firstOrFail();

    expect(fn () => app(ChangeMemberRole::class)->handle($this->team, $this->owner, $ownerMembership, TeamRole::Admin))
        ->toThrow(DomainConflictException::class)
        ->and(ownerMembershipsOf($this->team))->toBe([$this->owner->id]);
});

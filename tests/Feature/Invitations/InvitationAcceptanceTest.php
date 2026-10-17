<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Domain\Trash\PurgeTrash;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// CORE-002: previewing and accepting invitation tokens (API_SPEC.md §3.1, DATABASE.md §11, ADR-025 §4).
// TEST-FR-TEAM-002/-003; acceptance scenarios INV-01 (expiry), INV-02 (20-use limit), INV-03 (email
// binding); DB-T07 (replay, expiry, revocation).

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->team = Team::factory()->create(['name' => 'فريق الموقع']);
    $this->owner = $this->team->owner;
    $this->admin = TeamMembership::factory()->for($this->team)->role(TeamRole::Admin)->create()->user;
});

/** @return array{invitation: TeamInvitation, token: string} */
function issue(Team $team, User $actor, InvitationType $type = InvitationType::Link, TeamRole $role = TeamRole::Member, ?string $email = null): array
{
    return app(CreateInvitation::class)->handle($team, $actor, $type, $role, $email);
}

function invitationRow(TeamInvitation $invitation): TeamInvitation
{
    return TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id);
}

// --- Preview --------------------------------------------------------------------------------------

it('shows a minimal preview to any holder of a valid token, signed in or not', function () {
    ['token' => $token] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Admin, 'x@example.com');

    $response = $this->getJson("/api/v1/invitations/{$token}/preview")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.team.name', 'فريق الموقع')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.type', 'email');

    // Team name and role only: no IDs, members, addresses or projects.
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['team', 'role', 'type', 'expires_at'])
        ->and(array_keys($response->json('data.team')))->toBe(['name'])
        ->and($response->getContent())->not->toContain('x@example.com')
        ->and($response->getContent())->not->toContain($this->team->id);

    $this->actingAs(User::factory()->create())->getJson("/api/v1/invitations/{$token}/preview")->assertOk();
});

it('answers 404 to an unknown or malformed token, and to a team in the trash', function () {
    ['token' => $token] = issue($this->team, $this->owner);

    $this->getJson('/api/v1/invitations/'.str_repeat('A', 43).'/preview')->assertNotFound();
    $this->getJson('/api/v1/invitations/short/preview')->assertNotFound();
    $this->getJson('/api/v1/invitations/'.str_repeat('A', 42).'%2F/preview')->assertNotFound();

    $this->team->delete();

    $this->getJson("/api/v1/invitations/{$token}/preview")->assertNotFound();
    $this->actingAs(User::factory()->create())->postJson("/api/v1/invitations/{$token}/accept")->assertNotFound();
});

it('answers 410 to a revoked, used-up or expired token', function (string $state) {
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner);

    match ($state) {
        'revoked' => DB::table('team_invitations')->where('id', $invitation->id)->update(['revoked_at' => now()]),
        'used up' => DB::table('team_invitations')->where('id', $invitation->id)->update(['use_count' => 20, 'accepted_at' => now()]),
        'expired' => $this->travel(7)->days(),
    };

    $this->getJson("/api/v1/invitations/{$token}/preview")->assertStatus(410)->assertJsonPath('error.code', 'expired');
    $this->actingAs(User::factory()->create())->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);
})->with(['revoked', 'used up', 'expired']);

// --- Accept: email invitations --------------------------------------------------------------------

it('joins the invited, verified address with the invited role, once', function (TeamRole $role) {
    $invitee = User::factory()->create(['email' => 'sara@example.com']);
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner, InvitationType::Email, $role, 'Sara@Example.com');

    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertCreated()
        ->assertJsonPath('data.id', $this->team->id)
        ->assertJsonPath('data.role', $role->value);

    $row = invitationRow($invitation);

    expect($this->team->roleOf($invitee))->toBe($role)
        ->and($row->use_count)->toBe(1)
        ->and($row->accepted_at)->not->toBeNull()
        ->and($row->status()->value)->toBe('accepted');

    // A retry by the same member is their membership as it is: no use, no role change.
    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertOk()
        ->assertJsonPath('data.role', $role->value);

    expect(invitationRow($invitation)->use_count)->toBe(1);
})->with([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer]);

it('refuses an email invitation to any other verified account (INV-03)', function () {
    User::factory()->create(['email' => 'a@x.com']);
    $other = User::factory()->create(['email' => 'b@x.com']);
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'a@x.com');

    $this->actingAs($other)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($this->team->roleOf($other))->toBeNull()
        ->and(invitationRow($invitation)->use_count)->toBe(0);
});

it('refuses an expired email invitation (INV-01)', function () {
    $invitee = User::factory()->create(['email' => 'late@example.com']);
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'late@example.com');

    $this->travelTo(now()->addDays(7)->addSecond());

    $this->actingAs($invitee)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'expired');

    expect($this->team->roleOf($invitee))->toBeNull()
        ->and(invitationRow($invitation)->use_count)->toBe(0);
});

it('needs a signed-in account with a verified email', function () {
    ['token' => $token] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'new@example.com');

    $this->postJson("/api/v1/invitations/{$token}/accept")->assertUnauthorized();

    $unverified = User::factory()->unverified()->create(['email' => 'new@example.com']);
    $this->actingAs($unverified)->postJson("/api/v1/invitations/{$token}/accept")->assertForbidden();

    expect($this->team->roleOf($unverified))->toBeNull();
});

// --- Accept: share links --------------------------------------------------------------------------

it('lets up to 20 verified users redeem a share link, and refuses the 21st (INV-02)', function () {
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->admin, InvitationType::Link, TeamRole::Viewer);

    $users = User::factory()->count(21)->create();

    foreach ($users->take(20) as $index => $user) {
        $this->actingAs($user)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated()->assertJsonPath('data.role', 'viewer');

        $row = invitationRow($invitation);
        expect($row->use_count)->toBe($index + 1)
            ->and($row->accepted_at === null)->toBe($index < 19);
    }

    $this->actingAs($users->last())->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);

    expect(invitationRow($invitation)->use_count)->toBe(20)
        ->and($this->team->roleOf($users->last()))->toBeNull()
        ->and(TeamMembership::query()->where('team_id', $this->team->id)->count())->toBe(22);
});

it('never grants project membership: private projects stay hidden after joining by link', function () {
    $private = Project::factory()->for($this->team)->private()->create();
    $visible = Project::factory()->for($this->team)->create();
    ['token' => $token] = issue($this->team, $this->owner, InvitationType::Link, TeamRole::Member);
    $joiner = User::factory()->create();

    $this->actingAs($joiner)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();

    $this->actingAs($joiner)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();
    $this->actingAs($joiner)->getJson("/api/v1/projects/{$visible->id}")->assertOk();

    expect(DB::table('project_memberships')->where('user_id', $joiner->id)->count())->toBe(0);
});

it('refuses to join an archived team (423), but answers a member\'s retry as before', function () {
    ['token' => $token] = issue($this->team, $this->owner);
    $member = User::factory()->create();
    $this->actingAs($member)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();

    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs(User::factory()->create())->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(423);
    $this->actingAs($member)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();
});

it('keeps an existing member\'s role whatever the invitation offers', function () {
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner, InvitationType::Link, TeamRole::Viewer);

    $this->actingAs($this->admin)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');

    expect($this->team->roleOf($this->admin))->toBe(TeamRole::Admin)
        ->and(invitationRow($invitation)->use_count)->toBe(0);
});

it('rejects a body on accept', function () {
    ['token' => $token] = issue($this->team, $this->owner);

    $this->actingAs(User::factory()->create())->postJson("/api/v1/invitations/{$token}/accept", ['role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.role', ['unknown_field']);
});

it('rate-limits token preview and accept', function () {
    config(['sn3.rate_limits.invitation_tokens_per_minute' => 2]);
    $unknown = str_repeat('Z', 43);

    $user = User::factory()->create();

    // Per user: 2 a minute; per IP: four times that.
    $this->actingAs($user)->getJson("/api/v1/invitations/{$unknown}/preview")->assertNotFound();
    $this->actingAs($user)->getJson("/api/v1/invitations/{$unknown}/preview")->assertNotFound();
    $this->actingAs($user)->getJson("/api/v1/invitations/{$unknown}/preview")->assertStatus(429)->assertHeader('Retry-After');

    foreach (range(1, 5) as $ignored) {
        $this->actingAs(User::factory()->create())->getJson("/api/v1/invitations/{$unknown}/preview")->assertNotFound();
    }

    $this->actingAs(User::factory()->create())->getJson("/api/v1/invitations/{$unknown}/preview")->assertStatus(429);
});

// --- Issuers who lose the right to invite (ADR-025 §5) ---------------------------------------------

it('revokes the pending invitations of a member who leaves or is removed', function () {
    ['token' => $link] = issue($this->team, $this->admin);
    ['token' => $email] = issue($this->team, $this->admin, InvitationType::Email, TeamRole::Viewer, 'v@example.com');
    ['token' => $ownersLink] = issue($this->team, $this->owner);

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->admin->id}")->assertNoContent();

    $this->getJson("/api/v1/invitations/{$link}/preview")->assertStatus(410);
    $this->getJson("/api/v1/invitations/{$email}/preview")->assertStatus(410);
    $this->getJson("/api/v1/invitations/{$ownersLink}/preview")->assertOk();
});

it('revokes an Admin\'s pending invitations when demoted, not when promoted', function () {
    ['token' => $adminsLink] = issue($this->team, $this->admin);
    $member = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create()->user;

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$member->id}", ['role' => 'admin'])->assertOk();
    ['token' => $promotedLink] = issue($this->team, $member->refresh());
    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->admin->id}", ['role' => 'member'])->assertOk();

    $this->getJson("/api/v1/invitations/{$adminsLink}/preview")->assertStatus(410);
    $this->getJson("/api/v1/invitations/{$promotedLink}/preview")->assertOk();
});

it('revokes the former Owner\'s pending Admin invitations on transfer, and keeps the others', function () {
    ['token' => $adminInvite] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Admin, 'a1@example.com');
    ['token' => $memberInvite] = issue($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'm1@example.com');
    ['token' => $link] = issue($this->team, $this->owner);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/teams/{$this->team->id}/ownership-transfer", ['new_owner_user_id' => $this->admin->id])
        ->assertOk();

    $this->getJson("/api/v1/invitations/{$adminInvite}/preview")->assertStatus(410);
    $this->getJson("/api/v1/invitations/{$memberInvite}/preview")->assertOk();
    $this->getJson("/api/v1/invitations/{$link}/preview")->assertOk();
});

it('leaves already accepted and expired invitations untouched when revoking an issuer\'s', function () {
    $accepted = issue($this->team, $this->admin, InvitationType::Email, TeamRole::Member, 'done@example.com')['invitation'];
    $expired = issue($this->team, $this->admin, InvitationType::Email, TeamRole::Member, 'old@example.com')['invitation'];
    DB::table('team_invitations')->where('id', $accepted->id)->update(['use_count' => 1, 'accepted_at' => now()]);
    DB::table('team_invitations')->where('id', $expired->id)->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDays(8)]);

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/members/{$this->admin->id}")->assertNoContent();

    expect(invitationRow($accepted)->revoked_at)->toBeNull()
        ->and(invitationRow($expired)->revoked_at)->toBeNull();
});

it('deletes the invitations of a purged team with it (FK cascade)', function () {
    issue($this->team, $this->owner);
    $this->team->delete();
    $this->travel(31)->days();

    app(PurgeTrash::class)->handle();

    expect(TeamInvitation::query()->count())->toBe(0);
});

it('never stores a token in the session, even for a plain browser GET of the API', function () {
    config(['session.driver' => 'database']);
    ['token' => $token] = issue($this->team, $this->owner);

    // No X-Requested-With: the request a browser makes when the API URL is opened directly.
    $this->get("/api/v1/invitations/{$token}/preview", ['Accept' => 'application/json'])->assertOk();
    $this->actingAs(User::factory()->create())->get("/api/v1/invitations/{$token}/preview", ['Accept' => 'application/json'])->assertOk();

    $payloads = DB::table('sessions')->pluck('payload')->map(fn ($payload) => base64_decode((string) $payload))->implode("\n");

    expect(DB::table('sessions')->count())->toBeGreaterThan(0)
        ->and($payloads)->not->toContain($token);
});

it('refuses a use the database sees as expired even when the application clock lags behind', function () {
    ['invitation' => $invitation, 'token' => $token] = issue($this->team, $this->owner);
    DB::statement("UPDATE team_invitations SET created_at = now() - interval '8 days', expires_at = now() - interval '1 minute' WHERE id = ?", [$invitation->id]);
    $joiner = User::factory()->create();

    // The application thinks it is two minutes earlier, so its own expiry check still passes.
    $this->travelTo(now()->subMinutes(2));

    $this->actingAs($joiner)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(410);

    expect($this->team->roleOf($joiner))->toBeNull()
        ->and(invitationRow($invitation)->use_count)->toBe(0);
});

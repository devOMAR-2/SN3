<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\Actions\RevokeInvitation;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Notifications\Teams\TeamInvitationNotification;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// CORE-002: creating, listing and revoking team invitations (API_SPEC.md §3.1, DECIDED-007, ADR-025).
// TEST-FR-TEAM-002 (Owner/Admin invite by email or link), TEST-FR-TEAM-003 (expiry, revocation,
// usage limits); acceptance scenario AUTH-04 (403 whatever the body).

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->team = Team::factory()->create(['name' => 'Website']);
    $this->owner = $this->team->owner;
    $this->members = [];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->members[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->outsider = Team::factory()->create()->owner;
    $this->uri = "/api/v1/teams/{$this->team->id}/invitations";
});

/** Idempotency-Key header for a fresh create. */
function idem(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

/** An invitation created through the action, with its raw token. */
function invitationFor(Team $team, User $actor, InvitationType $type = InvitationType::Link, TeamRole $role = TeamRole::Member, ?string $email = null): array
{
    return app(CreateInvitation::class)->handle($team, $actor, $type, $role, $email);
}

function actorOf(object $test, string $who): User
{
    return match ($who) {
        'owner' => $test->owner,
        'outsider' => $test->outsider,
        default => $test->members[$who],
    };
}

// --- Create ---------------------------------------------------------------------------------------

it('lets the Owner and Admins create email invitations and share links', function (string $who, array $body, int $maxUses) {
    $response = $this->actingAs(actorOf($this, $who))->postJson($this->uri, $body, idem())
        ->assertCreated()
        ->assertJsonPath('data.team_id', $this->team->id)
        ->assertJsonPath('data.type', $body['type'])
        ->assertJsonPath('data.role', $body['role'])
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.use_count', 0)
        ->assertJsonPath('data.max_uses', $maxUses)
        ->assertJsonPath('data.invited_by.user_id', actorOf($this, $who)->id);

    $token = $response->json('data.token');

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($response->json('data.url'))->toBe(url('/invite').'#'.$token)
        ->and($response->json('data.email'))->toBe($body['email'] ?? null);

    $invitation = TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($response->json('data.id'));

    expect($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue()
        ->and(TeamInvitation::whereTokenHash(hash('sha256', $token))->value('id'))->toBe($invitation->id);
})->with([
    'Owner, email, Admin' => ['owner', ['type' => 'email', 'email' => 'new.admin@example.com', 'role' => 'admin'], 1],
    'Owner, email, Member' => ['owner', ['type' => 'email', 'email' => 'a@example.com', 'role' => 'member'], 1],
    'Owner, link, Viewer' => ['owner', ['type' => 'link', 'role' => 'viewer'], 20],
    'Admin, email, Member' => ['admin', ['type' => 'email', 'email' => 'b@example.com', 'role' => 'member'], 1],
    'Admin, email, Viewer' => ['admin', ['type' => 'email', 'email' => 'c@example.com', 'role' => 'viewer'], 1],
    'Admin, link, Member' => ['admin', ['type' => 'link', 'role' => 'member'], 20],
]);

it('stores only the hash of the token: no table and no log holds the raw token', function () {
    Log::spy();

    $response = $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => 'Sara@Example.com', 'role' => 'member'], idem())
        ->assertCreated();
    $token = $response->json('data.token');

    foreach (['team_invitations', 'api_idempotency_records', 'jobs', 'task_activities'] as $table) {
        $rows = DB::table($table)->get()->map(fn ($row) => json_encode(array_map(
            fn ($value) => is_resource($value) ? bin2hex((string) stream_get_contents($value)) : $value,
            (array) $row,
        )))->implode("\n");

        expect($rows)->not->toContain($token);
    }

    expect(bin2hex((string) stream_get_contents(DB::table('team_invitations')->value('token_hash'))))->toBe(hash('sha256', $token));

    // Audited as a team activity entry with IDs, type and role only (CORE-008, ADR-027 §3).
    $entry = TaskActivity::query()->where('action', 'team.invitation_created')->sole();
    expect($entry->changes)->toEqual(['invitation_id' => $response->json('data.id'), 'type' => 'email', 'role' => 'member'])
        ->and(json_encode($entry->changes))->not->toContain('example.com');

    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []) => str_contains((string) json_encode($context), $token));
});

it('mails an email invitation to the address, queued after commit and encrypted, with the token in the fragment', function () {
    $this->owner->forceFill(['locale' => 'en'])->save();

    $token = $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => '  Sara@Example.com ', 'role' => 'viewer'], idem())
        ->assertCreated()
        ->assertJsonPath('data.email', 'sara@example.com')
        ->json('data.token');

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($token): bool {
        $mail = $notification->toMail($notifiable);

        expect($notifiable->routes['mail'])->toBe('sara@example.com')
            ->and($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and($notification->afterCommit)->toBeTrue()
            ->and($notification->locale)->toBe('en')
            ->and($mail->actionUrl)->toBe(url('/invite').'#'.$token)
            ->and($mail->subject)->toContain('Website');

        return true;
    });
});

it('writes the email in the language of the account the address belongs to', function () {
    $this->owner->forceFill(['locale' => 'en'])->save();
    User::factory()->create(['email' => 'arabic@example.com', 'locale' => 'ar']);

    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => 'arabic@example.com', 'role' => 'member'], idem())
        ->assertCreated();

    Notification::assertSentOnDemand(TeamInvitationNotification::class, fn (TeamInvitationNotification $n): bool => $n->locale === 'ar');
});

it('sends no email for a share link', function () {
    $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem())->assertCreated();

    Notification::assertNothingSent();
});

it('refuses an Admin invitation from an Admin (403) and on a share link (422)', function () {
    $this->actingAs($this->members['admin'])
        ->postJson($this->uri, ['type' => 'email', 'email' => 'x@example.com', 'role' => 'admin'], idem())
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'link', 'role' => 'admin'], idem())
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.role', ['in']);

    expect(TeamInvitation::query()->count())->toBe(0);
});

it('answers 403 to Members and Viewers whatever the body (AUTH-04), and 404 to outsiders', function (string $who, array $body, int $status) {
    $this->actingAs(actorOf($this, $who))->postJson($this->uri, $body, idem())->assertStatus($status);

    expect(TeamInvitation::query()->count())->toBe(0);
})->with([
    'Member, valid' => ['member', ['type' => 'link', 'role' => 'member'], 403],
    'Viewer, valid' => ['viewer', ['type' => 'link', 'role' => 'member'], 403],
    'Member, invalid body' => ['member', ['type' => 'nope', 'role' => 'owner', 'extra' => 1], 403],
    'Viewer, empty body' => ['viewer', [], 403],
    'Outsider' => ['outsider', ['type' => 'link', 'role' => 'member'], 404],
]);

it('validates the body', function (array $body, string $field, string $code) {
    $this->actingAs($this->owner)
        ->postJson($this->uri, $body, idem())
        ->assertUnprocessable()
        ->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'type missing' => [['role' => 'member'], 'type', 'required'],
    'type unknown' => [['type' => 'sms', 'role' => 'member'], 'type', 'in'],
    'email missing' => [['type' => 'email', 'role' => 'member'], 'email', 'required'],
    'email invalid' => [['type' => 'email', 'email' => 'not-an-email', 'role' => 'member'], 'email', 'email'],
    'email too long' => [['type' => 'email', 'email' => str_repeat('a', 250).'@x.com', 'role' => 'member'], 'email', 'max'],
    'email on a link' => [['type' => 'link', 'email' => 'a@example.com', 'role' => 'member'], 'email', 'prohibited'],
    'role missing' => [['type' => 'link'], 'role', 'required'],
    'role owner' => [['type' => 'email', 'email' => 'a@example.com', 'role' => 'owner'], 'role', 'in'],
    'unknown field' => [['type' => 'link', 'role' => 'member', 'max_uses' => 100], 'max_uses', 'unknown_field'],
]);

it('refuses to invite a current member, or an address with a pending invitation (409)', function () {
    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => strtoupper($this->members['viewer']->email), 'role' => 'member'], idem())
        ->assertConflict();

    $first = $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => 'pending@example.com', 'role' => 'member'], idem())
        ->assertCreated();

    $this->actingAs($this->members['admin'])
        ->postJson($this->uri, ['type' => 'email', 'email' => 'Pending@Example.com', 'role' => 'viewer'], idem())
        ->assertConflict();

    // Revoked, or expired: a new invitation may be sent.
    $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$first->json('data.id')}")->assertNoContent();
    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => 'pending@example.com', 'role' => 'member'], idem())
        ->assertCreated();

    $this->travel(8)->days();
    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'email', 'email' => 'pending@example.com', 'role' => 'member'], idem())
        ->assertCreated();
});

it('allows several share links at once', function () {
    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem())->assertCreated();
    }

    expect(TeamInvitation::query()->count())->toBe(3);
});

it('refuses to invite into an archived team (423)', function () {
    $team = Team::factory()->archived()->create();

    $this->actingAs($team->owner)
        ->postJson("/api/v1/teams/{$team->id}/invitations", ['type' => 'link', 'role' => 'member'], idem())
        ->assertStatus(423);
});

it('requires an Idempotency-Key, and replays without the token (it is never stored)', function () {
    $this->actingAs($this->owner)
        ->postJson($this->uri, ['type' => 'link', 'role' => 'member'])
        ->assertStatus(400);

    $key = (string) Str::uuid();
    $first = $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem($key))->assertCreated();
    $replay = $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem($key))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.token', null)
        ->assertJsonPath('data.url', null);

    expect($first->json('data.token'))->not->toBeNull()
        ->and(TeamInvitation::query()->count())->toBe(1)
        ->and($replay->json('data.max_uses'))->toBe(20);

    $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'viewer'], idem($key))
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('rate-limits invitation creation per user', function () {
    config(['sn3.rate_limits.invitations_per_hour' => 2]);

    $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem())->assertCreated();
    $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem())->assertCreated();
    $this->actingAs($this->owner)->postJson($this->uri, ['type' => 'link', 'role' => 'member'], idem())
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('re-checks the actor on the locked team: removed is 404, demoted is 409', function () {
    $admin = $this->members['admin'];

    TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->update(['role' => 'member']);
    expect(fn () => invitationFor($this->team, $admin))->toThrow(DomainConflictException::class);

    // The former Owner of an Admin invitation right: an Admin may not invite Admins.
    TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->update(['role' => 'admin']);
    expect(fn () => invitationFor($this->team, $admin, InvitationType::Email, TeamRole::Admin, 'z@example.com'))->toThrow(DomainConflictException::class);

    TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->delete();
    expect(fn () => invitationFor($this->team, $admin))->toThrow(ModelNotFoundException::class);

    expect(TeamInvitation::query()->count())->toBe(0);
});

it('re-checks the archived flag on the locked team', function () {
    $this->team->forceFill(['archived_at' => now()])->save();

    expect(fn () => invitationFor($this->team, $this->owner))->toThrow(ResourceLockedException::class);
});

// --- List -----------------------------------------------------------------------------------------

it('lists invitation metadata with each status, and never a token or hash', function () {
    $pending = invitationFor($this->team, $this->owner)['invitation'];
    $revoked = invitationFor($this->team, $this->owner, InvitationType::Email, TeamRole::Viewer, 'r@example.com')['invitation'];
    $accepted = invitationFor($this->team, $this->members['admin'], InvitationType::Email, TeamRole::Member, 'acc@example.com')['invitation'];
    $expired = invitationFor($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'exp@example.com')['invitation'];

    DB::table('team_invitations')->where('id', $revoked->id)->update(['revoked_at' => now()]);
    DB::table('team_invitations')->where('id', $accepted->id)->update(['use_count' => 1, 'accepted_at' => now()]);
    DB::table('team_invitations')->where('id', $expired->id)->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDays(8)]);

    foreach (['owner', 'admin'] as $who) {
        $response = $this->actingAs(actorOf($this, $who))->getJson($this->uri)->assertOk();
        $statuses = collect($response->json('data'))->pluck('status', 'id');

        expect($statuses->all())->toEqual([
            $pending->id => 'pending',
            $revoked->id => 'revoked',
            $accepted->id => 'accepted',
            $expired->id => 'expired',
        ])->and($response->getContent())->not->toContain('token');

        expect(array_keys($response->json('data.0')))->toEqualCanonicalizing([
            'id', 'team_id', 'type', 'email', 'role', 'status', 'use_count', 'max_uses', 'invited_by',
            'expires_at', 'accepted_at', 'revoked_at', 'created_at',
        ]);
    }

    $this->actingAs($this->owner)->getJson($this->uri.'?limit=2')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.has_more', true);
});

it('lists invitations to the Owner and Admins only', function (string $who, int $status) {
    invitationFor($this->team, $this->owner);

    $this->actingAs(actorOf($this, $who))->getJson($this->uri)->assertStatus($status);
})->with([
    ['member', 403],
    ['viewer', 403],
    ['outsider', 404],
]);

it('lists only this team\'s invitations', function () {
    invitationFor($this->team, $this->owner);
    $other = Team::factory()->create();
    invitationFor($other, $other->owner);

    $this->actingAs($this->owner)->getJson($this->uri)->assertOk()->assertJsonCount(1, 'data');
});

// --- Revoke ---------------------------------------------------------------------------------------

it('lets the Owner and Admins revoke any invitation of the team, idempotently', function (string $who) {
    ['invitation' => $invitation, 'token' => $token] = invitationFor($this->team, $this->owner, InvitationType::Email, TeamRole::Admin, 'adm@example.com');

    $this->actingAs(actorOf($this, $who))->deleteJson("{$this->uri}/{$invitation->id}")->assertNoContent();
    $this->actingAs(actorOf($this, $who))->deleteJson("{$this->uri}/{$invitation->id}")->assertNoContent();

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id)->status()->value)->toBe('revoked');

    $this->getJson("/api/v1/invitations/{$token}/preview")->assertStatus(410)->assertJsonPath('error.code', 'expired');
})->with(['owner', 'admin']);

it('refuses revocation to Members and Viewers (403, whatever the body) and outsiders (404)', function (string $who, array $body, int $status) {
    $invitation = invitationFor($this->team, $this->owner)['invitation'];

    $this->actingAs(actorOf($this, $who))->deleteJson("{$this->uri}/{$invitation->id}", $body)->assertStatus($status);

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id)->revoked_at)->toBeNull();
})->with([
    ['member', [], 403],
    ['viewer', ['x' => 1], 403],
    ['outsider', [], 404],
]);

it('answers 404 for another team\'s, a random or a malformed invitation ID', function () {
    $other = Team::factory()->create();
    $foreign = invitationFor($other, $other->owner)['invitation'];

    foreach ([$foreign->id, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
        $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$id}")->assertNotFound();
    }

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($foreign->id)->revoked_at)->toBeNull();
});

it('rejects a body on revoke, and keeps used-up invitations as accepted', function () {
    $invitation = invitationFor($this->team, $this->owner, InvitationType::Email, TeamRole::Member, 'used@example.com')['invitation'];
    DB::table('team_invitations')->where('id', $invitation->id)->update(['use_count' => 1, 'accepted_at' => now()]);

    $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$invitation->id}", ['reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.reason', ['unknown_field']);

    $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$invitation->id}")->assertNoContent();

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id)->status()->value)->toBe('accepted');
});

it('refuses revocation in an archived team (423)', function () {
    $invitation = invitationFor($this->team, $this->owner)['invitation'];
    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$invitation->id}")->assertStatus(423);
});

it('generates distinct well-formed tokens', function () {
    $tokens = collect(range(1, 50))->map(fn () => InvitationToken::generate()['token']);

    expect($tokens->unique())->toHaveCount(50)
        ->and($tokens->every(fn (string $token) => InvitationToken::isWellFormed($token)))->toBeTrue()
        ->and(InvitationToken::isWellFormed(str_repeat('a', 42)))->toBeFalse()
        ->and(InvitationToken::isWellFormed(str_repeat('a', 42).'='))->toBeFalse();
});

it('re-checks the actor and the invitation on the locked team when revoking', function () {
    $revoke = app(RevokeInvitation::class);
    $invitation = invitationFor($this->team, $this->owner)['invitation'];
    $admin = $this->members['admin'];

    // Another team's invitation is not found, whatever the route resolved.
    $other = Team::factory()->create();
    $foreign = invitationFor($other, $other->owner)['invitation'];
    expect(fn () => $revoke->handle($this->team, $this->owner, $foreign))->toThrow(ModelNotFoundException::class);

    TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->update(['role' => 'member']);
    expect(fn () => $revoke->handle($this->team, $admin, $invitation))->toThrow(DomainConflictException::class);

    TeamMembership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->delete();
    expect(fn () => $revoke->handle($this->team, $admin, $invitation))->toThrow(ModelNotFoundException::class);

    $this->team->forceFill(['archived_at' => now()])->save();
    expect(fn () => $revoke->handle($this->team, $this->owner, $invitation))->toThrow(ResourceLockedException::class);

    expect(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id)->revoked_at)->toBeNull()
        ->and(TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($foreign->id)->revoked_at)->toBeNull();
});

it('leaves an expired invitation as it is when it is revoked', function () {
    $invitation = invitationFor($this->team, $this->owner)['invitation'];
    DB::table('team_invitations')->where('id', $invitation->id)->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDays(8)]);

    $this->actingAs($this->owner)->deleteJson("{$this->uri}/{$invitation->id}")->assertNoContent();

    $row = TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id);
    expect($row->revoked_at)->toBeNull()
        ->and($row->status()->value)->toBe('expired');
});

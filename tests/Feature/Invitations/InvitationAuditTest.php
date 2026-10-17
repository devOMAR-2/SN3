<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\TaskActivity;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

// CORE-002 × CORE-008: invitations and the memberships they create go into the append-only audit
// log (NFR-SEC-005; ADR-025, ADR-027 §3) as team entries with IDs, type and role only: never the
// invited address or the token. Team entries are readable only by their actor (`/me/activity`).

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->admin = TeamMembership::factory()->for($this->team)->role(TeamRole::Admin)->create()->user;
});

/**
 * @return list<array{0: string, 1: string|null, 2: string, 3: array<string, mixed>}>
 */
function invitationAudit(Team $team): array
{
    return TaskActivity::query()->where('team_id', $team->id)->whereNull('project_id')->orderBy('id')->get()
        ->map(fn (TaskActivity $entry) => [$entry->action, $entry->actor_user_id, $entry->source, $entry->changes])->all();
}

it('audits creating, revoking and accepting invitations, without addresses or tokens', function () {
    ['invitation' => $email, 'token' => $emailToken] = app(CreateInvitation::class)->handle($this->team, $this->owner, InvitationType::Email, TeamRole::Admin, 'sara@example.com');
    ['invitation' => $link, 'token' => $linkToken] = app(CreateInvitation::class)->handle($this->team, $this->admin, InvitationType::Link, TeamRole::Viewer, null);

    $this->actingAs($this->owner)->deleteJson("/api/v1/teams/{$this->team->id}/invitations/{$link->id}")->assertNoContent();

    $sara = User::factory()->create(['email' => 'sara@example.com']);
    $this->actingAs($sara)->postJson("/api/v1/invitations/{$emailToken}/accept")->assertCreated();

    expect(invitationAudit($this->team))->toEqual([
        ['team.invitation_created', $this->owner->id, 'user', ['invitation_id' => $email->id, 'type' => 'email', 'role' => 'admin']],
        ['team.invitation_created', $this->admin->id, 'user', ['invitation_id' => $link->id, 'type' => 'link', 'role' => 'viewer']],
        ['team.invitation_revoked', $this->owner->id, 'user', ['invitation_id' => $link->id]],
        ['team.member_added', $sara->id, 'user', ['user_id' => $sara->id, 'role' => 'admin', 'invitation_id' => $email->id]],
    ]);

    $all = (string) json_encode(TaskActivity::query()->get()->toArray());
    expect($all)->not->toContain('sara@example.com')->not->toContain($emailToken)->not->toContain($linkToken);

    // The joining user sees their own entry; nobody else's team entries reach them.
    expect(collect($this->actingAs($sara)->getJson('/api/v1/me/activity')->assertOk()->json('data'))->pluck('action')->all())->toBe(['team.member_added']);
});

it('records nothing for a refused accept, and one entry for a share link per joining user', function () {
    ['invitation' => $link, 'token' => $token] = app(CreateInvitation::class)->handle($this->team, $this->owner, InvitationType::Link, TeamRole::Member, null);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();
    // A member's retry changes nothing and records nothing.
    $this->actingAs($first)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();
    $this->actingAs($second)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();

    $added = TaskActivity::query()->where('action', 'team.member_added')->orderBy('id')->get();
    expect($added->pluck('actor_user_id')->all())->toBe([$first->id, $second->id])
        ->and($added->pluck('changes.invitation_id')->unique()->all())->toBe([$link->id]);
});

it('audits the invitations revoked when their issuer loses the right, as a system entry', function () {
    app(CreateInvitation::class)->handle($this->team, $this->admin, InvitationType::Link, TeamRole::Member, null);
    app(CreateInvitation::class)->handle($this->team, $this->admin, InvitationType::Email, TeamRole::Viewer, 'b@example.com');

    $this->actingAs($this->owner)->patchJson("/api/v1/teams/{$this->team->id}/members/{$this->admin->id}", ['role' => 'member'])->assertOk();

    $entry = TaskActivity::query()->where('action', 'team.invitations_revoked')->sole();
    expect($entry->actor_user_id)->toBeNull()
        ->and($entry->source)->toBe('system')
        ->and($entry->changes)->toEqual(['issuer_id' => $this->admin->id, 'reason' => 'team_role_changed', 'count' => 2])
        ->and(TaskActivity::query()->where('action', 'team.member_role_changed')->count())->toBe(1);
});

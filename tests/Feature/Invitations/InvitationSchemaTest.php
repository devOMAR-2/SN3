<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Logging\RedactSensitiveData;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// CORE-002: `team_invitations` (DATABASE.md §3 FINAL, DECIDED-007; ADR-025 §1) refuses invalid rows
// whatever the application does, and invitation tokens never reach the logs.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
});

/**
 * Inserts a raw invitation row; `$overrides` replace the defaults (a valid pending share link).
 *
 * @param  array<string, mixed>  $overrides
 */
function insertInvitation(Team $team, array $overrides = []): string
{
    $row = array_merge([
        'id' => (string) Str::uuid7(),
        'team_id' => $team->id,
        'email' => null,
        'invited_role' => 'member',
        'token_hash' => DB::raw("decode('".hash('sha256', Str::random(43))."', 'hex')"),
        'invited_by' => $team->owner_user_id,
        'expires_at' => now()->addDays(7),
        'max_uses' => 20,
        'use_count' => 0,
        'accepted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('team_invitations')->insert($row);

    return $row['id'];
}

it('accepts a valid share link and a valid email invitation', function () {
    insertInvitation($this->team);
    insertInvitation($this->team, ['email' => 'a@example.com', 'max_uses' => 1, 'invited_role' => 'admin']);

    expect(DB::table('team_invitations')->count())->toBe(2);
});

it('refuses rows that break the invitation rules', function (array $overrides) {
    DB::beginTransaction();

    try {
        expect(fn () => insertInvitation($this->team, $overrides))->toThrow(QueryException::class);
    } finally {
        DB::rollBack();
    }
})->with([
    'role owner' => [fn () => ['invited_role' => 'owner']],
    'share link with 1 use' => [fn () => ['max_uses' => 1]],
    'share link with 100 uses' => [fn () => ['max_uses' => 100]],
    'email invitation with 20 uses' => [fn () => ['email' => 'a@example.com']],
    'Admin share link' => [fn () => ['invited_role' => 'admin']],
    'more uses than allowed' => [fn () => ['use_count' => 21]],
    'negative uses' => [fn () => ['use_count' => -1]],
    'used up but not accepted' => [fn () => ['use_count' => 20]],
    'accepted with uses left' => [fn () => ['accepted_at' => now()]],
    'email not normalised' => [fn () => ['email' => 'A@Example.com', 'max_uses' => 1]],
    'hash of the wrong size' => [fn () => ['token_hash' => DB::raw("decode('abcd', 'hex')")]],
    'expires before it was created' => [fn () => ['expires_at' => now()->subDay()]],
    'unknown team' => [fn () => ['team_id' => (string) Str::uuid7()]],
]);

it('keeps token hashes unique', function () {
    $hash = hash('sha256', 'same');
    insertInvitation($this->team, ['token_hash' => DB::raw("decode('{$hash}', 'hex')")]);

    expect(fn () => insertInvitation($this->team, ['token_hash' => DB::raw("decode('{$hash}', 'hex')")]))
        ->toThrow(QueryException::class);
});

it('cascades with its team and its issuer', function () {
    insertInvitation($this->team);
    $admin = TeamMembership::factory()->for($this->team)->role(TeamRole::Admin)->create()->user;
    insertInvitation($this->team, ['invited_by' => $admin->id]);

    DB::table('team_memberships')->where('user_id', $admin->id)->delete();
    $admin->delete();
    expect(DB::table('team_invitations')->count())->toBe(1);

    $this->team->forceDelete();
    expect(DB::table('team_invitations')->count())->toBe(0);
});

it('redacts invitation tokens from log messages and context', function () {
    $token = str_repeat('Ab3_-', 8).'xyz';
    $processor = new RedactSensitiveData;

    expect($processor->redactString("GET /api/v1/invitations/{$token}/preview"))
        ->toBe('GET /api/v1/invitations/[REDACTED]/preview')
        ->and($processor->redactString("open https://sn3.example/invite#{$token} now"))
        ->toBe('open https://sn3.example/invite#[REDACTED] now')
        ->and($processor->redactArray(['url' => "https://x/api/v1/invitations/{$token}/accept"]))
        ->toBe(['url' => 'https://x/api/v1/invitations/[REDACTED]/accept'])
        // Invitation IDs (UUIDs) are not secrets and stay readable.
        ->and($processor->redactString('/teams/abc/invitations/0192f3a0-1111-7000-8000-000000000000'))
        ->toBe('/teams/abc/invitations/0192f3a0-1111-7000-8000-000000000000');
});

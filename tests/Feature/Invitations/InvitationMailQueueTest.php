<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// CORE-002, ADR-025 §6: the invitation email is queued encrypted, so the raw token (and the address)
// never sit in plain text in the `jobs` table (DATABASE.md §0 rule 6). Uses the real database queue.

uses(RefreshDatabase::class);

it('queues the invitation email encrypted, after the commit', function () {
    config(['queue.default' => 'database']);
    $team = Team::factory()->create();

    ['token' => $token] = DB::transaction(function () use ($team): array {
        $created = app(CreateInvitation::class)->handle($team, $team->owner, InvitationType::Email, TeamRole::Member, 'queued@example.com');

        // Inside the transaction nothing is queued yet.
        expect(DB::table('jobs')->count())->toBe(0);

        return $created;
    });

    $payload = (string) DB::table('jobs')->value('payload');

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($payload)->not->toContain($token)
        ->and($payload)->not->toContain('queued@example.com')
        ->and(json_decode($payload, true)['data']['command'] ?? '')->not->toContain('TeamInvitationNotification');
});

<?php

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
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

it('renders user-written names in the email as plain text, never as links or images', function () {
    $notification = new TeamInvitationNotification(
        teamName: '[Verify your account now](https://evil.example/login) <b>x</b>',
        inviterName: "![x](https://evil.example/p.png)\n# Heading",
        role: TeamRole::Member,
        token: str_repeat('A', 43),
        expiresAt: now()->addDays(7),
        locale: 'en',
    );

    $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->not->toContain('Heading</h1>')
        ->and($html)->toContain('Verify your account now')
        ->and($html)->toContain('evil.example/p.png')
        ->and($html)->toContain('href="'.url('/invite').'#'.str_repeat('A', 43).'"');
});

it('keeps ordinary punctuation and Arabic names readable in the email', function () {
    $notification = new TeamInvitationNotification(
        teamName: 'R&D\'s "Team" (2026) — فريق «الموقع»، 1.0',
        inviterName: 'O\'Brien-محمد_علي',
        role: TeamRole::Admin,
        token: str_repeat('A', 43),
        expiresAt: now()->addDays(7),
        locale: 'en',
    );

    $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('R&amp;D\'s "Team" (2026) — فريق «الموقع»، 1.0')
        ->and($html)->toContain('O\'Brien-محمد_علي')
        ->and($html)->not->toContain('&amp;amp;')
        ->and($html)->not->toContain('&amp;#039;')
        ->and($html)->not->toContain('\\');
});

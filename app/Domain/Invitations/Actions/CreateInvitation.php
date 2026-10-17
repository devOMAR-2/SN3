<?php

namespace App\Domain\Invitations\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Invitations\Events\InvitationSent;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Notifications\Teams\TeamInvitationNotification;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Creates an email-bound invitation or a share link (API_SPEC.md §3.1, DECIDED-007; ADR-025 §3).
 *
 * - Locks the team row first, like every team writer (ADR-018 §4), and re-checks on it: archived is
 *   423, a team the actor left meanwhile 404, a right lost meanwhile 409.
 * - An email address that already belongs to a member, or that already has a pending invitation to
 *   this team, is 409: revoke the pending one to send a new one.
 * - The raw token is returned to the caller once and, for an email invitation, mailed to the
 *   address after the commit (an encrypted queued notification). It is never stored or logged.
 */
final class CreateInvitation
{
    /**
     * @return array{invitation: TeamInvitation, token: string}
     */
    public function handle(Team $team, User $actor, InvitationType $type, TeamRole $role, ?string $email): array
    {
        if ($role === TeamRole::Owner) {
            throw new InvalidArgumentException('No invitation can grant ownership.');
        }

        if (($type === InvitationType::Email) !== ($email !== null)) {
            throw new InvalidArgumentException('Email invitations need an address; share links have none.');
        }

        if ($role === TeamRole::Admin && $type !== InvitationType::Email) {
            throw new InvalidArgumentException('Only an email invitation can carry the Admin role.');
        }

        $email = $email === null ? null : User::normalizeEmail($email);

        return DB::transaction(function () use ($team, $actor, $type, $role, $email): array {
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->roleOf($actor) === null) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if ($locked->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            if (! Gate::forUser($actor)->allows('inviteAs', [$locked, $role])) {
                throw new DomainConflictException('Your role in this team changed; reload and try again.');
            }

            if ($email !== null) {
                $this->assertInvitable($locked, $email);
            }

            ['token' => $token, 'hash' => $hash] = InvitationToken::generate();

            $invitation = new TeamInvitation;
            $invitation->forceFill([
                'team_id' => $locked->id,
                'email' => $email,
                'invited_role' => $role,
                'token_hash' => DB::raw("decode('".$hash."', 'hex')"),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(TeamInvitation::TTL_DAYS),
                'max_uses' => $email === null ? TeamInvitation::LINK_MAX_USES : TeamInvitation::EMAIL_MAX_USES,
                'use_count' => 0,
            ])->save();

            // Re-read without the hash (the raw expression is not a value the model can keep).
            $invitation = TeamInvitation::query()->select(TeamInvitation::COLUMNS)->findOrFail($invitation->id);

            if ($email !== null) {
                Notification::route('mail', $email)->notify(new TeamInvitationNotification(
                    teamName: $locked->name,
                    inviterName: $actor->name,
                    role: $role,
                    token: $token,
                    expiresAt: $invitation->expires_at,
                    locale: self::recipientLocale($email, $actor),
                ));

                // The in-app notification for an account behind the address (CORE-009, ADR-029 §2).
                InvitationSent::dispatch($invitation->id, $locked->id);
            }

            Activity::team($locked->id, $actor, 'team.invitation_created', [
                'invitation_id' => $invitation->id,
                'type' => $type->value,
                'role' => $role->value,
            ]);

            return ['invitation' => $invitation->setRelation('inviter', $actor), 'token' => $token];
        });
    }

    private function assertInvitable(Team $team, string $email): void
    {
        $isMember = TeamMembership::query()
            ->where('team_id', $team->id)
            ->whereHas('user', fn (Builder $user): Builder => $user->where('email', $email))
            ->exists();

        if ($isMember) {
            throw new DomainConflictException('This person is already a member of the team.');
        }

        $pending = TeamInvitation::query()
            ->where('team_id', $team->id)
            ->where('email', $email)
            ->whereNull('revoked_at')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pending) {
            throw new DomainConflictException('This address already has a pending invitation; revoke it to send a new one.');
        }
    }

    /**
     * The language of the account the address belongs to, if any, else the inviter's. The answer is
     * used for the email only and never reaches the inviter.
     */
    private static function recipientLocale(string $email, User $actor): string
    {
        $recipient = User::query()->where('email', $email)->first();

        return ($recipient ?? $actor)->preferredLocale();
    }
}

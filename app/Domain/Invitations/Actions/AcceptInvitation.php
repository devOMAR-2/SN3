<?php

namespace App\Domain\Invitations\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Invitations\InvitationToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Redeems an invitation token (API_SPEC.md §3.1 `POST /invitations/{token}/accept`, DATABASE.md
 * §11 "Invite acceptance"; ADR-025 §4). The caller is signed in with a verified email (route
 * middleware).
 *
 * Locks the team row, then the invitation, then checks in this order:
 * 1. unknown token, or a team that is gone or in the trash: 404;
 * 2. already a member of the team: their membership as it is (200), no use taken, role unchanged;
 * 3. archived team: 423;
 * 4. revoked, used up or expired: 410 `expired`;
 * 5. an email invitation for another address: 403.
 * Otherwise the membership is created with the invited role and one use is taken atomically. Never
 * grants a project membership, private or not.
 */
final class AcceptInvitation
{
    /**
     * @return array{team: Team, membership: TeamMembership, created: bool}
     */
    public function handle(string $token, User $user): array
    {
        if (! InvitationToken::isWellFormed($token)) {
            throw (new ModelNotFoundException)->setModel(TeamInvitation::class);
        }

        $found = TeamInvitation::whereTokenHash(InvitationToken::hash($token))->first();

        if ($found === null) {
            throw (new ModelNotFoundException)->setModel(TeamInvitation::class);
        }

        return DB::transaction(function () use ($found, $user): array {
            // Team first, like every team writer (ADR-018 §4); a trashed team is not found.
            $team = Team::query()->whereKey($found->team_id)->lock(Team::WRITE_LOCK)->first();

            $invitation = TeamInvitation::query()
                ->select(TeamInvitation::COLUMNS)
                ->whereKey($found->id)
                ->lockForUpdate()
                ->first();

            if ($team === null || $invitation === null) {
                throw (new ModelNotFoundException)->setModel(TeamInvitation::class);
            }

            $existing = TeamMembership::query()->where('team_id', $team->id)->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return ['team' => $team, 'membership' => $existing, 'created' => false];
            }

            if ($team->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            if (! $invitation->isRedeemable()) {
                throw new HttpException(410);
            }

            if ($invitation->email !== null && $invitation->email !== User::normalizeEmail($user->email)) {
                throw new AuthorizationException('This invitation was sent to a different email address.');
            }

            $membership = new TeamMembership;
            $membership->forceFill([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => $invitation->invited_role,
                'joined_at' => now(),
            ])->save();

            // Under the row lock, so the guarded UPDATE always takes the use; the condition and the
            // table's CHECKs refuse an over-use whatever the code above does.
            $taken = DB::update(
                'UPDATE team_invitations
                    SET use_count = use_count + 1,
                        accepted_at = CASE WHEN use_count + 1 = max_uses THEN now() ELSE accepted_at END,
                        updated_at = now()
                  WHERE id = ? AND use_count < max_uses AND revoked_at IS NULL AND expires_at > now()',
                [$invitation->id],
            );

            if ($taken !== 1) {
                throw new HttpException(410);
            }

            // The membership goes into the append-only audit like every other one (NFR-SEC-005).
            Activity::team($team->id, $user, 'team.member_added', [
                'user_id' => $user->id,
                'role' => $invitation->invited_role->value,
                'invitation_id' => $invitation->id,
            ]);

            return ['team' => $team, 'membership' => $membership, 'created' => true];
        });
    }
}

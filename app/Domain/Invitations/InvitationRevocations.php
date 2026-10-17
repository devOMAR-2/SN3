<?php

namespace App\Domain\Invitations;

use App\Domain\Activity\Activity;
use App\Models\TeamInvitation;

/**
 * Revokes the pending invitations a team member issued when they lose the right to issue them
 * (ADR-025 §5): an invitation never grants more than its issuer could grant today.
 *
 * Called inside the team writers' transactions, after the team row lock (ADR-018 §4):
 * - leaving or being removed (RemoveMember): all of that member's pending invitations;
 * - a role change to Member or Viewer (ChangeMemberRole): all of them;
 * - an ownership transfer (TransferOwnership): the former Owner's pending Admin invitations, since
 *   an Admin may invite Members and Viewers only.
 */
final class InvitationRevocations
{
    /**
     * @return int The number of invitations revoked.
     */
    public static function revokeIssuedBy(string $teamId, string $userId, string $reason, bool $adminInvitationsOnly = false): int
    {
        $query = TeamInvitation::query()
            ->where('team_id', $teamId)
            ->where('invited_by', $userId)
            ->whereNull('revoked_at')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());

        if ($adminInvitationsOnly) {
            $query->where('invited_role', 'admin');
        }

        $revoked = $query->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($revoked > 0) {
            // A consequence of the membership change that has its own entry: recorded by the system.
            Activity::team($teamId, null, 'team.invitations_revoked', [
                'issuer_id' => $userId,
                'reason' => $reason,
                'count' => $revoked,
            ], Activity::SOURCE_SYSTEM);
        }

        return $revoked;
    }
}

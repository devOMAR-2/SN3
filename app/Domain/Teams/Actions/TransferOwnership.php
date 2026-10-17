<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Invitations\InvitationRevocations;
use App\Domain\Tasks\TaskAssignments;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfers team ownership (FR-TEAM-005, DATABASE.md §11): one transaction that locks the team row
 * and both memberships, then demotes the current Owner to Admin, promotes the new Owner and updates
 * `teams.owner_user_id`. The team row lock serialises concurrent transfers, and the deferred owner
 * triggers re-check the result at COMMIT.
 */
final class TransferOwnership
{
    public function handle(Team $team, User $currentOwner, string $newOwnerId): Team
    {
        if ($newOwnerId === $currentOwner->id) {
            throw ValidationException::withMessages(['new_owner_user_id' => 'different']);
        }

        return DB::transaction(function () use ($team, $currentOwner, $newOwnerId): Team {
            // Always lock the team first, then memberships: one order for every writer avoids deadlocks.
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            // A concurrent transfer may have moved ownership while this request waited for the lock.
            if ($locked->owner_user_id !== $currentOwner->id) {
                throw new DomainConflictException('Ownership changed while the transfer was waiting.');
            }

            $memberships = TeamMembership::query()
                ->where('team_id', $locked->id)
                ->whereIn('user_id', [$currentOwner->id, $newOwnerId])
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $outgoing = $memberships->get($currentOwner->id);
            $incoming = $memberships->get($newOwnerId);

            if ($incoming === null) {
                throw ValidationException::withMessages(['new_owner_user_id' => 'not_member']);
            }

            if ($outgoing === null) {
                throw new DomainConflictException('The current Owner has no owner membership.');
            }

            // Demote first: at most one owner membership may exist at any moment (partial unique index).
            $outgoing->role = TeamRole::Admin;
            $outgoing->save();

            $incoming->role = TeamRole::Owner;
            $incoming->save();

            $locked->owner_user_id = $newOwnerId;
            $locked->save();

            // The former Owner, now an Admin, keeps only explicit private memberships (ADR-023 §5).
            TaskAssignments::releaseIneligible($locked->id, $currentOwner->id, null, 'ownership_transferred');

            // An Admin may not invite Admins: the former Owner's pending Admin invitations go (ADR-025 §5).
            InvitationRevocations::revokeIssuedBy($locked->id, $currentOwner->id, 'ownership_transferred', adminInvitationsOnly: true);

            Activity::team($locked->id, $currentOwner, 'team.ownership_transferred', ['from_user_id' => $currentOwner->id, 'to_user_id' => $newOwnerId]);

            return $locked->setRelation('memberships', $locked->memberships()->where('user_id', $currentOwner->id)->get());
        });
    }
}

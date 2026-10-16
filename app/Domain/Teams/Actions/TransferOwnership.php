<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $locked = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

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

            Log::info('team.ownership_transferred', [
                'team_id' => $locked->id,
                'from_user_id' => $currentOwner->id,
                'to_user_id' => $newOwnerId,
            ]);

            return $locked->setRelation('memberships', $locked->memberships()->where('user_id', $currentOwner->id)->get());
        });
    }
}

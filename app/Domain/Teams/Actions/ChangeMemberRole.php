<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets a member's role to Admin, Member or Viewer (FR-TEAM-004). The Owner's role changes only
 * through TransferOwnership, so the Owner membership is never touched here.
 */
final class ChangeMemberRole
{
    public function handle(Team $team, TeamMembership $membership, TeamRole $role): TeamMembership
    {
        if ($role === TeamRole::Owner) {
            throw new InvalidArgumentException('Ownership moves only through an ownership transfer.');
        }

        if ($team->isArchived()) {
            throw new ResourceLockedException('Archived teams are read-only.');
        }

        return DB::transaction(function () use ($team, $membership, $role): TeamMembership {
            $locked = TeamMembership::query()
                ->whereKey($membership->id)
                ->where('team_id', $team->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === TeamRole::Owner) {
                throw new DomainConflictException('The Owner role changes only by transfer.');
            }

            $locked->role = $role;
            $locked->save();

            return $locked->setRelation('user', $membership->user);
        });
    }
}

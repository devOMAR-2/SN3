<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Tasks\TaskAssignments;
use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sets a member's role to Admin, Member or Viewer (FR-TEAM-004). The Owner's role changes only
 * through TransferOwnership, so the Owner membership is never touched here.
 *
 * Like every team-membership writer, it locks the team row first and re-checks the archived flag
 * and the actor's ownership on the locked row (ADR-018 §4), so it cannot interleave with a
 * transfer or an archive.
 */
final class ChangeMemberRole
{
    public function handle(Team $team, User $actor, TeamMembership $membership, TeamRole $role): TeamMembership
    {
        if ($role === TeamRole::Owner) {
            throw new InvalidArgumentException('Ownership moves only through an ownership transfer.');
        }

        return DB::transaction(function () use ($team, $actor, $membership, $role): TeamMembership {
            $lockedTeam = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($lockedTeam->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            if ($lockedTeam->owner_user_id !== $actor->id) {
                throw new DomainConflictException('Team ownership changed; reload and try again.');
            }

            $locked = TeamMembership::query()
                ->whereKey($membership->id)
                ->where('team_id', $team->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === TeamRole::Owner) {
                throw new DomainConflictException('The Owner role changes only by transfer.');
            }

            $from = $locked->role;
            $locked->role = $role;
            $locked->save();

            // A Team Viewer cannot be an assignee (API_SPEC.md §6.1, ADR-023 §5).
            TaskAssignments::releaseIneligible($team->id, $locked->user_id, null, 'team_role_changed');

            Log::info('team.member_role_changed', [
                'team_id' => $team->id,
                'user_id' => $locked->user_id,
                'actor_id' => $actor->id,
                'from' => $from->value,
                'to' => $role->value,
            ]);

            return $locked->setRelation('user', $membership->user);
        });
    }
}

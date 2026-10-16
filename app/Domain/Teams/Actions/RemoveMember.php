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

/**
 * Removes a member or lets a member leave (FR-TEAM-005/006, API_SPEC.md §3).
 *
 * The Owner can never be removed and cannot leave without transferring ownership first
 * (409, acceptance scenario TEAM-01). Access ends with the membership row: every request
 * re-resolves the team through AccessibleTeams. Project memberships (CORE-003) must be removed
 * in the same transaction once they exist.
 *
 * The team row is locked first and the archived flag and the actor's right to remove someone else
 * are re-checked on it (ADR-018 §4).
 */
final class RemoveMember
{
    public function handle(Team $team, User $actor, TeamMembership $membership): void
    {
        DB::transaction(function () use ($team, $actor, $membership): void {
            $lockedTeam = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($lockedTeam->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            $locked = TeamMembership::query()
                ->whereKey($membership->id)
                ->where('team_id', $team->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === TeamRole::Owner) {
                throw new DomainConflictException('The Owner cannot leave or be removed without transferring ownership.');
            }

            if ($locked->user_id !== $actor->id && $lockedTeam->owner_user_id !== $actor->id) {
                throw new DomainConflictException('Team ownership changed; reload and try again.');
            }

            $locked->delete();

            Log::info('team.member_removed', [
                'team_id' => $team->id,
                'user_id' => $locked->user_id,
                'actor_id' => $actor->id,
            ]);
        });
    }
}

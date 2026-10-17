<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Invitations\InvitationRevocations;
use App\Domain\Tasks\TaskAssignments;
use App\Domain\Teams\TeamRole;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member or lets a member leave (FR-TEAM-005/006, API_SPEC.md §3).
 *
 * The Owner can never be removed and cannot leave without transferring ownership first
 * (409, acceptance scenario TEAM-01). Access ends with the membership row: every request
 * re-resolves the team through AccessibleTeams. The member's project memberships in this team are
 * deleted in the same transaction (ADR-018 §6, ADR-020 §6); the database refuses to delete a team
 * membership that still has project memberships, so they can never outlive it.
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

            ProjectMembership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $locked->user_id)
                ->delete();

            // Before the membership row: the tasks' assignee key refuses a removal that leaves one.
            TaskAssignments::releaseUser($team->id, $locked->user_id, 'team_member_removed');

            $locked->delete();

            // An invitation never grants more than its issuer could grant today (ADR-025 §5).
            InvitationRevocations::revokeIssuedBy($team->id, $locked->user_id, 'team_member_removed');

            // A team entry: no count of the member's project memberships, which may be private.
            Activity::team($team->id, $actor, 'team.member_removed', ['user_id' => $locked->user_id]);
        });
    }
}

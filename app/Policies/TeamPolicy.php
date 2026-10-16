<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Team-level permissions from API_SPEC.md §3 (role column). Non-members are denied every
 * ability; in HTTP flows they never get this far, because AccessibleTeams answers 404 first.
 *
 * Resource-state rules (archived teams are read-only, ARCHITECTURE.md §5.2 step 6) and role
 * assignment limits (who may grant which role) belong to the team actions that use these checks.
 */
class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->roleOf($user) !== null;
    }

    /** PATCH /teams/{team}: name and slug. */
    public function update(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->managesTeam() ?? false;
    }

    /** Create, list and revoke invitations. */
    public function invite(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->managesTeam() ?? false;
    }

    public function createProject(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->managesTeam() ?? false;
    }

    /** PATCH /teams/{team}/members/{member}: Owner only; the Owner's own role changes only by transfer. */
    public function changeMemberRole(User $user, Team $team, User $member): bool
    {
        return $this->isOwner($user, $team) && $member->id !== $team->owner_user_id;
    }

    /** DELETE /teams/{team}/members/{member}: Owner removes others, anyone may leave, the Owner never leaves. */
    public function removeMember(User $user, Team $team, User $member): bool
    {
        if ($member->id === $team->owner_user_id || $team->roleOf($member) === null) {
            return false;
        }

        return $member->id === $user->id ? $team->roleOf($user) !== null : $this->isOwner($user, $team);
    }

    public function transferOwnership(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    /** POST /teams/{team}/archive and POST /teams/{team}/restore (unarchive). */
    public function archive(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    /** DELETE /teams/{team}: move to the 30-day trash. */
    public function delete(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    /** POST /teams/{team}/restore-deleted: recover from the trash. */
    public function restore(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    public function export(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    private function isOwner(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->isOwner() ?? false;
    }
}

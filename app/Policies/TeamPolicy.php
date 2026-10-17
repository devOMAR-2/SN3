<?php

namespace App\Policies;

use App\Domain\Teams\TeamRole;
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

    /**
     * Invite with this role (API_SPEC.md §3, §3.1): an Admin invites Members and Viewers; only the
     * Owner may invite an Admin (the request rules allow that on email invitations only); no
     * invitation grants ownership.
     */
    public function inviteAs(User $user, Team $team, TeamRole $role): bool
    {
        return match ($role) {
            TeamRole::Owner => false,
            TeamRole::Admin => $this->isOwner($user, $team),
            TeamRole::Member, TeamRole::Viewer => $this->invite($user, $team),
        };
    }

    public function createProject(User $user, Team $team): bool
    {
        return $team->roleOf($user)?->managesTeam() ?? false;
    }

    /** Only the Owner assigns roles (API_SPEC.md §3: "Owner alone may assign Admin"). */
    public function changeRoles(User $user, Team $team): bool
    {
        return $this->isOwner($user, $team);
    }

    /** PATCH /teams/{team}/members/{member}: Owner only; the Owner's own role changes only by transfer. */
    public function changeMemberRole(User $user, Team $team, User $member): bool
    {
        return $this->changeRoles($user, $team) && $member->id !== $team->owner_user_id;
    }

    /**
     * DELETE /teams/{team}/members/{member}: the Owner removes others; any member may try to leave.
     * The Owner leaving is then refused by RemoveMember (409, TEAM-01), not here.
     */
    public function removeMember(User $user, Team $team, User $member): bool
    {
        if ($team->roleOf($member) === null) {
            return false;
        }

        return $member->id === $user->id || $this->isOwner($user, $team);
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

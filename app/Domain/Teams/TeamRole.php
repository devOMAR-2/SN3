<?php

namespace App\Domain\Teams;

/**
 * Team roles (DATABASE.md §3 `team_memberships.role`, API_SPEC.md §3).
 *
 * Admin manages team settings but never gains implicit access to private-project content
 * (DECIDED-003); that rule lives with project authorization, not here.
 */
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Owner or Admin: team settings, invitations and project creation.
     */
    public function managesTeam(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}

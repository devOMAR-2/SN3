<?php

namespace App\Http\Resources;

use App\Models\TeamMembership;

/**
 * A team member as seen by another member. Email addresses are shown only to people who manage
 * the team (Owner/Admin), who need them to manage membership; other members see name and role.
 */
final class TeamMemberResource
{
    /**
     * @return array{user_id: string, name: string, email?: string, role: string, joined_at: string}
     */
    public static function make(TeamMembership $membership, bool $withEmail): array
    {
        $member = [
            'user_id' => $membership->user_id,
            'name' => $membership->user->name,
            'role' => $membership->role->value,
            'joined_at' => $membership->joined_at->utc()->toIso8601ZuluString(),
        ];

        if ($withEmail) {
            $member['email'] = $membership->user->email;
        }

        return $member;
    }
}

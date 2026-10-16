<?php

namespace App\Http\Resources;

use App\Models\ProjectMembership;

/**
 * An explicit project member. As in the team member list (ADR-018 §5), email addresses are shown
 * only to the Team Owner and Admins, so a project never exposes more than the team already does.
 */
final class ProjectMemberResource
{
    /**
     * @return array{user_id: string, name: string, email?: string, role: string, added_by: string, added_at: string|null}
     */
    public static function make(ProjectMembership $membership, bool $withEmail): array
    {
        $member = [
            'user_id' => $membership->user_id,
            'name' => $membership->user->name,
            'role' => $membership->project_role->value,
            'added_by' => $membership->added_by,
            'added_at' => $membership->created_at?->utc()->toIso8601ZuluString(),
        ];

        if ($withEmail) {
            $member['email'] = $membership->user->email;
        }

        return $member;
    }
}

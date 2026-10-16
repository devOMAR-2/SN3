<?php

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\User;

/**
 * A team as seen by one of its members. `role` is the viewer's own role in the team.
 */
final class TeamResource
{
    /**
     * @return array{id: string, name: string, slug: string, description: string|null, owner_user_id: string, role: string|null, archived_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function make(Team $team, User $viewer): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'description' => $team->description,
            'owner_user_id' => $team->owner_user_id,
            'role' => $team->roleOf($viewer)?->value,
            'archived_at' => $team->archived_at?->utc()->toIso8601ZuluString(),
            'created_at' => $team->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $team->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

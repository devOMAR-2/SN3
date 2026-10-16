<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\User;

/**
 * A project as seen by a user who may view it. `role` is the viewer's own explicit project role
 * (`manager`, `contributor`, or null without a membership row, e.g. the Team Owner).
 */
final class ProjectResource
{
    /**
     * @return array{id: string, team_id: string, name: string, key: string, description: string|null, visibility: string, role: string|null, created_by: string, version: int, archived_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function make(Project $project, User $viewer): array
    {
        return [
            'id' => $project->id,
            'team_id' => $project->team_id,
            'name' => $project->name,
            'key' => $project->project_key,
            'description' => $project->description,
            'visibility' => $project->visibility->value,
            'role' => $project->roleOf($viewer)?->value,
            'created_by' => $project->created_by,
            'version' => $project->version,
            'archived_at' => $project->archived_at?->utc()->toIso8601ZuluString(),
            'created_at' => $project->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $project->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Project;

/**
 * Effective project configuration (API_SPEC.md §5 `GET /projects/{project}/settings`).
 *
 * - `version` is the project's version: `PATCH /settings` and `PATCH /projects/{project}` share it.
 * - `key_frozen` is true once a task number has been issued. From then on the key can no longer
 *   change (PROJ-01), so a client can stop offering it.
 */
final class ProjectSettingsResource
{
    /**
     * @return array{project_id: string, sprints_enabled: bool, key_frozen: bool, version: int}
     */
    public static function make(Project $project): array
    {
        return [
            'project_id' => $project->id,
            'sprints_enabled' => $project->sprints_enabled,
            'key_frozen' => $project->keyIsFrozen(),
            'version' => $project->version,
        ];
    }
}

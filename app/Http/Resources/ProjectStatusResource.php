<?php

namespace App\Http\Resources;

use App\Models\ProjectStatus;

/**
 * A workflow status (API_SPEC.md §5). `position` is the 0-based board ordinal; `is_default` marks
 * the status new tasks start in.
 */
final class ProjectStatusResource
{
    /**
     * @return array{id: string, project_id: string, name: string, category: string, color: string|null, position: int, is_default: bool, version: int, created_at: string|null, updated_at: string|null}
     */
    public static function make(ProjectStatus $status): array
    {
        return [
            'id' => $status->id,
            'project_id' => $status->project_id,
            'name' => $status->name,
            'category' => $status->category->value,
            'color' => $status->color,
            'position' => $status->position,
            'is_default' => $status->is_default,
            'version' => $status->version,
            'created_at' => $status->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $status->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\TaskActivity;

/**
 * An activity entry (API_SPEC.md §9 "actor, action, timestamp and authorized resource reference";
 * CORE-008, ADR-027 §4). `changes` holds IDs, enum values, dates and field names only.
 */
final class ActivityResource
{
    /**
     * @return array{id: string, team_id: string, project_id: string|null, task_id: string|null, actor_user_id: string|null, action: string, changes: array<string, mixed>|object, source: string, created_at: string|null}
     */
    public static function make(TaskActivity $entry): array
    {
        return [
            'id' => $entry->id,
            'team_id' => $entry->team_id,
            'project_id' => $entry->project_id,
            'task_id' => $entry->task_id,
            'actor_user_id' => $entry->actor_user_id,
            'action' => $entry->action,
            // Always a JSON object, also when empty.
            'changes' => $entry->changes === [] ? (object) [] : $entry->changes,
            'source' => $entry->source,
            'created_at' => $entry->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

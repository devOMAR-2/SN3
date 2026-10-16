<?php

namespace App\Http\Resources;

use App\Models\TaskChecklistItem;

/**
 * A checklist item (API_SPEC.md §6.2, ADR-023 §7). `position` is the 0-based ordinal.
 */
final class ChecklistItemResource
{
    /**
     * @return array{id: string, task_id: string, text: string, checked: bool, position: int, completed_by: string|null, completed_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function make(TaskChecklistItem $item): array
    {
        return [
            'id' => $item->id,
            'task_id' => $item->task_id,
            'text' => $item->content,
            'checked' => $item->is_done,
            'position' => $item->position,
            'completed_by' => $item->completed_by,
            'completed_at' => $item->completed_at?->utc()->toIso8601ZuluString(),
            'created_at' => $item->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $item->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

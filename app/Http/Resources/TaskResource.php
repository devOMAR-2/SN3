<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Task;

/**
 * A task as seen by a user who may view its project (API_SPEC.md §6.1, ADR-023 §3).
 *
 * `key` is `{project_key}-{number}` (FR-TASK-003). `label_ids` are sorted, so the representation is
 * stable. `sprint_id`, `story_points` and the board `position` are not part of it yet: sprints come
 * with FLEX-005, task moves with CORE-007.
 */
final class TaskResource
{
    /**
     * @return array{id: string, project_id: string, key: string, number: int, title: string, description: string|null, status_id: string, priority: string, assignee_user_id: string|null, due_date: string|null, label_ids: list<string>, parent_task_id: string|null, created_by: string, version: int, created_at: string|null, updated_at: string|null}
     */
    public static function make(Task $task, Project $project): array
    {
        $labels = $task->relationLoaded('labels') ? $task->labels : $task->labels()->get(['labels.id']);
        $labelIds = [];

        foreach ($labels as $label) {
            $labelIds[] = $label->id;
        }

        sort($labelIds);

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'key' => $task->key($project),
            'number' => $task->task_number,
            'title' => $task->title,
            'description' => $task->description,
            'status_id' => $task->status_id,
            'priority' => $task->priority->value,
            'assignee_user_id' => $task->assignee_user_id,
            'due_date' => $task->due_date?->toDateString(),
            'label_ids' => $labelIds,
            'parent_task_id' => $task->parent_task_id,
            'created_by' => $task->created_by,
            'version' => $task->version,
            'created_at' => $task->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $task->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

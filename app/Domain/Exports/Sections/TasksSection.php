<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\Project;
use App\Models\Task;

/**
 * `data/tasks.json`: the live tasks of the exported projects (CORE-005, ADR-023 §11). Tasks in the
 * task trash are left out, as trashed projects are (ADR-024 §3): no read path shows them. `key` is
 * `{project_key}-{number}`; `position` is the stored rank within the task's status, as a decimal
 * string (it is fractional once tasks are moved, CORE-007). The generated sort columns
 * (`priority_rank`, `due_sort`) and the concurrency `version` are not data and stay out.
 */
final class TasksSection implements TeamExportSection
{
    public function name(): string
    {
        return 'tasks';
    }

    public function records(TeamExportContext $context): iterable
    {
        /** @var array<string, string> $keys */
        $keys = Project::query()->whereIn('id', $context->projectIds())->pluck('project_key', 'id')->all();

        $tasks = Task::query()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->lazyById(500);

        foreach ($tasks as $task) {
            yield [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'key' => ($keys[$task->project_id] ?? '').'-'.$task->task_number,
                'number' => $task->task_number,
                'title' => $task->title,
                'description' => $task->description,
                'status_id' => $task->status_id,
                'position' => $task->position,
                'priority' => $task->priority->value,
                'assignee_user_id' => $task->assignee_user_id,
                'due_date' => $task->due_date?->toDateString(),
                'parent_task_id' => $task->parent_task_id,
                'created_by' => $task->created_by,
                'created_at' => TeamExportContext::timestamp($task->created_at),
                'updated_at' => TeamExportContext::timestamp($task->updated_at),
            ];
        }
    }
}

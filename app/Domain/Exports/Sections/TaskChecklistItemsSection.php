<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * `data/task_checklist_items.json`: checklist items of the exported, live tasks (CORE-005, ADR-023
 * §7), with the API's field names (`text`, `checked`; the columns are `content` and `is_done`).
 * `position` is the 0-based order within the task.
 */
final class TaskChecklistItemsSection implements TeamExportSection
{
    public function name(): string
    {
        return 'task_checklist_items';
    }

    public function records(TeamExportContext $context): iterable
    {
        $items = TaskChecklistItem::query()
            ->whereHas('task', function (Builder $tasks) use ($context): void {
                /** @var Builder<Task> $tasks */
                $tasks->where('team_id', $context->team->id)->whereIn('project_id', $context->projectIds());
            })
            ->lazyById(500);

        foreach ($items as $item) {
            yield [
                'id' => $item->id,
                'task_id' => $item->task_id,
                'text' => $item->content,
                'checked' => $item->is_done,
                'position' => $item->position,
                'completed_by' => $item->completed_by,
                'completed_at' => TeamExportContext::timestamp($item->completed_at),
                'created_at' => TeamExportContext::timestamp($item->created_at),
                'updated_at' => TeamExportContext::timestamp($item->updated_at),
            ];
        }
    }
}

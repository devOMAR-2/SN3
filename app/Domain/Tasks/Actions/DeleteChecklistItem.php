<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Tasks\Checklist;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a checklist item (API_SPEC.md §6.2 DELETE, "Project writer"; ADR-023 §7) and closes up the
 * ranks of the items after it.
 */
final class DeleteChecklistItem
{
    public function handle(Task $task, TaskChecklistItem $item, User $actor): void
    {
        DB::transaction(function () use ($task, $item, $actor): void {
            ['task' => $locked, 'items' => $items] = Checklist::lock($task, $actor);

            $row = $items->firstWhere('id', $item->id)
                ?? throw (new ModelNotFoundException)->setModel(TaskChecklistItem::class, [$item->id]);

            $row->delete();

            Checklist::write($locked->id, $items
                ->reject(fn (TaskChecklistItem $other): bool => $other->id === $row->id)
                ->map(fn (TaskChecklistItem $other): string => $other->id)
                ->values()
                ->all());

            Activity::task($locked, $actor, 'task.checklist_item_deleted', ['item_id' => $row->id]);
        });
    }
}

<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Checklist;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds a checklist item at the end of a task's checklist (API_SPEC.md §6.2 POST, "Project writer";
 * ADR-023 §7). The 101st item of a task is a 409 (§6.2: at most 100 items per task).
 */
final class CreateChecklistItem
{
    public function handle(Task $task, User $actor, string $text): TaskChecklistItem
    {
        return DB::transaction(function () use ($task, $actor, $text): TaskChecklistItem {
            ['project' => $project, 'task' => $locked, 'items' => $items] = Checklist::lock($task, $actor);

            if ($items->count() >= Checklist::MAX_ITEMS) {
                throw new DomainConflictException('A task holds at most '.Checklist::MAX_ITEMS.' checklist items.');
            }

            $item = new TaskChecklistItem;
            $item->forceFill([
                'task_id' => $locked->id,
                'content' => $text,
                'position' => $items->count(),
            ])->save();

            Log::info('task.checklist_item_created', [
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'task_id' => $locked->id,
                'item_id' => $item->id,
                'actor_id' => $actor->id,
            ]);

            return $item->refresh();
        });
    }
}

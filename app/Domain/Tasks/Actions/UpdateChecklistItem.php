<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Checklist;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Edits a checklist item's text, ticks or unticks it, or moves it (API_SPEC.md §6.2 PATCH "text,
 * checked, position"; ADR-023 §7).
 *
 * - `checked: true` records who ticked it and when; `false` clears both. Re-sending the current
 *   state changes nothing, so a toggle retried or sent by two people at once is harmless.
 * - `position` is the 0-based index the item moves to; the other items close up around it. An index
 *   past the last item is 422 `max`.
 *
 * Checklist items are not versioned (§1.4 lists Task, not its checklist): edits are serialised by the
 * team → project → task → items locks and do not change the task's `version`.
 */
final class UpdateChecklistItem
{
    /**
     * @param  array{text?: string, checked?: bool, position?: int}  $changes
     */
    public function handle(Task $task, TaskChecklistItem $item, User $actor, array $changes): TaskChecklistItem
    {
        return DB::transaction(function () use ($task, $item, $actor, $changes): TaskChecklistItem {
            ['project' => $project, 'task' => $locked, 'items' => $items] = Checklist::lock($task, $actor);

            $row = $items->firstWhere('id', $item->id)
                ?? throw (new ModelNotFoundException)->setModel(TaskChecklistItem::class, [$item->id]);

            if (array_key_exists('text', $changes)) {
                $row->content = $changes['text'];
            }

            if (array_key_exists('checked', $changes) && $changes['checked'] !== $row->is_done) {
                $row->is_done = $changes['checked'];
                $row->completed_by = $changes['checked'] ? $actor->id : null;
                $row->completed_at = $changes['checked'] ? now() : null;
            }

            $moved = false;

            if (array_key_exists('position', $changes) && $changes['position'] !== $row->position) {
                if ($changes['position'] >= $items->count()) {
                    throw ValidationException::withMessages(['position' => 'max']);
                }

                $order = array_values($items->reject(fn (TaskChecklistItem $other): bool => $other->id === $row->id)
                    ->map(fn (TaskChecklistItem $other): string => $other->id)
                    ->all());
                array_splice($order, $changes['position'], 0, [$row->id]);

                Checklist::write($locked->id, $order);
                $moved = true;
            }

            if ($row->isDirty()) {
                $row->save();
            }

            if ($moved || $row->wasChanged()) {
                Log::info('task.checklist_item_updated', [
                    'team_id' => $project->team_id,
                    'project_id' => $project->id,
                    'task_id' => $locked->id,
                    'item_id' => $row->id,
                    'actor_id' => $actor->id,
                    'fields' => array_values(array_filter([
                        $row->wasChanged('content') ? 'text' : null,
                        $row->wasChanged('is_done') ? 'checked' : null,
                        $moved ? 'position' : null,
                    ])),
                ]);
            }

            return $row->refresh();
        });
    }
}

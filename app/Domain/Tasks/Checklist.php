<?php

namespace App\Domain\Tasks;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared steps of the checklist writers (API_SPEC.md §6.2, ADR-023 §7).
 *
 * Every writer locks team → project (`contribute`: a Team Viewer never writes, an archived team or
 * project is 423) → task (a task trashed meanwhile is a 404) → the task's items, in that order, and
 * keeps the ranks dense (`0..n-1`).
 */
final class Checklist
{
    /** At most this many items per task (API_SPEC.md §6.2). */
    public const MAX_ITEMS = 100;

    public const TEXT_MAX = 300;

    /**
     * @return array{project: Project, task: Task, items: Collection<int, TaskChecklistItem>}
     */
    public static function lock(Task $task, User $actor): array
    {
        $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
        $locked = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();
        $items = $locked->checklistItems()->orderBy('position')->orderBy('id')->lockForUpdate()->get();

        return ['project' => $project, 'task' => $locked->setRelation('project', $project), 'items' => $items];
    }

    /**
     * Rewrites the ranks of a task's items in the given order, in one statement
     * (`UNIQUE(task_id, position)` is checked at its end).
     *
     * @param  array<int, string>  $orderedIds  every remaining item of the task
     */
    public static function write(string $taskId, array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach (array_values($orderedIds) as $position => $id) {
            $values[] = '(?::uuid, ?::numeric)';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        DB::update(
            'UPDATE task_checklist_items AS i SET position = v.position, updated_at = now() '
            .'FROM (VALUES '.implode(', ', $values).') AS v(id, position) '
            .'WHERE i.task_id = ? AND i.id = v.id AND i.position <> v.position',
            [...$bindings, $taskId],
        );
    }
}

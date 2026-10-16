<?php

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Subtask rules (API_SPEC.md §6.1 `parent_task_id`, DATABASE.md §5 and §19, TASK-01, TASK-02):
 * a parent is a live task of the same project, the tree is at most three levels below its root
 * (root depth 0, deepest depth 3), and there are no cycles.
 *
 * Callers hold the project write lock (ProjectWriteLock), and every task writer of the project takes
 * it first, so no other change to the tree can interleave between these checks and the write. The
 * walks are bounded and include trashed tasks, so restoring a subtask can never break the depth rule.
 */
final class TaskHierarchy
{
    public const MAX_DEPTH = 3;

    /**
     * Checks a new parent for a task that is created (`$task` null) or moved under it.
     *
     * - 422 `exists`: not a live task of this project (a hidden, trashed or foreign ID included);
     * - 422 `cycle`: the task itself or one of its descendants;
     * - 422 `max_depth`: the task and its subtree would end up deeper than three levels.
     */
    public static function assertParent(Project $lockedProject, ?Task $task, string $parentId): void
    {
        $exists = $lockedProject->tasks()->whereKey($parentId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['parent_task_id' => 'exists']);
        }

        $height = 0;

        if ($task !== null) {
            $subtree = self::subtree($task->id);

            if (in_array($parentId, $subtree['ids'], true)) {
                throw ValidationException::withMessages(['parent_task_id' => 'cycle']);
            }

            $height = $subtree['height'];
        }

        if (self::depth($parentId) + 1 + $height > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_task_id' => 'max_depth']);
        }
    }

    /**
     * Number of ancestors of a task (a root has depth 0).
     */
    public static function depth(string $taskId): int
    {
        $row = DB::selectOne(
            'WITH RECURSIVE up (id, parent_task_id, depth) AS ('
            .'SELECT id, parent_task_id, 0 FROM tasks WHERE id = ? '
            .'UNION ALL SELECT t.id, t.parent_task_id, up.depth + 1 FROM tasks AS t JOIN up ON t.id = up.parent_task_id WHERE up.depth < ?'
            .') SELECT max(depth) AS depth FROM up',
            [$taskId, self::MAX_DEPTH + 1],
        );

        return $row instanceof stdClass && is_numeric($row->depth ?? null) ? (int) $row->depth : 0;
    }

    /**
     * The task and its descendants (trashed ones included), and the height of that subtree.
     *
     * @return array{ids: list<string>, height: int}
     */
    private static function subtree(string $taskId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE down (id, depth) AS ('
            .'SELECT id, 0 FROM tasks WHERE id = ? '
            .'UNION ALL SELECT t.id, down.depth + 1 FROM tasks AS t JOIN down ON t.parent_task_id = down.id WHERE down.depth < ?'
            .') SELECT id, depth FROM down',
            [$taskId, self::MAX_DEPTH + 1],
        );

        $ids = [];
        $height = 0;

        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->id ?? null)) {
                $ids[] = $row->id;
                $height = max($height, is_numeric($row->depth ?? null) ? (int) $row->depth : 0);
            }
        }

        return ['ids' => $ids, 'height' => $height];
    }
}

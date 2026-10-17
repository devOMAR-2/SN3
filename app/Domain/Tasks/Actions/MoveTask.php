<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Tasks\Events\TaskChanged;
use App\Domain\Tasks\TaskOrder;
use App\Models\Task;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a task within or across the columns of its project's board (API_SPEC.md §6.1
 * `POST /tasks/{task}/move`; CORE-007, ADR-028 §2).
 *
 * `{ "status_id", "before_task_id", "after_task_id" }`: the task goes into `status_id`, just before
 * `before_task_id` or just after `after_task_id` (at most one is given), or to the end of the column
 * when neither is. Anchors must be live tasks of the same project in that status, and not the task
 * itself; anything else is 422 `exists` (`different` for the task itself), answered the same way for
 * another project's task and a random ID.
 *
 * Locks team → project (ProjectWriteLock, `contribute`) → task, then checks `If-Match` (423 wins over
 * 412). The new rank is the midpoint between the neighbours (TaskOrder::between); a column whose gap
 * has run out is renumbered first, inside the same transaction. A move to where the task already is
 * changes nothing and keeps the version; otherwise the version increments.
 */
final class MoveTask
{
    /**
     * @throws StaleVersionException
     */
    public function handle(Task $task, User $actor, int $expectedVersion, string $statusId, ?string $beforeTaskId, ?string $afterTaskId): Task
    {
        return DB::transaction(function () use ($task, $actor, $expectedVersion, $statusId, $beforeTaskId, $afterTaskId): Task {
            $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
            $from = $task->status_id;

            $moved = OptimisticLock::update($task, $expectedVersion, function (Task $locked) use ($project, $statusId, $beforeTaskId, $afterTaskId, &$from): void {
                $from = $locked->status_id;

                if (! $project->statuses()->whereKey($statusId)->exists()) {
                    throw ValidationException::withMessages(['status_id' => 'exists']);
                }

                $anchorField = $beforeTaskId !== null ? 'before_task_id' : 'after_task_id';
                $anchorId = $beforeTaskId ?? $afterTaskId;

                if ($anchorId !== null && $anchorId === $locked->id) {
                    throw ValidationException::withMessages([$anchorField => 'different']);
                }

                $current = $locked->status_id === $statusId ? $locked->position : null;
                $position = TaskOrder::place($project->id, $statusId, $locked->id, $beforeTaskId, $afterTaskId, $current);

                if ($position === null) {
                    throw ValidationException::withMessages([$anchorField => 'exists']);
                }

                if ($current !== null && TaskOrder::sameRank($current, $position)) {
                    return;
                }

                $locked->status_id = $statusId;
                $locked->position = $position;
            }, lock: Task::WRITE_LOCK);

            if ($moved->wasChanged()) {
                // In the move's transaction (ADR-027 §3); IDs only.
                Activity::task($moved, $actor, 'task.moved', ['status_id' => ['from' => $from, 'to' => $moved->status_id]]);

                // A status change notifies the task's watchers after commit, like a PATCH of `status_id`
                // (CORE-009, ADR-029 §2); a reorder within the column notifies nobody.
                if ($from !== $moved->status_id) {
                    TaskChanged::dispatch($moved->id, $project->id, $project->team_id, $actor->id, ['status_id'], null);
                }
            }

            return $moved->setRelation('project', $project);
        });
    }
}

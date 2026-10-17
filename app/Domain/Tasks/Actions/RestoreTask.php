<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Restores a soft-deleted task (API_SPEC.md §6.1 `POST /tasks/{id}/restore`, "Project
 * Manager/Owner"; ADR-023 §4).
 *
 * Like project restore-deleted (ADR-022 §6) it takes no `If-Match`, because no read path exposes a
 * trashed task's version, but it increments `version`, so a copy fetched before the deletion is stale
 * afterwards. Locks team → project (`restoreTasks`) → task. A task that a concurrent request already
 * restored is returned unchanged. A subtask whose parent is still in the trash is a 409: restore the
 * parent first.
 *
 * The task comes back exactly as it was. Its assignee was already cleared if they lost access while
 * it was in the trash (TaskAssignments), and labels deleted meanwhile are gone with their links.
 */
final class RestoreTask
{
    public function handle(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $project = ProjectWriteLock::project($task->project, $actor, 'restoreTasks');

            $locked = Task::withTrashed()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();

            if ($locked->deleted_at === null) {
                return $locked->setRelation('project', $project);
            }

            if ($locked->parent_task_id !== null && ! $project->tasks()->whereKey($locked->parent_task_id)->exists()) {
                throw new DomainConflictException('Restore the parent task first.');
            }

            $locked->deleted_at = null;
            $locked->version = $locked->version + 1;
            $locked->save();

            Activity::task($locked, $actor, 'task.restored');

            return $locked->setRelation('project', $project);
        });
    }
}

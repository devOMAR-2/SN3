<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a task (API_SPEC.md §6.1 DELETE "Project writer") against its `If-Match` version.
 *
 * Any project writer may delete any task of the project, as §6.1 says; only the Team Owner or a
 * project manager may restore it (RestoreTask). The task disappears from every list and detail route,
 * its number stays used and the project key stays frozen (ADR-021 §5). Nothing is purged: the row,
 * its labels and checklist stay until the project itself is purged (ADR-023 §4).
 *
 * A task with live subtasks is a 409: deleting it would leave them under a parent nobody can see.
 * Delete or move the subtasks first.
 */
final class TrashTask
{
    /**
     * @throws StaleVersionException
     */
    public function handle(Task $task, User $actor, int $expectedVersion): void
    {
        DB::transaction(function () use ($task, $actor, $expectedVersion): void {
            ProjectWriteLock::project($task->project, $actor, 'contribute');

            $trashed = OptimisticLock::update($task, $expectedVersion, function (Task $locked): void {
                if ($locked->children()->exists()) {
                    throw new DomainConflictException('Delete or move the subtasks first.');
                }

                $locked->deleted_at = now();
            }, lock: Task::WRITE_LOCK);

            Activity::task($trashed, $actor, 'task.trashed');
        });
    }
}

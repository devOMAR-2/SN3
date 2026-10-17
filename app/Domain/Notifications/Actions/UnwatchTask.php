<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /tasks/{task}/watchers/me (API_SPEC.md §6.1 "Unwatch task — Watcher"; CORE-009, ADR-029 §1).
 * The same locks and checks as WatchTask; a caller who does not watch the task gets 404 (there is no
 * such watcher).
 */
final class UnwatchTask
{
    public function handle(Task $task, User $actor): void
    {
        DB::transaction(function () use ($task, $actor): void {
            $project = ProjectWriteLock::project($task->project, $actor, 'view');
            $locked = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();

            $deleted = DB::table('task_watchers')->where('task_id', $locked->id)->where('user_id', $actor->id)->delete();

            if ($deleted === 0) {
                throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
            }
        });
    }
}

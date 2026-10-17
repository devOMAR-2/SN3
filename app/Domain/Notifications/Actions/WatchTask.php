<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * POST /tasks/{task}/watchers (API_SPEC.md §6.1 "Watch task — Project viewer with team membership";
 * CORE-009, ADR-029 §1). Anyone who can see the task may watch it, a Team Viewer included.
 *
 * Locks team → project (`view`: 423 when archived, 404 when the caller can no longer see the project)
 * → task (a task trashed meanwhile is a 404), like every project writer, so watching cannot interleave
 * with a membership change. Watching twice is not an error: the existing watch is returned.
 */
final class WatchTask
{
    /**
     * @return array{watch: array{task_id: string, user_id: string, created_at: string}, created: bool}
     */
    public function handle(Task $task, User $actor): array
    {
        return DB::transaction(function () use ($task, $actor): array {
            $project = ProjectWriteLock::project($task->project, $actor, 'view');
            $locked = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();

            $created = DB::table('task_watchers')->insertOrIgnore([
                'task_id' => $locked->id,
                'user_id' => $actor->id,
                'team_id' => $locked->team_id,
                'project_id' => $locked->project_id,
                'created_at' => now(),
            ]) === 1;

            $row = DB::table('task_watchers')->where('task_id', $locked->id)->where('user_id', $actor->id)->first(['created_at']);
            $createdAt = $row instanceof stdClass && is_string($row->created_at ?? null) ? Carbon::parse($row->created_at) : now();

            return [
                'watch' => ['task_id' => $locked->id, 'user_id' => $actor->id, 'created_at' => $createdAt->utc()->toIso8601ZuluString()],
                'created' => $created,
            ];
        });
    }
}

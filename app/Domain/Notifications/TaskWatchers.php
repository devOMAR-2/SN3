<?php

namespace App\Domain\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Task watchers (API_SPEC.md §6.1 `/tasks/{id}/watchers`, DECIDED-006 "watchers are separate entities";
 * CORE-009, ADR-029 §1). A watch is a personal subscription: only its owner adds or removes it, and
 * nobody else can list it. A watcher who can no longer see the task keeps the row but receives
 * nothing: recipients are filtered by access at send time and the inbox again at read time.
 */
final class TaskWatchers
{
    public static function watching(Task $task, User $user): bool
    {
        return DB::table('task_watchers')->where('task_id', $task->id)->where('user_id', $user->id)->exists();
    }

    /**
     * Every watcher of the task, by user ID.
     *
     * @return list<string>
     */
    public static function of(Task $task): array
    {
        $ids = [];

        foreach (DB::table('task_watchers')->where('task_id', $task->id)->orderBy('user_id')->pluck('user_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

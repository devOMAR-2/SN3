<?php

namespace App\Domain\Comments\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Collaboration\AuthoredContent;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a comment (API_SPEC.md §6.2 DELETE "Delete own or moderate": the author while they may
 * write, or the Team Owner or a project manager; CORE-008, ADR-027 §2).
 *
 * The comment is soft-deleted: it disappears from every read path at once, its mentions are removed
 * (nobody is notified from a deleted comment), and the activity log keeps only a `comment.deleted`
 * tombstone with the comment ID, never its content (§6.2). The row is pruned after the 30-day
 * recovery window (DATABASE.md §5, TaskComment::prunable()).
 */
final class DeleteComment
{
    public function handle(Task $task, TaskComment $comment, User $actor): void
    {
        DB::transaction(function () use ($task, $comment, $actor): void {
            $project = ProjectWriteLock::project($task->project, $actor, 'view');
            $lockedTask = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();
            $locked = $lockedTask->comments()->whereKey($comment->id)->lock(TaskComment::WRITE_LOCK)->firstOrFail();

            if (! AuthoredContent::mayDelete($actor, $project, $locked->author_user_id)) {
                throw new DomainConflictException('Comment access changed while the request waited; reload and try again.');
            }

            $locked->delete();
            DB::table('task_comment_mentions')->where('comment_id', $locked->id)->delete();

            Activity::task($lockedTask, $actor, 'comment.deleted', [
                'comment_id' => $locked->id,
                'moderated' => AuthoredContent::moderates($actor, $project, $locked->author_user_id),
            ]);
        });
    }
}

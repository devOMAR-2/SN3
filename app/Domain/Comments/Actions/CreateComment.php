<?php

namespace App\Domain\Comments\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Comments\CommentMentions;
use App\Domain\Comments\Events\CommentAdded;
use App\Domain\Comments\Events\UsersMentioned;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a comment to a task (API_SPEC.md §6.2 POST, "Project writer"; FR-COL-001; CORE-008, ADR-027 §2).
 *
 * Locks team → project (`contribute`: 423 when archived, 404 when the actor can no longer see the
 * project, 409 when they lost the right) → task (a task trashed meanwhile is a 404). In the same
 * transaction it stores the mentions the server resolved (CommentMentions) and the `comment.created`
 * activity entry; mentioned users (UsersMentioned) and the new comment (CommentAdded, for watchers) are
 * announced after commit.
 */
final class CreateComment
{
    public function handle(Task $task, User $actor, string $body): TaskComment
    {
        return DB::transaction(function () use ($task, $actor, $body): TaskComment {
            $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
            $locked = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();

            $comment = new TaskComment;
            $comment->forceFill([
                'team_id' => $locked->team_id,
                'project_id' => $locked->project_id,
                'task_id' => $locked->id,
                'author_user_id' => $actor->id,
                'body' => $body,
            ])->save();

            $mentioned = CommentMentions::sync($comment, CommentMentions::eligible($project, CommentMentions::candidates($body)));

            Activity::task($locked, $actor, 'comment.created', ['comment_id' => $comment->id, 'mentions' => count($mentioned)]);

            if ($mentioned !== []) {
                UsersMentioned::dispatch($comment->id, $locked->id, $project->id, $project->team_id, $actor->id, $mentioned);
            }

            // The task's watchers are notified after commit (CORE-009, ADR-029 §2).
            CommentAdded::dispatch($comment->id, $locked->id, $project->id, $project->team_id, $actor->id);

            return $comment;
        });
    }
}

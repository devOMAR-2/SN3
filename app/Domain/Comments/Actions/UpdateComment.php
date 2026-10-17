<?php

namespace App\Domain\Comments\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Collaboration\AuthoredContent;
use App\Domain\Comments\CommentMentions;
use App\Domain\Comments\Events\UsersMentioned;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Edits the author's own comment (API_SPEC.md §6.2 PATCH "Author; project write permission";
 * CORE-008, ADR-027 §2).
 *
 * Locks team → project → task → comment and re-checks on the locked state that the actor still may
 * edit it (409 otherwise; a comment deleted meanwhile is a 404). A real change sets `edited_at` and
 * records `comment.edited` (§6.2 "Comment edits keep edit timestamp and audit record"); the previous
 * text is not kept anywhere. Mentions are re-resolved from the new body: removed ones go, and only
 * newly added ones are announced. Re-sending the same body changes nothing.
 *
 * Comments are not versioned (§1.4 lists Task, Project, Sprint, workflow and Issue mappings), so there
 * is no If-Match: the row locks serialise concurrent edits, and the last one wins.
 */
final class UpdateComment
{
    public function handle(Task $task, TaskComment $comment, User $actor, string $body): TaskComment
    {
        return DB::transaction(function () use ($task, $comment, $actor, $body): TaskComment {
            $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
            $lockedTask = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();
            $locked = $lockedTask->comments()->whereKey($comment->id)->lock(TaskComment::WRITE_LOCK)->firstOrFail();

            if (! AuthoredContent::mayEdit($actor, $project, $locked->author_user_id)) {
                throw new DomainConflictException('Comment access changed while the request waited; reload and try again.');
            }

            if ($locked->body === $body) {
                return $locked;
            }

            $locked->body = $body;
            $locked->edited_at = now();
            $locked->save();

            $added = CommentMentions::sync($locked, CommentMentions::eligible($project, CommentMentions::candidates($body)));

            Activity::task($lockedTask, $actor, 'comment.edited', ['comment_id' => $locked->id, 'mentions' => count($added)]);

            if ($added !== []) {
                UsersMentioned::dispatch($locked->id, $lockedTask->id, $project->id, $project->team_id, $actor->id, $added);
            }

            return $locked;
        });
    }
}

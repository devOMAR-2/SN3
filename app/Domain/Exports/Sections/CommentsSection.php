<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * `data/comments.json`: the live comments of the exported, live tasks (CORE-008, ADR-027 §7; API_SPEC.md
 * §13 "Include metadata, tasks, comments…"). Deleted comments are left out, as trashed tasks are:
 * no read path shows them. `body` is the Markdown source; `mention_user_ids` are the stored mentions.
 */
final class CommentsSection implements TeamExportSection
{
    public function name(): string
    {
        return 'comments';
    }

    public function records(TeamExportContext $context): iterable
    {
        $comments = TaskComment::query()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->whereHas('task', function (Builder $tasks): void {
                /** @var Builder<Task> $tasks */
                $tasks->whereNull('tasks.deleted_at');
            })
            ->with('mentions:id')
            ->lazyById(500);

        foreach ($comments as $comment) {
            $mentions = [];

            foreach ($comment->mentions as $user) {
                if ($user instanceof User) {
                    $mentions[] = $user->id;
                }
            }

            sort($mentions);

            yield [
                'id' => $comment->id,
                'task_id' => $comment->task_id,
                'author_user_id' => $comment->author_user_id,
                'body' => $comment->body,
                'mention_user_ids' => $mentions,
                'edited_at' => TeamExportContext::timestamp($comment->edited_at),
                'created_at' => TeamExportContext::timestamp($comment->created_at),
                'updated_at' => TeamExportContext::timestamp($comment->updated_at),
            ];
        }
    }
}

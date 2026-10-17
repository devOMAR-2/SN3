<?php

namespace App\Http\Resources;

use App\Models\TaskComment;

/**
 * A task comment (API_SPEC.md §6.2; CORE-008, ADR-027 §2). `body` is the Markdown source as written
 * (clients sanitise it when rendering); `mention_user_ids` are the users the server resolved from its
 * `@<user-id>` tokens, sorted. `edited_at` is set once the author changed the body.
 */
final class CommentResource
{
    /**
     * @return array{id: string, task_id: string, author_user_id: string, body: string, mention_user_ids: list<string>, edited_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function make(TaskComment $comment): array
    {
        $mentions = [];

        foreach ($comment->mentions as $user) {
            $mentions[] = $user->id;
        }

        sort($mentions);

        return [
            'id' => $comment->id,
            'task_id' => $comment->task_id,
            'author_user_id' => $comment->author_user_id,
            'body' => $comment->body,
            'mention_user_ids' => $mentions,
            'edited_at' => $comment->edited_at?->utc()->toIso8601ZuluString(),
            'created_at' => $comment->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $comment->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}

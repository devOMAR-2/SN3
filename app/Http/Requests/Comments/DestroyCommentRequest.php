<?php

namespace App\Http\Requests\Comments;

use App\Domain\Collaboration\AuthoredContent;
use App\Models\User;

/**
 * DELETE /api/v1/tasks/{task}/comments/{comment} (API_SPEC.md §6.2 "Delete own or moderate — Author
 * or Owner/Project Manager"). No body.
 */
class DestroyCommentRequest extends CommentRequest
{
    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && AuthoredContent::mayDelete($user, $this->task()->project, $this->comment()->author_user_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests\Comments;

use App\Domain\Collaboration\AuthoredContent;
use App\Models\User;

/**
 * PATCH /api/v1/tasks/{task}/comments/{comment} (API_SPEC.md §6.2 "Edit own comment — Author; project
 * write permission"): `{ "body" }`. Anyone else who can see the comment gets 403.
 */
class UpdateCommentRequest extends CommentRequest
{
    /** @var list<string> */
    public const FIELDS = ['body'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && AuthoredContent::mayEdit($user, $this->task()->project, $this->comment()->author_user_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->bodyRules();
    }
}

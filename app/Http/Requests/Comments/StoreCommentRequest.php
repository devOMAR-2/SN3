<?php

namespace App\Http\Requests\Comments;

/**
 * POST /api/v1/tasks/{task}/comments (API_SPEC.md §6.2 "Project writer"): `{ "body" }`, Markdown,
 * at most 10,000 characters, not blank.
 */
class StoreCommentRequest extends CommentRequest
{
    /** @var list<string> */
    public const FIELDS = ['body'];

    public function authorize(): bool
    {
        return $this->user()?->can('contribute', $this->task()->project) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->bodyRules();
    }
}

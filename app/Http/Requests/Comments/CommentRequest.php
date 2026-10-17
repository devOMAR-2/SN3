<?php

namespace App\Http\Requests\Comments;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Comment writes (API_SPEC.md §6.2; CORE-008, ADR-027 §2). The right is checked before validation,
 * so a caller without it gets 403 whatever the body (AUTH-04). A `{comment}` that is not a live
 * comment of the route's task is a 404, checked before the right.
 */
abstract class CommentRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** At most this many characters of Markdown (API_SPEC.md §6.2). */
    public const BODY_MAX = 10_000;

    /** @var list<string> */
    public const FIELDS = [];

    private ?TaskComment $resolved = null;

    public function task(): Task
    {
        $task = $this->route('task');

        if (! $task instanceof Task) {
            throw (new ModelNotFoundException)->setModel(Task::class);
        }

        return $task;
    }

    /**
     * The live comment named by `{comment}`, of the route's task.
     *
     * @throws ModelNotFoundException<TaskComment>
     */
    public function comment(): TaskComment
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $id = $this->route('comment');

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(TaskComment::class);
        }

        return $this->resolved = $this->task()->comments()->whereKey(strtolower($id))->firstOrFail();
    }

    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyRules(): array
    {
        return ['body' => ['required', 'string', 'max:'.self::BODY_MAX]];
    }
}

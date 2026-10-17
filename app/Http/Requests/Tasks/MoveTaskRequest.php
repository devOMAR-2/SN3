<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/tasks/{task}/move (API_SPEC.md §6.1): `{ "status_id", "before_task_id", "after_task_id" }`.
 *
 * - The right to write tasks in the project (`contribute`) is checked before validation, so a Viewer
 *   gets 403 whatever the body (AUTH-04).
 * - `status_id` is required; `before_task_id` and `after_task_id` are optional, nullable UUIDs, and at
 *   most one of them may be non-null (`prohibits`). Whether they exist in that column is the action's
 *   check (422 `exists`), the same answer for another project's task and a random ID.
 */
class MoveTaskRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['status_id', 'before_task_id', 'after_task_id'];

    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && ($this->user()?->can('contribute', $task->project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'string', 'uuid'],
            'before_task_id' => ['nullable', 'string', 'uuid'],
            'after_task_id' => ['nullable', 'string', 'uuid', 'prohibits:before_task_id'],
        ];
    }

    public function statusId(): string
    {
        return strtolower($this->string('status_id')->toString());
    }

    public function beforeTaskId(): ?string
    {
        return self::id($this->input('before_task_id'));
    }

    public function afterTaskId(): ?string
    {
        return self::id($this->input('after_task_id'));
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }
}

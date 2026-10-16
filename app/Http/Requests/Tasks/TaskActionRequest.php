<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body-less task requests: `DELETE /tasks/{task}` (`contribute`) and `POST /tasks/{trashedTask}/restore`
 * (`restoreTasks`). Any body field is `unknown_field` (API_SPEC.md §1.2), and the right is checked
 * before validation, so a caller without it gets 403 whatever the body (AUTH-04).
 */
abstract class TaskActionRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    /** The ProjectPolicy ability the request needs on the task's project. */
    abstract protected function ability(): string;

    /** The route parameter holding the task. */
    protected function parameter(): string
    {
        return 'task';
    }

    public function authorize(): bool
    {
        $task = $this->route($this->parameter());

        return $task instanceof Task && ($this->user()?->can($this->ability(), $task->project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

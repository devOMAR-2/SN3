<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/tasks/{task}/watchers and DELETE /api/v1/tasks/{task}/watchers/me (API_SPEC.md §6.1;
 * CORE-009, ADR-029 §1): no body. `{task}` resolves through AccessibleTasks (a hidden task is a 404);
 * anyone who can see the project may watch, so the right is `view`.
 */
class WatchTaskRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function task(): Task
    {
        $task = $this->route('task');

        if (! $task instanceof Task) {
            throw (new ModelNotFoundException)->setModel(Task::class);
        }

        return $task;
    }

    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->task()->project) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

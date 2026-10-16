<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/tasks/{task} (API_SPEC.md §6.1, §1.2): partial update with the create rules. Absent
 * fields stay unchanged; `null` clears `description`, `assignee_user_id`, `due_date` and
 * `parent_task_id`; `label_ids` replaces the whole label set (`[]` removes every label). Requires
 * `If-Match` (read by the controller).
 */
class UpdateTaskRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = StoreTaskRequest::FIELDS;

    /** Project writers only (ProjectPolicy::contribute): 403 before validation (AUTH-04). */
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
        return StoreTaskRequest::fieldRules(creating: false);
    }

    protected function prepareForValidation(): void
    {
        StoreTaskRequest::normalize($this);
    }

    /**
     * @return array{title?: string, description?: string|null, status_id?: string, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null}
     */
    public function changes(): array
    {
        /** @var array{title?: string, description?: string|null, status_id?: string, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null} $changes */
        $changes = StoreTaskRequest::canonical($this->safe()->except(['sprint_id', 'story_points']));

        return $changes;
    }
}

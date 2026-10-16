<?php

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskPriority;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /api/v1/projects/{project}/tasks (API_SPEC.md §6.1, §1.4 filter syntax): explicit keys
 * only, repeated keys for several values (`status_id[]=…&status_id[]=…`).
 *
 * | Key | Meaning |
 * |---|---|
 * | `q` | title contains the text (case-insensitive), or the task number / key is exactly it (`104`, `WEB-104`) |
 * | `status_id[]` | in any of these statuses |
 * | `assignee_user_id` | assigned to this user |
 * | `priority[]` | any of these priorities |
 * | `label_id[]` | carries at least one of these labels |
 * | `due_after`, `due_before` | due on or after / on or before this date (inclusive; tasks without a due date are left out) |
 * | `sort` | `created_at` (default, newest first), `updated_at` (newest first), `due_date` (soonest first, none last), `priority` (urgent first), `position` (board order) |
 *
 * `sprint_id` is `prohibited` until sprints exist (FLEX-005), so a client is never told a filter
 * applied when it did not. `limit` and `cursor` are checked by CursorPagination.
 */
class ListTasksRequest extends FormRequest
{
    /** @var list<string> */
    public const SORTS = ['created_at', 'updated_at', 'due_date', 'priority', 'position'];

    public const Q_MAX = 200;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('view', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = ['sometimes', 'string', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'];

        return [
            'q' => ['sometimes', 'string', 'max:'.self::Q_MAX],
            'status_id' => ['sometimes', 'array', 'list', 'max:50'],
            'status_id.*' => ['string', 'uuid'],
            'assignee_user_id' => ['sometimes', 'string', 'uuid'],
            'priority' => ['sometimes', 'array', 'list', 'max:5'],
            'priority.*' => ['string', Rule::in(TaskPriority::values())],
            'label_id' => ['sometimes', 'array', 'list', 'max:20'],
            'label_id.*' => ['string', 'uuid'],
            'sprint_id' => ['prohibited'],
            'due_after' => $date,
            'due_before' => $this->has('due_after') ? [...$date, 'after_or_equal:due_after'] : $date,
            'sort' => ['sometimes', 'string', Rule::in(static::SORTS)],
        ];
    }

    /**
     * Only the query string counts; there is no body on a GET.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * @return array{q?: string, status_id?: list<string>, assignee_user_id?: string, priority?: list<string>, label_id?: list<string>, due_after?: string, due_before?: string, sort?: string}
     */
    public function filters(): array
    {
        $filters = $this->validated();

        foreach (['status_id', 'label_id'] as $key) {
            if (isset($filters[$key]) && is_array($filters[$key])) {
                $filters[$key] = array_values(array_map(fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), $filters[$key]));
            }
        }

        if (isset($filters['assignee_user_id']) && is_string($filters['assignee_user_id'])) {
            $filters['assignee_user_id'] = strtolower($filters['assignee_user_id']);
        }

        /** @var array{q?: string, status_id?: list<string>, assignee_user_id?: string, priority?: list<string>, label_id?: list<string>, due_after?: string, due_before?: string, sort?: string} $typed */
        $typed = $filters;

        return $typed;
    }
}

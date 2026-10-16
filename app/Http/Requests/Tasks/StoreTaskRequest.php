<?php

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskLabels;
use App\Domain\Tasks\TaskPriority;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/projects/{project}/tasks (API_SPEC.md §6.1, §14.1). Requires `Idempotency-Key`
 * (the `idempotent` route middleware).
 *
 * - `title` 1–200 characters after trimming; `description` Markdown source up to 50,000 characters
 *   (`null` or empty: none).
 * - `status_id` a status of the project (default: the project's default status); `priority`
 *   `none|low|medium|high|urgent` (default `none`); `assignee_user_id` an eligible user or `null`;
 *   `due_date` `YYYY-MM-DD` or `null`; `label_ids` up to 20 distinct labels of the project;
 *   `parent_task_id` a task of the project or `null`.
 * - `sprint_id` and `story_points` may only be `null` until sprints exist (FLEX-005).
 * - The task key is never accepted (`key` is `unknown_field`, like any other field).
 *
 * Whether the referenced status, assignee, labels and parent are usable is decided by CreateTask
 * under the project lock (422 `exists`).
 */
class StoreTaskRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['title', 'description', 'status_id', 'priority', 'assignee_user_id', 'due_date', 'label_ids', 'parent_task_id', 'sprint_id', 'story_points'];

    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 50_000;

    /** Project writers only (ProjectPolicy::contribute): a Team Viewer gets 403 whatever the body (AUTH-04). */
    public function authorize(): bool
    {
        return $this->user()?->can('contribute', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::fieldRules(creating: true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'min:1', 'max:'.self::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            // On create `null` means "the default status"; a task always has a status, so PATCH needs one.
            'status_id' => $creating ? ['sometimes', 'nullable', 'string', 'uuid'] : ['sometimes', 'required', 'string', 'uuid'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(TaskPriority::values())],
            'assignee_user_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'due_date' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'label_ids' => ['sometimes', 'array', 'list', 'max:'.TaskLabels::MAX_PER_TASK],
            'label_ids.*' => ['string', 'uuid', 'distinct:ignore_case'],
            'parent_task_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            // Sprints and story points arrive with FLEX-005; until then only `null` is accepted.
            'sprint_id' => ['prohibited'],
            'story_points' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        self::normalize($this);
    }

    /**
     * Trims the title; an empty or blank description becomes `null`. Shared with UpdateTaskRequest.
     */
    public static function normalize(FormRequest $request): void
    {
        $title = $request->input('title');

        if (is_string($title)) {
            $request->merge(['title' => trim($title)]);
        }

        $description = $request->input('description');

        if (is_string($description) && trim($description) === '') {
            $request->merge(['description' => null]);
        }
    }

    /**
     * The validated fields, with UUIDs lower-cased and `label_ids` deduplicated.
     *
     * @return array{title: string, description?: string|null, status_id?: string|null, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null}
     */
    public function taskInput(): array
    {
        /** @var array{title: string, description?: string|null, status_id?: string|null, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null} $input */
        $input = self::canonical($this->safe()->except(['sprint_id', 'story_points']));

        return $input;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function canonical(array $fields): array
    {
        foreach (['status_id', 'assignee_user_id', 'parent_task_id'] as $key) {
            if (isset($fields[$key]) && is_string($fields[$key])) {
                $fields[$key] = strtolower($fields[$key]);
            }
        }

        if (isset($fields['label_ids']) && is_array($fields['label_ids'])) {
            $fields['label_ids'] = array_values(array_unique(array_map(
                fn (mixed $id): string => strtolower(is_string($id) ? $id : ''),
                $fields['label_ids'],
            )));
        }

        return $fields;
    }
}

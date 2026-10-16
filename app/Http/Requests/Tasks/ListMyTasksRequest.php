<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /api/v1/me/tasks (API_SPEC.md §2): the caller's assigned tasks across every project
 * they can see. `sort` as for the project task list, without `position` (board order is per project).
 */
class ListMyTasksRequest extends FormRequest
{
    /** @var list<string> */
    public const SORTS = ['created_at', 'updated_at', 'due_date', 'priority'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function sort(): string
    {
        $sort = $this->validated('sort');

        return is_string($sort) ? $sort : 'created_at';
    }
}

<?php

namespace App\Http\Requests\Workflows;

use App\Domain\Workflows\StatusRules;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/projects/{project}/statuses/order (API_SPEC.md §5): `{ "status_ids": [ … ] }`, every
 * status ID of the project exactly once, in the new board order, with `If-Match` carrying the
 * workflow version from `GET /statuses` (412 when stale). A list that no longer matches the
 * project's statuses is a 409 (ReorderStatuses).
 */
class ReorderStatusesRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['status_ids'];

    /** Owner or project manager (ProjectPolicy::manageWorkflow): 403 before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageWorkflow', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_ids' => ['required', 'list', 'min:1', 'max:'.StatusRules::MAX_STATUSES],
            'status_ids.*' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /**
     * @return list<string>
     */
    public function statusIds(): array
    {
        $ids = $this->validated('status_ids');

        return is_array($ids)
            ? array_values(array_map(fn (mixed $id): string => is_string($id) ? strtolower($id) : '', $ids))
            : [];
    }
}

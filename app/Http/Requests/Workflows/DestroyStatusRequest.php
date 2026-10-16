<?php

namespace App\Http\Requests\Workflows;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/projects/{project}/statuses/{status} (API_SPEC.md §5): optional
 * `{ "replacement_status_id": "uuid" }`, another status of the same project that receives the
 * deleted status's tasks (and its default flag). Requires `If-Match`.
 */
class DestroyStatusRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['replacement_status_id'];

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
            'replacement_status_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    public function replacementId(): ?string
    {
        $id = $this->validated('replacement_status_id');

        return is_string($id) ? strtolower($id) : null;
    }
}

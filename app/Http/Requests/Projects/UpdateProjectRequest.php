<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectVisibility;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/projects/{project} (API_SPEC.md §4): partial update of `name`, `key`,
 * `description` and `visibility`, with the same rules as creation. Absent fields stay unchanged;
 * `description: null` clears it. Requires `If-Match` (read by the controller).
 */
class UpdateProjectRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'key', 'description', 'visibility'];

    /** Owner or project manager (Admin too on team-visible projects): 403 before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:'.StoreProjectRequest::NAME_MAX],
            'key' => ['sometimes', 'required', 'string', 'regex:'.StoreProjectRequest::KEY_PATTERN],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.StoreProjectRequest::DESCRIPTION_MAX],
            'visibility' => ['sometimes', 'required', 'string', Rule::in(array_column(ProjectVisibility::cases(), 'value'))],
        ];
    }

    protected function prepareForValidation(): void
    {
        StoreProjectRequest::normalize($this);
    }
}

<?php

namespace App\Http\Requests\Labels;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/projects/{project}/labels/{label} (API_SPEC.md §5): `name` and/or `color` (`null`
 * clears it), with the create rules. Labels are not versioned (§1.4), so there is no `If-Match`.
 */
class UpdateLabelRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = StoreLabelRequest::FIELDS;

    /** Owner or project manager (ProjectPolicy::manageLabels): 403 before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageLabels', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoreLabelRequest::fieldRules(required: false);
    }

    protected function prepareForValidation(): void
    {
        StoreLabelRequest::normalize($this);
    }
}

<?php

namespace App\Http\Requests\Labels;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/projects/{project}/labels/{label} (API_SPEC.md §5): no body.
 */
class DestroyLabelRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

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
        return [];
    }
}

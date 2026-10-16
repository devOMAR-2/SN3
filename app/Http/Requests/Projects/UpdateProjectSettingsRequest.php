<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/projects/{project}/settings (API_SPEC.md §5): `{ "sprints_enabled": bool }`.
 * Requires `If-Match` with the project's version. Settings without a column yet (story points,
 * enabled views) are `unknown_field` until FLEX-002/FLEX-005 add them (ADR-021 §6).
 */
class UpdateProjectSettingsRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['sprints_enabled'];

    /** Same right as PATCH /projects/{project}: 403 before validation. */
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
            'sprints_enabled' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }
}

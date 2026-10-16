<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectRole;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/projects/{project}/members/{user} (API_SPEC.md §4): `{ "role": "manager" | "contributor" }`.
 */
class PutProjectMemberRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['role'];

    /** Owner or project manager only: 403 whatever the body. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_column(ProjectRole::cases(), 'value'))],
        ];
    }
}

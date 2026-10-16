<?php

namespace App\Http\Requests\Teams;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/teams/{team} (API_SPEC.md §3): partial update of `name`, `slug` and
 * `description`. Absent fields stay unchanged; `description: null` clears it.
 */
class UpdateTeamRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'slug', 'description'];

    /** Authorise before validating, so a Viewer gets 403 whatever the body (AUTH-04). */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'slug' => ['sometimes', 'required', 'string', 'regex:'.StoreTeamRequest::SLUG_PATTERN],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}

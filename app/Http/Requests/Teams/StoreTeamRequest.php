<?php

namespace App\Http\Requests\Teams;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/teams (API_SPEC.md §3): `name` 1–100, optional `slug` ([a-z0-9-]{3,40}),
 * optional `description` (max 1000).
 */
class StoreTeamRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'slug', 'description'];

    public const SLUG_PATTERN = '/^[a-z0-9-]{3,40}$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'regex:'.self::SLUG_PATTERN],
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

<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectVisibility;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/teams/{team}/projects (API_SPEC.md §4):
 * `{ "name", "key", "visibility", "template_id", "description" }`.
 *
 * - `name` 1–180 (the `projects.name` column), `description` up to 5000 characters (ADR-020 §3).
 * - `key` is upper-cased, then must match `[A-Z][A-Z0-9]{1,9}`.
 * - `visibility` is `team` (default) or `private`.
 * - `template_id` may only be null until team templates exist (FLEX-004).
 */
class StoreProjectRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'key', 'description', 'visibility', 'template_id'];

    public const KEY_PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    public const NAME_MAX = 180;

    public const DESCRIPTION_MAX = 5000;

    /** Owner/Admin only: everyone else gets 403 whatever the body (AUTH-04). */
    public function authorize(): bool
    {
        return $this->user()?->can('createProject', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:'.self::NAME_MAX],
            'key' => ['required', 'string', 'regex:'.self::KEY_PATTERN],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'visibility' => ['sometimes', 'required', 'string', Rule::in(array_column(ProjectVisibility::cases(), 'value'))],
            'template_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        self::normalize($this);
    }

    /**
     * Trims `name` and `description` and upper-cases `key` (DATABASE.md §4: normalise at the
     * application boundary). Shared with UpdateProjectRequest.
     */
    public static function normalize(FormRequest $request): void
    {
        foreach (['name', 'description'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([$field => trim($value)]);
            }
        }

        $key = $request->input('key');

        if (is_string($key)) {
            $request->merge(['key' => Str::upper(trim($key))]);
        }
    }
}

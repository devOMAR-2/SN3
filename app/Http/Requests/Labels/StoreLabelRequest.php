<?php

namespace App\Http\Requests\Labels;

use App\Domain\Workflows\StatusRules;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * POST /api/v1/projects/{project}/labels (API_SPEC.md §5; ADR-023 §6): `{ "name", "color" }`.
 * `name` is trimmed, 1–50 characters and unique in the project ignoring case (409 otherwise);
 * `color` is optional `#RRGGBB`, trimmed and upper-cased, like a status colour.
 */
class StoreLabelRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'color'];

    public const NAME_MAX = 50;

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
        return self::fieldRules(required: true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(bool $required): array
    {
        return [
            'name' => [$required ? 'required' : 'sometimes', 'required', 'string', 'min:1', 'max:'.self::NAME_MAX],
            'color' => ['sometimes', 'nullable', 'string', 'regex:'.StatusRules::COLOR_PATTERN],
        ];
    }

    protected function prepareForValidation(): void
    {
        self::normalize($this);
    }

    public static function normalize(FormRequest $request): void
    {
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $color = $request->input('color');

        if (is_string($color)) {
            $request->merge(['color' => Str::upper(trim($color))]);
        }
    }
}

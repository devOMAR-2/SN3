<?php

namespace App\Http\Requests\Workflows;

use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusRules;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/projects/{project}/statuses (API_SPEC.md §5): `{ "name", "category", "color" }`.
 *
 * - `name` is trimmed, 1–50 characters, unique in the project ignoring case (409).
 * - `category` is `todo`, `in_progress` or `done`.
 * - `color` is optional hex RGB `#RRGGBB`, upper-cased; `null` means none.
 * - `position` is read-only: new statuses go last, reordering uses `PUT /statuses/order`.
 */
class StoreStatusRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'category', 'color'];

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
        return self::fieldRules(required: true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'min:1', 'max:'.StatusRules::NAME_MAX],
            'category' => [$presence, 'required', 'string', Rule::in(array_column(StatusCategory::cases(), 'value'))],
            'color' => ['sometimes', 'nullable', 'string', 'regex:'.StatusRules::COLOR_PATTERN],
        ];
    }

    protected function prepareForValidation(): void
    {
        self::normalize($this);
    }

    /**
     * Trims `name`; trims and upper-cases `color`. Shared with UpdateStatusRequest.
     */
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

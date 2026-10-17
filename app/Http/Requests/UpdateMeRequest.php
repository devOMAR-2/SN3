<?php

namespace App\Http\Requests;

use App\Models\UserPreference;
use App\Support\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /me: partial update of display name, language, timezone, theme (API_SPEC.md §2) and the
 * notification preferences `email_notifications` and `in_app_notifications` (DATABASE.md §8,
 * FR-COL-004; CORE-009, ADR-029 §5), strict booleans. Absent fields stay unchanged; unknown fields are
 * rejected (API_SPEC.md §1.2).
 */
class UpdateMeRequest extends FormRequest
{
    /** @var list<string> */
    public const FIELDS = ['name', 'locale', 'timezone', 'theme', 'email_notifications', 'in_app_notifications'];

    /** The fields stored in `user_preferences`. */
    public const PREFERENCE_FIELDS = ['theme', 'email_notifications', 'in_app_notifications'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'locale' => ['sometimes', 'required', 'string', Rule::in(Locale::values())],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone:all'],
            'theme' => ['sometimes', 'required', 'string', Rule::in(UserPreference::THEMES)],
            'email_notifications' => ['sometimes', 'required', 'boolean:strict'],
            'in_app_notifications' => ['sometimes', 'required', 'boolean:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), self::FIELDS) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'unknown_field');
                }
            },
        ];
    }
}

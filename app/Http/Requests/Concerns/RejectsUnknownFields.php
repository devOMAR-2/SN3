<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Unknown body fields are rejected with the `unknown_field` code (API_SPEC.md §1.2).
 * The using request lists its accepted fields in `FIELDS`.
 */
trait RejectsUnknownFields
{
    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), static::FIELDS) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'unknown_field');
                }
            },
        ];
    }
}

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
                foreach (array_diff($this->bodyKeys(), static::FIELDS) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'unknown_field');
                }
            },
        ];
    }

    /**
     * Only body fields count; query-string parameters (for example cache busters) are not part of
     * the resource representation.
     *
     * @return array<int, int|string>
     */
    private function bodyKeys(): array
    {
        return array_keys($this->isJson() ? $this->json()->all() : $this->request->all());
    }
}

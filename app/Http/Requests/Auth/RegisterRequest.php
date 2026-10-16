<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/register (API_SPEC.md §2). Uniqueness is deliberately not validated here:
 * a duplicate email gets the same response as a new one (see RegisterUser).
 */
class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
            'password' => ['required', ...PasswordRules::rules()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? trim($this->input('email')) : $this->input('email'),
        ]);
    }
}

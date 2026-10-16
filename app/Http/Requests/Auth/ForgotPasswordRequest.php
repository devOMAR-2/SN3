<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
        ];
    }
}

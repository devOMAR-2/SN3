<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Password policy (API_SPEC.md §2): 12–128 characters, plus an optional breached-password
 * check. Plain `min`/`max` rules keep the API's validation codes specific (`min`, `max`,
 * and `password` for a breached password).
 */
final class PasswordRules
{
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        $rules = ['string', 'min:'.config()->integer('sn3.passwords.min'), 'max:'.config()->integer('sn3.passwords.max')];

        if (config('sn3.passwords.check_breached')) {
            $rules[] = Password::min(1)->uncompromised();
        }

        return $rules;
    }
}

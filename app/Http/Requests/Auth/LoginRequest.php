<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/auth/login. Failures are generic (unknown email, wrong password and disabled
 * account all return the same `credentials` error); repeated failures for one email+IP lock out
 * with 429 rate_limited and Retry-After.
 */
class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => User::normalizeEmail($this->string('email')->toString()),
            'password' => $this->string('password')->toString(),
            fn (Builder $query) => $query->whereNull('disabled_at'),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages(['email' => 'credentials']);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        $max = config()->integer('sn3.rate_limits.login_per_minute');

        if ($max <= 0 || ! RateLimiter::tooManyAttempts($this->throttleKey(), $max)) {
            return;
        }

        event(new Lockout($this));

        throw new ThrottleRequestsException(
            headers: ['Retry-After' => RateLimiter::availableIn($this->throttleKey())],
        );
    }

    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate(User::normalizeEmail($this->string('email')->toString()).'|'.$this->ip());
    }
}

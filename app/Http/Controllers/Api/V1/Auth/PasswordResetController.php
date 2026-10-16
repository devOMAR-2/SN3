<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/auth/password/forgot and POST /api/v1/auth/password/reset.
 */
class PasswordResetController extends Controller
{
    /**
     * Always 202, whether or not the email exists, is disabled or was throttled by the broker.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(
            ['email' => User::normalizeEmail($request->string('email')->toString())],
            function (CanResetPassword $user, string $token): void {
                if ($user instanceof User && ! $user->isDisabled()) {
                    $user->sendPasswordResetNotification($token);
                }
            },
        );

        return ApiResponse::data(['status' => 'reset_link_sent'], 202);
    }

    /**
     * On success the password and remember token are replaced and every stored session of the
     * user is revoked. An unknown email and a bad token return the same `invalid_token` error.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => User::normalizeEmail($request->string('email')->toString()),
                'password' => $request->string('password')->toString(),
                'token' => $request->string('token')->toString(),
            ],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    DB::table('sessions')->where('user_id', $user->getKey())->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'invalid_token']);
        }

        return ApiResponse::data(['status' => 'password_reset']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET  /api/v1/auth/email/verify/{id}/{hash}: temporary signed link from the verification email.
 * POST /api/v1/auth/email/resend: queue a new verification email for the signed-in user.
 *
 * The link works without an active session (it is opened from a mail client). Browsers are
 * redirected to an Inertia page with a status message; JSON clients get the API envelope.
 * Invalid, expired and mismatched links are indistinguishable.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = Str::isUuid($id) ? User::query()->find($id) : null;

        if (! $request->hasValidSignature()
            || $user === null
            || ! hash_equals(hash('sha256', $user->getEmailForVerification()), $hash)) {
            return $request->expectsJson()
                ? ApiResponse::error('forbidden', 403)
                : redirect()->route($request->user() !== null ? 'home' : 'login')->with('status', 'verification_link_invalid');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $request->expectsJson()
            ? ApiResponse::data(['status' => 'verified'])
            : redirect()->route($request->user() !== null ? 'home' : 'login')->with('status', 'email_verified');
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::data(['status' => 'already_verified']);
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::data(['status' => 'verification_sent'], 202);
    }
}

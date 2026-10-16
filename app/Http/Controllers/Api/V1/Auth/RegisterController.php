<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

/**
 * POST /api/v1/auth/register.
 *
 * Always 202 with the same body for new and existing emails, and no automatic sign-in,
 * so the response never reveals whether an account exists. See ADR-016.
 */
class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        if (! config('sn3.registration.enabled')) {
            return ApiResponse::error('forbidden', 403);
        }

        /** @var array{name: string, email: string, password: string} $input */
        $input = $request->validated();

        $register->handle($input, Locale::fromValue(App::getLocale()) ?? Locale::DEFAULT);

        return ApiResponse::data(['status' => 'verification_sent'], 202);
    }
}

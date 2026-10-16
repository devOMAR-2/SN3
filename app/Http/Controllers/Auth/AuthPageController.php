<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia pages for the public auth shell (API_SPEC.md §1.1 page routes; DESIGN_SYSTEM.md §3.4).
 * Every form submits to the /api/v1/auth JSON endpoints.
 */
class AuthPageController extends Controller
{
    public function login(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function register(): Response
    {
        abort_unless((bool) config('sn3.registration.enabled'), 404);

        return Inertia::render('Auth/Register');
    }

    public function forgotPassword(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function resetPassword(Request $request, string $token): Response
    {
        $email = $request->query('email');

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => is_string($email) ? $email : '',
        ]);
    }

    public function verifyEmail(): Response
    {
        return Inertia::render('Auth/VerifyEmail');
    }
}

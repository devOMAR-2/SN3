<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

    /**
     * The token and email stay in the URL fragment (ResetPasswordNotification), so the server never
     * receives them and they cannot reach access or proxy logs. The page reads them client-side.
     */
    public function resetPassword(): Response
    {
        return Inertia::render('Auth/ResetPassword');
    }

    public function verifyEmail(): Response
    {
        return Inertia::render('Auth/VerifyEmail');
    }
}

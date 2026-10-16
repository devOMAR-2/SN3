<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 JSON interface (API_SPEC.md §1). Registered in bootstrap/app.php with the `web`
| middleware group: same-origin session authentication and CSRF, no bearer tokens.
| Route names are prefixed with `api.`.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:auth-register')
        ->name('register');

    Route::post('login', [SessionController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('login');

    Route::post('logout', [SessionController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:auth-password')
        ->name('password.forgot');

    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:auth-password')
        ->name('password.reset');

    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth', 'throttle:auth-verification'])
        ->name('verification.resend');

    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:auth-verification')
        ->name('verification.verify');
});

Route::middleware('auth')->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');
});

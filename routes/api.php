<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\TeamOwnershipController;
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
    Route::get('me/teams', [TeamController::class, 'index'])->name('me.teams');

    // `{team}` resolves only to the caller's own teams (TenancyServiceProvider); anything else is 404.
    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    // Creating or joining a team needs a verified email (API_SPEC.md §2).
    Route::post('teams', [TeamController::class, 'store'])->middleware('verified')->name('teams.store');
    Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::patch('teams/{team}', [TeamController::class, 'update'])->name('teams.update');

    Route::get('teams/{team}/members', [TeamMemberController::class, 'index'])->name('teams.members.index');
    Route::patch('teams/{team}/members/{member}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
    Route::post('teams/{team}/ownership-transfer', TeamOwnershipController::class)->name('teams.ownership.transfer');

    // Projects (API_SPEC.md §4, ADR-020). `{project}` resolves only to projects the caller can see
    // (AccessibleProjects): a private project is a 404 for everyone but its members and the Owner.
    Route::get('teams/{team}/projects', [ProjectController::class, 'index'])->name('teams.projects.index');
    Route::post('teams/{team}/projects', [ProjectController::class, 'store'])->middleware('throttle:project-writes')->name('teams.projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->middleware('throttle:project-writes')->name('projects.update');

    Route::get('projects/{project}/members', [ProjectMemberController::class, 'index'])->name('projects.members.index');
    Route::put('projects/{project}/members/{member}', [ProjectMemberController::class, 'update'])->name('projects.members.update');
    Route::delete('projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');
});

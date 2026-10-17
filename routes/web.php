<?php

use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationPageController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Teams\TeamInvitationsPageController;
use App\Http\Controllers\Web\InboxPageController;
use App\Http\Controllers\Web\ProjectPageController;
use App\Http\Controllers\Web\TaskPageController;
use App\Http\Controllers\Web\TeamPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Interface-language switch (FND-006). Signed-in users' choice is also saved to their profile.
Route::put('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

// Public auth shell (FND-004). Forms submit to /api/v1/auth/*.
// The reset page is not guest-only: a redirect would carry the URL fragment (the reset token)
// along to the next page.
Route::get('/reset-password', [AuthPageController::class, 'resetPassword'])->name('password.reset');

// Invitation links (CORE-002): `/invite#<token>`, for guests and signed-in users alike. The token is in
// the fragment, which never reaches the server.
Route::get('/invite', InvitationPageController::class)->name('invitations.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthPageController::class, 'login'])->name('login');
    Route::get('/register', [AuthPageController::class, 'register'])->name('register');
    Route::get('/forgot-password', [AuthPageController::class, 'forgotPassword'])->name('password.request');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email', [AuthPageController::class, 'verifyEmail'])->name('verification.notice');
    Route::inertia('/settings', 'Settings/Profile')->name('settings');

    // Team, project and task pages (API_SPEC.md §1.1; CORE-006, ADR-026). Read-only Inertia pages:
    // `{teamSlug}`, `{projectKey}` and `{task}` resolve through AccessibleTeams, AccessibleProjects and
    // AccessibleTasks (hidden = 404), and every change is a request to /api/v1.
    Route::get('/teams', [TeamPageController::class, 'index'])->name('teams.index');
    Route::get('/teams/new', [TeamPageController::class, 'create'])->name('teams.create');
    Route::get('/t/{teamSlug}', [TeamPageController::class, 'show'])->name('teams.show');
    Route::get('/t/{teamSlug}/members', [TeamPageController::class, 'members'])->name('teams.members');
    Route::get('/t/{teamSlug}/settings', [TeamPageController::class, 'settings'])->name('teams.settings');
    Route::get('/t/{teamSlug}/projects', [ProjectPageController::class, 'index'])->name('projects.index');
    Route::get('/t/{teamSlug}/projects/new', [ProjectPageController::class, 'create'])->name('projects.create');
    Route::get('/t/{teamSlug}/p/{projectKey}', [ProjectPageController::class, 'show'])->name('projects.show');
    Route::get('/t/{teamSlug}/p/{projectKey}/settings', [ProjectPageController::class, 'settings'])->name('projects.settings');
    Route::get('/tasks/{task}', [TaskPageController::class, 'show'])->name('tasks.show');

    // The inbox (CORE-009, ADR-029 §6); its content comes from /api/v1/notifications.
    Route::get('/inbox', InboxPageController::class)->name('inbox');

    Route::get('/teams/{team}/invitations', TeamInvitationsPageController::class)->name('teams.invitations');
});

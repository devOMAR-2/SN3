<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MyTasksController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectLifecycleController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\ProjectSettingsController;
use App\Http\Controllers\Api\V1\ProjectStatusController;
use App\Http\Controllers\Api\V1\TaskChecklistController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TeamExportController;
use App\Http\Controllers\Api\V1\TeamLifecycleController;
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

    // Workflow statuses and project settings (API_SPEC.md §5, ADR-021).
    Route::get('projects/{project}/statuses', [ProjectStatusController::class, 'index'])->name('projects.statuses.index');
    Route::post('projects/{project}/statuses', [ProjectStatusController::class, 'store'])->name('projects.statuses.store');
    Route::put('projects/{project}/statuses/order', [ProjectStatusController::class, 'reorder'])->name('projects.statuses.reorder');
    Route::patch('projects/{project}/statuses/{status}', [ProjectStatusController::class, 'update'])->name('projects.statuses.update');
    Route::delete('projects/{project}/statuses/{status}', [ProjectStatusController::class, 'destroy'])->name('projects.statuses.destroy');
    Route::get('projects/{project}/settings', [ProjectSettingsController::class, 'show'])->name('projects.settings.show');
    Route::patch('projects/{project}/settings', [ProjectSettingsController::class, 'update'])->name('projects.settings.update');

    // Archive and 30-day trash (API_SPEC.md §3, §4; ADR-022). `{trashedTeam}` and `{trashedProject}`
    // resolve only trashed rows the caller may recover (RecoverableResources); anything else is 404.
    Route::post('teams/{team}/archive', [TeamLifecycleController::class, 'archive'])->name('teams.archive');
    Route::post('teams/{team}/restore', [TeamLifecycleController::class, 'unarchive'])->name('teams.restore');
    Route::delete('teams/{team}', [TeamLifecycleController::class, 'destroy'])->name('teams.destroy');
    Route::post('teams/{trashedTeam}/restore-deleted', [TeamLifecycleController::class, 'restoreDeleted'])->name('teams.restore-deleted');

    Route::post('projects/{project}/archive', [ProjectLifecycleController::class, 'archive'])->name('projects.archive');
    Route::post('projects/{project}/restore', [ProjectLifecycleController::class, 'unarchive'])->name('projects.restore');
    Route::delete('projects/{project}', [ProjectLifecycleController::class, 'destroy'])->name('projects.destroy');
    Route::post('projects/{trashedProject}/restore-deleted', [ProjectLifecycleController::class, 'restoreDeleted'])->name('projects.restore-deleted');

    // Tasks, labels and checklists (API_SPEC.md §2, §5, §6.1, §6.2; CORE-005, ADR-023). `{task}` resolves
    // only to live tasks of projects the caller can see (AccessibleTasks); anything else is 404.
    Route::get('me/tasks', MyTasksController::class)->name('me.tasks');

    Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->name('projects.tasks.index');
    Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->middleware('idempotent')->name('projects.tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('tasks/{trashedTask}/restore', [TaskController::class, 'restore'])->name('tasks.restore');

    Route::get('projects/{project}/labels', [LabelController::class, 'index'])->name('projects.labels.index');
    Route::post('projects/{project}/labels', [LabelController::class, 'store'])->name('projects.labels.store');
    Route::patch('projects/{project}/labels/{label}', [LabelController::class, 'update'])->name('projects.labels.update');
    Route::delete('projects/{project}/labels/{label}', [LabelController::class, 'destroy'])->name('projects.labels.destroy');

    Route::get('tasks/{task}/checklist', [TaskChecklistController::class, 'index'])->name('tasks.checklist.index');
    Route::post('tasks/{task}/checklist', [TaskChecklistController::class, 'store'])->name('tasks.checklist.store');
    Route::patch('tasks/{task}/checklist/{item}', [TaskChecklistController::class, 'update'])->name('tasks.checklist.update');
    Route::delete('tasks/{task}/checklist/{item}', [TaskChecklistController::class, 'destroy'])->name('tasks.checklist.destroy');

    // Team exports (API_SPEC.md §3, §13, §18; ADR-024), Owner only. The download URL is signed and
    // expires with the export; it also needs the requesting Owner's session.
    Route::post('teams/{team}/exports', [TeamExportController::class, 'store'])->middleware(['throttle:team-exports', 'idempotent'])->name('teams.exports.store');
    Route::get('teams/{team}/exports/{export}', [TeamExportController::class, 'show'])->name('teams.exports.show');
    Route::get('teams/{team}/exports/{export}/download', [TeamExportController::class, 'download'])->middleware('signed')->name('teams.exports.download');
});

<?php

use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Interface-language switch (FND-006). Signed-in users' choice is also saved to their profile.
Route::put('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

// Public auth shell (FND-004). Forms submit to /api/v1/auth/*.
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthPageController::class, 'login'])->name('login');
    Route::get('/register', [AuthPageController::class, 'register'])->name('register');
    Route::get('/forgot-password', [AuthPageController::class, 'forgotPassword'])->name('password.request');
    Route::get('/reset-password/{token}', [AuthPageController::class, 'resetPassword'])->name('password.reset');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email', [AuthPageController::class, 'verifyEmail'])->name('verification.notice');
    Route::inertia('/settings', 'Settings/Profile')->name('settings');
});

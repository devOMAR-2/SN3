<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Guest interface-language switch (FND-006). Signed-in users use PATCH /me (FND-004).
Route::put('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');

<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Health probes are registered outside the "web" middleware group: they must not
| start sessions, set cookies or touch the session store on every probe.
*/

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

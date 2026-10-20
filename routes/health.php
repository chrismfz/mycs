<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// A monitor's check (docs/ops.md). No web middleware: no session, cookie or CSRF for a machine endpoint.
Route::get('/health', HealthController::class)->name('health');

<?php

use App\Http\Controllers\LicensingCheckController;
use App\Http\Controllers\ZlicController;
use Illuminate\Support\Facades\Route;

// nixpal licensing checks (WHMCS-era URL). No web middleware: no session, cookie or CSRF for a machine endpoint.
Route::match(['GET', 'POST'], '/zlic.php', ZlicController::class)->name('zlic');
// The new releases' check: zlic.php's answer, plus what the software says about itself (docs/licensing.md).
Route::post('/licensing/v1/check', LicensingCheckController::class)->name('licensing.check');

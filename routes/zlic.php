<?php

use App\Http\Controllers\ZlicController;
use Illuminate\Support\Facades\Route;

// nixpal licensing checks (WHMCS-era URL). No web middleware: no session, cookie or CSRF for a machine endpoint.
Route::match(['GET', 'POST'], '/zlic.php', ZlicController::class)->name('zlic');

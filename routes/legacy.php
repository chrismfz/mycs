<?php

use App\Http\Controllers\LegacyPayPalIpnController;
use Illuminate\Support\Facades\Route;

// WHMCS-era URLs that outside parties still call after the move (PR H2b). No web middleware: no session, cookie or CSRF.
// The PayPal IPN of WHMCS's own PayPal module: its legacy subscriptions and one-off payments carry this URL as notify_url.
// Throttled per address: each request makes us call PayPal (code review CR-3).
Route::post('/modules/gateways/callback/paypal.php', LegacyPayPalIpnController::class)->middleware('throttle:30,1')
    ->name('extensions.gateways.paypal_legacy.ipn');

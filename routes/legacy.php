<?php

use App\Http\Controllers\LegacyPayPalIpnController;
use Illuminate\Support\Facades\Route;

// WHMCS-era URLs that outside parties still call after the move (PR H2b). No web middleware: no session, cookie or CSRF.
// The PayPal IPN of WHMCS's own PayPal module: its legacy subscriptions and one-off payments carry this URL as notify_url.
Route::post('/modules/gateways/callback/paypal.php', LegacyPayPalIpnController::class)->name('extensions.gateways.paypal_legacy.ipn');

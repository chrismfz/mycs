<?php

use App\Http\Controllers\LegacyPayPalIpnController;
use Illuminate\Support\Facades\Route;

// WHMCS-era URLs that outside parties still call after the move (PR H2b). No web middleware: no session, cookie or CSRF.
// The PayPal IPN of WHMCS's own PayPal module: its legacy subscriptions and one-off payments carry this URL as notify_url.
// Throttled in the controller, on forged requests only (adversarial ADV-3).
Route::post('/modules/gateways/callback/paypal.php', LegacyPayPalIpnController::class)->name('extensions.gateways.paypal_legacy.ipn');
// The same under /clients: myip's WHMCS lived there, and myip.gr hands /clients/modules/gateways/callback/* on to here
// as it came (a POST is never redirected: cutover-runbook, the 301 map).
Route::post('/clients/modules/gateways/callback/paypal.php', LegacyPayPalIpnController::class)->name('extensions.gateways.paypal_legacy.ipn_clients');

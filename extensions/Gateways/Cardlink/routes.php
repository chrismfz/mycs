<?php

use Illuminate\Support\Facades\Route;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;

// The vPOS posts each result here twice (the customer's browser and its own server); a cancel may come back as a plain
// GET. Outside the web group: no session, no CSRF (the return is signed by the vPOS).
Route::match(['get', 'post'], '/extensions/cardlink/return', [Cardlink::class, 'callback'])->name('extensions.gateways.cardlink.return');

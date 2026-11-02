<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use MyCS\Extensions\Gateways\Stripe\Stripe;

// Named in the action, not with ->name(): loaded late (a gateway created in this request, the routes cached), a name
// given after the route was added is never looked up, and route() of it throws.
Route::post('/extensions/stripe/webhook', ['as' => 'extensions.gateways.stripe.webhook', 'uses' => Stripe::class . '@webhook'])
    ->withoutMiddleware([PreventRequestForgery::class]);
Route::get('/extensions/stripe/setup-agreement', ['as' => 'extensions.gateways.stripe.setup-agreement', 'uses' => Stripe::class . '@setupAgreement'])
    // Saving a payment method on the account needs `invoices`, as its page (docs/accounts.md, PR 2b).
    ->middleware(['web', 'auth', 'client.can:invoices']);

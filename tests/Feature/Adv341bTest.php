<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Adversarial re-review of #341 (e8aac66c): the PayPal_IPN notify route is listed among the callbacks that ask the
 * gateway back, but it answers a forged IPN (PayPal says INVALID) with 200, so no refused callback is ever counted and
 * every forged one still costs a call to PayPal, without end.
 */
class Adv341bTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    public function test_a_flood_of_forged_ipns_stops_costing_calls_to_paypal(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal_IPN'], ['name' => 'PayPal IPN', 'type' => 'gateway']);
        foreach (['email' => 'billing@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake(['https://ipnpb.sandbox.paypal.com/*' => Http::response('INVALID')]);
        Route::group([], base_path('extensions/Gateways/PayPal_IPN/routes.php'));
        app('router')->getRoutes()->refreshNameLookups();

        $flood = AppServiceProvider::GATEWAY_REFUSED_CALLBACKS_PER_MINUTE + 20;
        for ($i = 0; $i < $flood; $i++) {
            $this->post('/extensions/paypal_ipn/notify', ['item_number' => '1', 'mc_gross' => '10.00', 'payment_status' => 'Completed']);
        }

        // Past the limit the forged ones wait and PayPal is no longer asked about them.
        Http::assertSentCount(AppServiceProvider::GATEWAY_REFUSED_CALLBACKS_PER_MINUTE);
    }
}

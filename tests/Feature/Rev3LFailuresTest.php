<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Gateway;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Sweep 3, review of the «failures» fixes (independent reviewer).
 */
class Rev3LFailuresTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stripeGateway(): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        $gateway->settings()->create(['key' => 'stripe_use_subscriptions', 'value' => '1', 'type' => 'boolean']);

        return $gateway;
    }

    private function subscribedService(Client $client): Service
    {
        $service = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-11-20', 'subscription_id' => 'sub_1']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Stripe', 'value' => '1']);

        return $service->fresh();
    }

    /** @param  array<string, mixed>  $object */
    private function event(string $type, array $object): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => 'evt_' . $type, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    /**
     * REV3-LF-1 (on L-FAILURES-1): the subscription of a terminated service still could not be cancelled when its next draft
     * invoice came (invoice.created), and deleting that draft failed too (rescued, reported). The webhook still answers 2xx.
     * Stripe finalises a subscription's draft about an hour after a successful answer to invoice.created and charges the
     * saved card by itself (auto_advance); with no successful answer it waits (up to 72 hours) and sends the event again,
     * which is the retry the fix's comment and staff mail promise («its cancel is tried again»). Answering 2xx here lets
     * Stripe charge the terminated service's customer within the hour, the very outcome of the finding.
     */
    public function test_rev3_lf1_invoice_created_is_not_acknowledged_when_neither_the_cancel_nor_the_draft_delete_went_through(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Cache::flush();
        $this->stripeGateway();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response(['error' => ['type' => 'api_error', 'message' => 'internal']], 500)
                : Http::response(['id' => 'sub_1', 'status' => 'active']),
            'https://api.stripe.com/v1/invoices/*' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response(['error' => ['type' => 'api_error', 'message' => 'internal']], 500)
                : Http::response(['id' => 'in_next', 'status' => 'open']),
        ]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->subscribedService(User::factory()->create()->client());
        Exceptions::fake();
        $service->markTerminated();
        $this->assertSame('sub_1', $service->fresh()->subscription_id, 'precondition: the cancel failed, it still bills');

        $response = $this->event('invoice.created', ['id' => 'in_next', 'object' => 'invoice', 'status' => 'draft',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]]);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/invoices/in_next'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/invoices/in_next/'));
        $this->assertSame('sub_1', $service->fresh()->subscription_id, 'precondition: still not cancelled, the draft still there');
        // Fails: 200, so Stripe finalises the draft in an hour and charges the terminated service's customer.
        $this->assertGreaterThanOrEqual(500, $response->status(), 'Stripe must send invoice.created again, not finalise and charge the draft');
    }
}

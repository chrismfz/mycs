<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * Sweep 8, payments: adversarial verifier. Each test fails for the reported reason on main (6f4b6e29).
 */
class Sweep8PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        MailFacade::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** POST /payment_intents times out (maybe taken); then the customer read answers 503 on the resend. */
    private function unknownThenResentWhileDown(array &$keys, string &$mode): array
    {
        Http::fake(function ($request) use (&$mode, &$keys) {
            if (str_contains($request->url(), '/customers/')) {
                return $mode === 'down' ? Http::response(['error' => ['message' => 'unavailable']], 503) : Http::response(['id' => 'cus_1']);
            }
            if (str_contains($request->url(), '/payment_intents')) {
                $keys[] = $request->header('Idempotency-Key')[0] ?? null;
                if ($mode === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out'); // Stripe may have taken it
                }

                return Http::response(['id' => 'pi_new', 'status' => 'succeeded']);
            }

            return Http::response([], 404);
        });
        $invoice = $this->invoice('2026-11-10');
        $mode = 'timeout';
        $first = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));

        Carbon::setTestNow('2026-11-10 09:01:00');
        $mode = 'down';
        $second = (new SavedMethodCharge)->charge($invoice->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame($first->id, $second->id, 'the same attempt, resent');
        $this->assertCount(1, $keys, 'the resend never reached POST /payment_intents');

        return [$invoice, $second];
    }

    /** S8-PAYMENTS-1: a resend that was not sent says nothing of the first POST: the charge is still of unknown outcome. */
    public function test_s8_payments_1_a_resend_not_sent_keeps_the_unknown_charge_holding_the_invoice(): void
    {
        $keys = [];
        $mode = 'timeout';
        [$invoice, $attempt] = $this->unknownThenResentWhileDown($keys, $mode);

        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->fresh()->status, 'the first POST may have taken the money');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'the invoice is still held');
    }

    /** S8-PAYMENTS-1: and so the next charge goes out with a new idempotency key: a second charge of the card. */
    public function test_s8_payments_1_the_next_charge_is_not_sent_with_a_new_key(): void
    {
        $keys = [];
        $mode = 'timeout';
        [$invoice] = $this->unknownThenResentWhileDown($keys, $mode);

        Carbon::setTestNow('2026-11-10 09:02:00');
        $mode = 'up';
        try {
            (new SavedMethodCharge)->charge($invoice->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
        }
        $this->assertCount(1, array_unique($keys), 'a second idempotency key is a second charge of the card');
    }

    /** S8-PAYMENTS-2: Stripe refuses OUR key (403) on the cron's charge: no card was asked, so nothing was declined. */
    public function test_s8_payments_2_a_key_stripe_refuses_is_not_a_decline_of_the_card(): void
    {
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $this->agreement->id])->save();
        $intents = 0;
        Http::fake(function ($request) use (&$intents) {
            $intents += str_contains($request->url(), '/payment_intents') ? 1 : 0;

            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions']], 403);
        });
        $invoice = $this->invoice('2026-11-10');
        (new AutoCharge)->run();

        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($attempt, 'the cron charged it');
        $this->assertSame(0, $intents, 'no charge was ever sent to Stripe');
        $this->assertSame(0, (int) $this->agreement->fresh()->failed_count, 'no decline is counted on the method');
        $this->assertNotSame(ChargeAttempt::STATUS_FAILED, $attempt->status, 'Stripe refused our key: no card was declined');
    }

    /** S8-PAYMENTS-3: PayPal's token call fails before any order is posted: nothing can have been taken. */
    public function test_s8_payments_3_a_paypal_token_outage_is_not_an_unknown_outcome(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'test_mode' => true, 'webhook_id' => 'WH'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'vault_1', 'type' => 'paypal']);
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $vault->id])->save();
        $orders = 0;
        Http::fake(function ($request) use (&$orders) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return Http::response(['error' => 'unavailable'], 503);
            }
            $orders++;

            return Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]]]]]]);
        });
        $invoice = $this->invoice('2026-11-10');
        (new AutoCharge)->run();
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($attempt, 'the cron charged it');
        $this->assertSame(0, $orders, 'no order was posted');

        $this->assertNotSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->fresh()->status, 'no order was ever posted: nothing can have been taken');
        // Past PayPal's 3-hour key window it is never resent, and it holds the invoice for every way of paying until staff
        // resolve it by hand.
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'not held for ever by a charge that was never sent');
    }
}

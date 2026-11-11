<?php

namespace Tests\Feature;

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
use App\Services\Invoice\PaymentStatus;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * Adversarial review of PR #791, second pass (head 9deeaacc: «a resend of an unknown charge is settled only by a success»).
 * Both tests pass on main and on the first pass's head (44af0a96) and fail on 9deeaacc: they show what the new rule costs
 * when Stripe DOES answer the resent POST for the attempt's own key and the answer is «no money». Nothing is fixed.
 */
class Rev791R2Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $card;

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
        $stripe = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $stripe->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $this->card->id])->save();
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-10']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /**
     * Stripe: the customer is found; POST /payment_intents does as $mode says.
     *
     * @param  list<string|null>  $keys  the idempotency key of each POST /payment_intents
     */
    private function stripe(string &$mode, array &$keys): void
    {
        Http::fake(function ($request) use (&$mode, &$keys) {
            if (str_contains($request->url(), '/customers/')) {
                return Http::response(['id' => 'cus_1']);
            }
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return match ($mode) {
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                'decline' => Http::response(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds']], 402),
                // An off-session confirm the bank wants confirmed: Stripe's 402, the intent left «requires_payment_method».
                '3ds' => Http::response(['error' => ['type' => 'card_error', 'code' => 'authentication_required',
                    'payment_intent' => ['id' => 'pi_1', 'status' => 'requires_payment_method']]], 402),
                default => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
            };
        });
    }

    private function charge(Invoice $invoice, string $source = ChargeAttempt::SOURCE_CLIENT): ChargeAttempt
    {
        return (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, $source)->fresh();
    }

    /**
     * REV-791-R2-1 (medium; introduced by 9deeaacc, deliberate per its docs: «a real decline answered on the resend stays so
     * until staff resolve it»).
     *
     * The first POST times out; the resend reaches POST /payment_intents with the attempt's own idempotency key and Stripe
     * answers 402 card_declined. Under one key Stripe gives one result: that answer is the first send's too, so no money was
     * taken. On main the attempt becomes FAILED and the invoice is payable again. Now it stays UNKNOWN: the invoice is held
     * against every way of paying (another saved method, a card checkout, credit: «A payment for this invoice is already on
     * its way»), the page says «Waiting for the bank to answer», the customer is not told the card was declined, the
     * decline is not counted, and Stripe's answer is kept nowhere (the attempt keeps its first error, a DisplayException is
     * not reported). Only staff's «Resolve charge» frees it, while the cron's suspension of the service runs on.
     *
     * Instead: an answer to the charge POST itself that says the card was declined settles the attempt as failed (the
     * rule «stays unknown» for a resend that did not reach the POST, or met a 409/5xx/timeout there).
     */
    public function test_rev_791_r2_1_a_decline_stripe_answers_for_the_attempts_own_key_settles_it(): void
    {
        [$mode, $keys] = ['timeout', []];
        $this->stripe($mode, $keys);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);

        Carbon::setTestNow('2026-11-10 09:01:00');
        $mode = 'decline';
        $resent = $this->charge($invoice);
        $this->assertSame($first->id, $resent->id);
        $this->assertSame([$first->key, $first->key], $keys, 'Stripe answered the charge POST for the same key');
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $resent->status, 'declined under its own key: no money was taken');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'the customer can pay another way');
        $this->assertTrue(SavedMethodCharge::openCheckout($invoice->fresh()));
    }

    /**
     * REV-791-R2-2 (medium; introduced by 9deeaacc).
     *
     * As above, the resend answered 402 authentication_required (3-D Secure) for the attempt's own key: no money was taken,
     * and the saved charge's intent can never be completed (nothing hands its client secret to the customer; Stripe::pay()
     * always posts a new intent). On main the attempt becomes ACTION_REQUIRED, the invoice's page says «Your bank asks you
     * to confirm … pay here by entering your card in the payment form» and the checkout opens. Now it stays UNKNOWN: the
     * page says «Waiting for the bank to answer … no other payment can be made for this invoice until it is settled», the
     * click answers «We could not confirm the payment with your bank yet», and the card checkout the customer needs is
     * refused («A payment for this invoice is already on its way») until staff «Resolve charge».
     *
     * Instead: as on main, the bank's request to confirm settles the attempt as action required and frees the checkout.
     */
    public function test_rev_791_r2_2_the_bank_asking_to_confirm_on_a_resend_lets_the_customer_pay_at_the_checkout(): void
    {
        [$mode, $keys] = ['timeout', []];
        $this->stripe($mode, $keys);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);

        Carbon::setTestNow('2026-11-10 09:01:00');
        $mode = '3ds';
        $resent = $this->charge($invoice);
        $this->assertSame([$first->key, $first->key], $keys, 'Stripe answered the charge POST for the same key');
        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, $resent->status);
        $this->assertSame(PaymentStatus::ACTION, PaymentStatus::for($invoice->fresh())->state, 'the page tells the customer to pay with the card form');
        $this->assertTrue(SavedMethodCharge::openCheckout($invoice->fresh()), 'and the checkout opens');
    }
}

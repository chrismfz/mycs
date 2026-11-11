<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
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
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of PR #791 (saved-method charge: a resend not sent, no PayPal token, Stripe refusing our key). Each
 * test fails on the PR's head for the reason in its docblock, which also says what should happen instead. Nothing is fixed.
 */
class Rev791Test extends TestCase
{
    use RefreshDatabase;

    private const KEY_REFUSED_SUBJECT = 'Stripe: saved cards cannot be charged';

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
     * Stripe: GET /customers answers $customer (an HTTP status, 200 = found); POST /payment_intents does as $mode says.
     *
     * @param  list<string|null>  $keys  the idempotency key of each POST /payment_intents
     */
    private function stripe(string &$mode, array &$keys, int &$customer): void
    {
        Http::fake(function ($request) use (&$mode, &$keys, &$customer) {
            if (str_contains($request->url(), '/customers/')) {
                return $customer === 200 ? Http::response(['id' => 'cus_1'])
                    : Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer: \'cus_1\'']], $customer);
            }
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return match ($mode) {
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                // Stripe's answer while the first request with this key is still running at Stripe.
                'in_use' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'idempotency_key_in_use',
                    'message' => 'There is currently another in-progress request using this Idempotent Key.']], 409),
                // The payment method is of the other mode; the customer was just read with this key in this mode.
                'other_mode_method' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
                    'message' => 'No such PaymentMethod: \'pm_1\'; a similar object exists in test mode, but a live mode key was used to make this request.']], 404),
                default => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
            };
        });
    }

    private function charge(Invoice $invoice, string $source = ChargeAttempt::SOURCE_CLIENT): ChargeAttempt
    {
        return (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, $source)->fresh();
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * REV-791-1 (high; already so on main, but the PR's docs now promise «only the gateway's answer for the same key
     * (taken, declined) or staff close an unknown charge»).
     *
     * The customer pays with the saved card; POST /payment_intents times out on our side after the request left (Stripe
     * is slow: the charge is still running there, and may take the money). The attempt is unknown and holds the invoice.
     * The customer clicks again: the resend goes out with the same key and Stripe answers 409 idempotency_key_in_use (the
     * first request with this key is still in progress). That says nothing of the money, yet Stripe::charge() reads every
     * 4xx it does not know as «Could not process payment» (DisplayException): the attempt becomes FAILED, the hold is
     * released, and the customer's third click is a NEW attempt with a NEW key: a second charge of the card while the
     * first is taken at Stripe.
     *
     * Instead: a 409 on a resend leaves the attempt unknown (with its key and its hold), as a resend not sent does; every
     * later charge goes out with the same key.
     */
    public function test_rev_791_1_stripe_saying_the_key_is_still_in_use_must_not_release_the_unknown_charge(): void
    {
        [$mode, $keys, $customer] = ['timeout', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);

        Carbon::setTestNow('2026-11-10 09:00:40');
        $mode = 'in_use';
        $resent = $this->charge($invoice);
        $this->assertSame($first->id, $resent->id);

        // The third click, Stripe answering again: whatever the attempt was left as, no charge may go out under a new key.
        Carbon::setTestNow('2026-11-10 09:01:20');
        $mode = 'ok';
        try {
            $this->charge($invoice);
        } catch (DisplayException) {
            // held: nothing sent
        }
        $this->assertCount(1, array_unique($keys), 'a second idempotency key went to Stripe: the card can be charged twice');
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $resent->status, 'a 409 «key in use» says nothing of the money: the charge is still of unknown outcome');
    }

    /**
     * REV-791-2 (medium; already so on main: S8-PAYMENTS-1 was fixed for ChargeNotSent only).
     *
     * A resend can also be stopped before POST /payment_intents by a DisplayException: the customer read answers a plain
     * 404 (the customer deleted at Stripe after the first send; deleting it refunds nothing), the `stripe_id` property is
     * gone («Stripe customer not found»), the method was removed meanwhile (ExtensionHelper::charge). Like a resend not
     * sent, that says nothing of the first POST, which timed out and may have taken the money; but the attempt becomes
     * FAILED, the invoice is released, and (from the cron) the customer is mailed «payment failed». The next charge of the
     * invoice (another method, a checkout, or this one once the customer exists again) is new money on top.
     *
     * Instead: a resend that did not reach the charge POST, for whatever reason, leaves the attempt unknown with its key
     * and its hold; only an answer to the POST itself settles it.
     */
    public function test_rev_791_2_a_resend_stopped_before_the_post_by_a_refusal_must_not_release_the_unknown_charge(): void
    {
        [$mode, $keys, $customer] = ['timeout', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice, ChargeAttempt::SOURCE_CRON);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);

        Carbon::setTestNow('2026-11-10 09:01:00');
        [$customer, $mode] = [404, 'ok'];
        $resent = $this->charge($invoice, ChargeAttempt::SOURCE_CRON);
        $this->assertSame($first->id, $resent->id);
        $this->assertCount(1, $keys, 'the resend never reached the charge');
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $resent->status, 'the first POST may have taken the money: nothing since says it did not');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'the invoice stays held');
        $this->assertSame(0, (int) $this->card->fresh()->failed_count, 'no decline is counted for a charge nobody answered');
    }

    /**
     * REV-791-3 (low; introduced by this PR: chargeKeyRefused() is new in the charge path).
     *
     * Stripe refuses our key on the customer read: nothing was sent. chargeKeyRefused() then claims the hourly notice
     * (Cache::add), writes the payments log and queues the staff mail BEFORE it returns the ChargeNotSent. When any of
     * those throws (the queue's or the cache's Redis is down, the mail cannot be rendered), the exception leaves
     * Stripe::charge() as a plain Throwable and SavedMethodCharge keeps the attempt as UNKNOWN: a charge that provably never
     * left holds the invoice against every way of paying until staff «Resolve charge» it, staff were not told of the key,
     * and the notice's claim is kept for an hour.
     *
     * Instead: the charge is «not sent» whatever happens to the notice (report the notice's failure, drop its claim).
     */
    public function test_rev_791_3_a_staff_notice_that_fails_must_not_turn_a_charge_never_sent_into_an_unknown_outcome(): void
    {
        [$mode, $keys, $customer] = ['ok', [], 401];
        $this->stripe($mode, $keys, $customer);
        MailFacade::shouldReceive('to')->andThrow(new RuntimeException('queue connection refused'));
        $invoice = $this->invoice();

        $attempt = $this->charge($invoice);
        $this->assertSame([], $keys, 'no charge was sent');
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status, 'nothing was sent: there is no outcome to be unknown');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'and nothing holds the invoice');
    }

    /**
     * REV-791-4 (low; introduced by this PR: on main this charge fails, the customer is mailed and the decline counted).
     *
     * keyRefused() reads Stripe's 404 «a similar object exists in test mode» as OUR key refused wherever it comes. On the
     * charge POST it comes after GET /customers was answered 200 with the very same key: the key is accepted and of the
     * customer's mode, and it is the saved payment method that is of the other mode (one saved while the gateway was in
     * test mode, its customer since made again in live mode). That method can never be charged, yet each run keeps it as
     * «not sent»: the customer is never told the payment failed (the reminders still say «charged on its own»), the
     * method's declines are never counted so it is never stopped, and staff get «check the key», which is fine.
     *
     * Instead: a key that just read the customer is not refused; the method's 404 on the POST is a failed charge (the
     * customer is told, the decline counted), with no «Stripe: saved cards cannot be charged» mail.
     */
    public function test_rev_791_4_a_method_of_the_other_mode_is_not_our_key_refused_when_the_key_just_read_the_customer(): void
    {
        [$mode, $keys, $customer] = ['other_mode_method', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        (new AutoCharge)->run();

        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertCount(1, $keys);
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $attempt->status, 'the key works: this method cannot be charged, now or on any retry');
        $this->assertSame(1, $this->failedMails(), 'the customer is told to pay another way');
        $this->assertSame(1, (int) $this->card->fresh()->failed_count);
        $this->assertSame(0, EmailLog::query()->where('to', 'staff@example.test')->where('subject', self::KEY_REFUSED_SUBJECT)->count(),
            'staff are not sent to check a key that works');
    }
}

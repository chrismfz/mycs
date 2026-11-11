<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * Sweep 8, payments: the fixes' own tests, and the neighbouring behaviour they must keep (the findings' failing tests are
 * Sweep8PaymentsTest).
 */
class Sweep8PaymentsFixTest extends TestCase
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
     * Stripe as $mode says: the customer read answers $customer (an HTTP status), POST /payment_intents times out
     * («timeout»), declines the card («decline»), refuses our key («forbidden») or takes it.
     *
     * @param  list<string|null>  $keys  the idempotency keys of the charges sent
     */
    private function stripe(string &$mode, array &$keys, int &$customer): void
    {
        Http::fake(function ($request) use (&$mode, &$keys, &$customer) {
            if (str_contains($request->url(), '/customers/')) {
                return $customer === 200 ? Http::response(['id' => 'cus_1']) : Http::response(['error' => ['message' => 'no']], $customer);
            }
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return match ($mode) {
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                'decline' => Http::response(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds']], 402),
                'forbidden' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions']], 403),
                default => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
            };
        });
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    private function staffMails(string $subject): int
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', $subject)->count();
    }

    private function charge(Invoice $invoice, string $source = ChargeAttempt::SOURCE_CLIENT): ChargeAttempt
    {
        return (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, $source)->fresh();
    }

    /** S8-PAYMENTS-1: the resend not sent leaves the attempt as it was: unknown, its first error, its key; no decline counted. */
    public function test_a_resend_not_sent_leaves_the_unknown_attempt_as_it_was(): void
    {
        [$mode, $keys, $customer] = ['timeout', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice, ChargeAttempt::SOURCE_CRON);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);

        Carbon::setTestNow('2026-11-10 09:01:00');
        // Not sent two ways: Stripe down, and Stripe refusing our key, before the charge.
        foreach ([[503, 'ok'], [401, 'ok']] as [$customer, $mode]) {
            $resent = $this->charge($invoice, ChargeAttempt::SOURCE_CRON);
            $this->assertSame($first->id, $resent->id);
            $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $resent->status);
            $this->assertSame($first->error, $resent->error, 'the first send\'s error is what staff check at the gateway');
            $this->assertSame($first->key, $resent->key);
            $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));
        }
        $this->assertCount(1, $keys, 'neither resend reached the charge');
        $this->assertSame(0, (int) $this->card->fresh()->failed_count);
        $this->assertSame(0, $this->failedMails());
        $this->assertSame(1, ChargeAttempt::query()->count());
    }

    /** The neighbour: a resend that IS sent settles the original with Stripe's answer for its key: taken. */
    public function test_a_resend_that_is_sent_and_taken_settles_the_original(): void
    {
        [$mode, $keys, $customer] = ['timeout', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);

        Carbon::setTestNow('2026-11-10 09:01:00');
        $mode = 'ok';
        $resent = $this->charge($invoice);
        $this->assertSame($first->id, $resent->id);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $resent->status);
        $this->assertNull($resent->error);
        $this->assertSame([$first->key, $first->key], $keys, 'sent again with its own key');
    }

    /** The neighbour: a resend that IS sent and declined settles the original as failed, and the invoice is payable again. */
    public function test_a_resend_that_is_sent_and_declined_settles_the_original_and_releases_the_invoice(): void
    {
        [$mode, $keys, $customer] = ['timeout', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);

        Carbon::setTestNow('2026-11-10 09:01:00');
        $mode = 'decline';
        $resent = $this->charge($invoice);
        $this->assertSame($first->id, $resent->id);
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $resent->status);
        $this->assertSame('Card declined: insufficient_funds', $resent->error);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
    }

    /** The neighbour: a FIRST charge not sent is still «not sent», holds nothing, and the next one is a new charge. */
    public function test_a_first_charge_not_sent_is_still_not_sent_and_holds_nothing(): void
    {
        [$mode, $keys, $customer] = ['ok', [], 503];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        $first = $this->charge($invoice);
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $first->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));

        $customer = 200;
        $second = $this->charge($invoice);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $second->status);
        $this->assertSame([$second->key], $keys);
    }

    /** S8-PAYMENTS-2: our key refused on the customer read (401, 403, the other mode's 404): not sent, staff told once an hour. */
    public function test_a_key_stripe_refuses_before_the_charge_is_ours_to_fix_and_staff_are_told_once(): void
    {
        $refusals = [
            [401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']]],
            [403, ['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions']]],
            [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
                'message' => 'No such customer: \'cus_1\'; a similar object exists in live mode, but a test mode key was used to make this request.']]],
        ];
        $answer = null;
        $intents = 0;
        Http::fake(function ($request) use (&$answer, &$intents) {
            $intents += str_contains($request->url(), '/payment_intents') ? 1 : 0;

            return Http::response($answer[1], $answer[0]);
        });
        foreach ($refusals as $answer) {
            $invoice = $this->invoice();
            (new AutoCharge)->run();
            $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
            $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status, 'HTTP ' . $answer[0]);
            $this->assertSame('Stripe refused our key: nothing was charged', $attempt->error);
            $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
            $invoice->update(['status' => Invoice::STATUS_CANCELLED]); // the next refusal's run charges its own invoice only
        }
        $this->assertSame(0, $intents);
        $this->assertSame(0, (int) $this->card->fresh()->failed_count, 'no decline is counted on the method');
        $this->assertNull($this->card->fresh()->last_failed_at);
        $this->assertSame(0, $this->failedMails(), 'the customer is not told the payment failed');
        $this->assertSame(1, $this->staffMails(self::KEY_REFUSED_SUBJECT), 'staff are told, once an hour');
        $this->assertSame(3, $this->staffMails('Automatic charges declined'), 'and each run\'s digest lists it, as any charge not taken');

        // The cron tries again as set: the next day, with a key that works.
        Carbon::setTestNow('2026-11-11 09:00:00');
        $again = $this->invoice();
        $again->update(['due_at' => '2026-11-11']);
        $answer = [200, ['id' => 'pi_2', 'status' => 'succeeded']];
        (new AutoCharge)->run();
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::query()->where('invoice_id', $again->id)->sole()->status);
    }

    /** S8-PAYMENTS-2: a key that may read customers and not charge (403 on the charge itself): Stripe ran nothing. */
    public function test_a_key_stripe_refuses_on_the_charge_itself_is_not_a_decline(): void
    {
        [$mode, $keys, $customer] = ['forbidden', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        (new AutoCharge)->run();

        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertCount(1, $keys);
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status);
        $this->assertSame(0, (int) $this->card->fresh()->failed_count);
        $this->assertSame(0, $this->failedMails());
        $this->assertSame(1, $this->staffMails(self::KEY_REFUSED_SUBJECT));
    }

    /** The neighbour: a card Stripe declines is still a decline: the customer is mailed, the method's count rises, no key mail. */
    public function test_a_card_stripe_declines_still_mails_the_customer_and_counts(): void
    {
        [$mode, $keys, $customer] = ['decline', [], 200];
        $this->stripe($mode, $keys, $customer);
        $invoice = $this->invoice();
        (new AutoCharge)->run();

        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('Card declined: insufficient_funds', $attempt->error);
        $this->assertSame(1, (int) $this->card->fresh()->failed_count);
        $this->assertSame(1, $this->failedMails());
        $this->assertSame(0, $this->staffMails(self::KEY_REFUSED_SUBJECT));
    }

    /** The neighbour: a customer Stripe no longer has (a plain 404) is not our key: the charge fails as before. */
    public function test_a_customer_gone_at_stripe_is_not_a_key_refusal(): void
    {
        Http::fake(fn () => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer: \'cus_1\'']], 404));
        $invoice = $this->invoice();
        (new AutoCharge)->run();

        $this->assertSame(ChargeAttempt::STATUS_FAILED, ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);
        $this->assertSame(0, $this->staffMails(self::KEY_REFUSED_SUBJECT));
    }

    /**
     * PayPal as $token (the token call's HTTP status; 0: no connection) and $order (the order POST's) say.
     *
     * @param  list<string|null>  $ids  the PayPal-Request-Id of each order posted
     */
    private function paypal(int &$token, int &$order, array &$ids): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'test_mode' => true, 'webhook_id' => 'WH'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::fake(function ($request) use (&$token, &$order, &$ids) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                return match ($token) {
                    0 => throw new ConnectionException('cURL error 28: Operation timed out'),
                    200 => Http::response(['access_token' => 'token']),
                    default => Http::response(['error' => 'no'], $token),
                };
            }
            $ids[] = $request->header('PayPal-Request-Id')[0] ?? null;

            return Http::response(['name' => 'INTERNAL_SERVER_ERROR'], $order);
        });

        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'vault_1', 'type' => 'paypal']);
    }

    /** S8-PAYMENTS-3: no token (PayPal down, no connection, our credentials refused): no order posted, so not sent. */
    public function test_paypal_giving_no_token_is_a_charge_not_sent(): void
    {
        [$token, $order, $ids] = [503, 500, []];
        $vault = $this->paypal($token, $order, $ids);
        $this->client->forceFill(['default_billing_agreement_id' => $vault->id])->save();
        foreach ([503, 0, 401] as $token) {
            $invoice = $this->invoice();
            $attempt = (new SavedMethodCharge)->charge($invoice, $vault, ChargeAttempt::SOURCE_CRON)->fresh();
            $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status, 'token: ' . $token);
            $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
        }
        $this->assertSame([], $ids, 'no order was posted');
        $this->assertSame(0, (int) $vault->fresh()->failed_count);
    }

    /**
     * The neighbour: a 5xx AFTER the order was posted is still of unknown outcome and holds the invoice; and its resend
     * that gets no token leaves it so (S8-PAYMENTS-1 with S8-PAYMENTS-3).
     */
    public function test_a_paypal_failure_after_the_order_was_posted_stays_unknown(): void
    {
        [$token, $order, $ids] = [200, 500, []];
        $vault = $this->paypal($token, $order, $ids);
        $invoice = $this->invoice();
        $first = (new SavedMethodCharge)->charge($invoice, $vault, ChargeAttempt::SOURCE_CLIENT)->fresh();
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $first->status);
        $this->assertCount(1, $ids);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));

        Carbon::setTestNow('2026-11-10 09:01:00');
        $token = 503;
        $resent = (new SavedMethodCharge)->charge($invoice->fresh(), $vault, ChargeAttempt::SOURCE_CLIENT)->fresh();
        $this->assertSame($first->id, $resent->id);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $resent->status);
        $this->assertCount(1, $ids, 'the resend posted no order');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));
    }
}

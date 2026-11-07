<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\GatewayReference;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\S3Checkout\S3Checkout;
use MyCS\Extensions\Gateways\S3Saved\S3Saved;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Sweep 3, lens «no double charge» (adversarial verifier). Each test fails on main for the finding it names.
 */
#[Group('adversarial')]
class Sweep3LNoDoubleChargeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_sweep3';

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.credits_enabled' => true, 'settings.system_email_address' => 'staff@example.test',
            'settings.mail_disable' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        foreach (['S3Checkout', 'S3Saved'] as $name) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} {
                    /** @var list<string> */
                    public static array \$calls = [];
                    public \$explicitConfig = false;
                    public function __construct(\$config = []) {}
                    public function getConfig(\$values = []) { return []; }
                    public function config(\$key) { return null; }
                    public function supportsBillingAgreements() { return true; }
                    public function supportsPaymentGroups() { return true; }
                    public function cancelBillingAgreement(\$agreement): bool { return true; }
                    public function pay(\$invoice, \$total) { self::\$calls[] = 'pay:' . \$total; return 'https://{$name}.test/checkout'; }
                    public function charge(\$invoice, \$amount, \$agreement) {
                        self::\$calls[] = 'charge:' . \$amount;
                        \\App\\Helpers\\ExtensionHelper::addPayment(\$invoice->id, '{$name}', \$amount, null, 'ch_saved_' . count(self::\$calls), currency: 'USD');
                        return true;
                    }
                }");
            }
        }
        S3Checkout::$calls = [];
        S3Saved::$calls = [];
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 08:59:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due today, for 10.00 USD. */
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

    private function stripeWebhook(string $type, array $object)
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => $object]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::SECRET),
        ], $payload);
    }

    /**
     * L-NO-DOUBLE-CHARGE-1. A saved Stripe method (a SEPA debit) is charged by the cron; Stripe answers «processing», so the
     * attempt is kept «succeeded». The debit then fails (payment_intent.payment_failed): the payment row turns Failed, but
     * nothing touches the attempt. It now counts as «taken, not recorded»: the invoice is held for every way of paying,
     * for ever, though no money came (PayPal settles the same case: deniedChargeAttempt).
     */
    public function test_l_no_double_charge_1_a_failed_sepa_debit_leaves_its_attempt_holding_the_invoice(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'SEPA DE89', 'external_reference' => 'pm_sepa', 'type' => 'sepa_debit']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_sepa']);
        $this->client->refresh();
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_sepa']),
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_sepa', 'object' => 'payment_intent', 'status' => 'processing']),
        ]);
        $invoice = $this->invoice();
        $this->assertSame($agreement->id, AutoCharge::agreementFor($invoice)?->id, 'precondition: the cron charges the SEPA mandate');

        Carbon::setTestNow('2026-11-10 09:00:00');
        (new AutoCharge)->run();
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->status, 'precondition: «processing» is kept as succeeded');

        $intent = ['id' => 'pi_sepa', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id, 'billing_agreement_id' => (string) $agreement->id]];
        $this->stripeWebhook('payment_intent.processing', $intent + ['status' => 'processing'])->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status, 'precondition: the debit is on its way');

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->stripeWebhook('payment_intent.payment_failed', $intent + ['status' => 'requires_payment_method'])->assertOk();
        $this->assertSame(InvoiceTransactionStatus::Failed, $invoice->transactions()->sole()->status, 'precondition: the debit bounced, no money came');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()),
            'no money is on its way, yet the invoice is held for every way of paying (and the cron neither retries nor suspends)');
        $this->assertNotSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->fresh()->status,
            'the debit failed at Stripe, yet its charge attempt still says «succeeded» (taken, payment not recorded)');
    }

    /**
     * L-NO-DOUBLE-CHARGE-2. The customer opens a hosted checkout on the invoice page (the hold is read only then); the cron
     * then charges the saved method; the checkout's money arrives afterwards and lands as account credit. The customer's
     * money is taken twice for one invoice.
     */
    public function test_l_no_double_charge_2_a_checkout_opened_before_the_cron_charge_takes_the_debt_again(): void
    {
        $checkout = Gateway::query()->create(['extension' => 'S3Checkout', 'name' => 'Card checkout', 'type' => 'gateway', 'enabled' => true]);
        $saved = Gateway::query()->create(['extension' => 'S3Saved', 'name' => 'Saved card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $saved->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
        $invoice = $this->invoice();
        $this->assertSame($agreement->id, AutoCharge::agreementFor($invoice)?->id, 'precondition: the cron charges the saved card today');

        // 08:59: the customer picks the card checkout on the invoice page; it opens for the full 10.00.
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $checkout->id)->call('processPayment');
        $this->assertSame(['pay:10'], S3Checkout::$calls, 'precondition: the checkout was opened');

        // 09:00: the cron's run charges the saved card.
        Carbon::setTestNow('2026-11-10 09:00:00');
        (new AutoCharge)->run();

        // 09:02: the customer completes the checkout; its gateway reports the money.
        Carbon::setTestNow('2026-11-10 09:02:00');
        ExtensionHelper::addPayment($invoice->id, 'S3Checkout', 10, null, 'chk_1', currency: 'USD');

        $taken = InvoiceTransaction::query()->where('invoice_id', $invoice->id)->where('is_credit_transaction', false)
            ->where('status', InvoiceTransactionStatus::Succeeded)->whereNotNull('gateway_id')->sum('amount');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals(10.0, (float) $taken, 'one invoice of 10.00, yet the customer was charged ' . $taken . ' through two gateways');
    }

    /** A Stripe gateway and a saved method of the customer on it, the customer known to Stripe. */
    private function stripeAgreement(string $type = 'card'): BillingAgreement
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Method', 'external_reference' => 'pm_x', 'type' => $type]);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_x']);
        $this->client->refresh();

        return $agreement;
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /** L-NO-DOUBLE-CHARGE-1, with no «processing» event first: the failure alone settles the attempt, by its key. */
    public function test_l_no_double_charge_1_a_failure_with_no_processing_event_first_settles_the_attempt_by_its_key(): void
    {
        $agreement = $this->stripeAgreement('sepa_debit');
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_x']),
            'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_k', 'object' => 'payment_intent', 'status' => 'processing']),
        ]);
        $invoice = $this->invoice();
        Carbon::setTestNow('2026-11-10 09:00:00');
        (new AutoCharge)->run();
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment_intents') && ($request->data()['metadata']['charge_attempt'] ?? null) === $attempt->key);

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->stripeWebhook('payment_intent.payment_failed', ['id' => 'pi_k', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'status' => 'requires_payment_method', 'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id,
                'billing_agreement_id' => (string) $agreement->id, 'charge_attempt' => $attempt->key]])->assertOk();

        $this->assertSame(ChargeAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
    }

    /** A failed payment intent of a checkout (no saved method in it) never settles a saved method's charge. */
    public function test_l_no_double_charge_1_a_checkout_failure_leaves_a_saved_charge_alone(): void
    {
        $agreement = $this->stripeAgreement();
        $invoice = $this->invoice();
        $attempt = ChargeAttempt::query()->create(['key' => 'k-1', 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $agreement->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);

        $this->stripeWebhook('payment_intent.payment_failed', ['id' => 'pi_form', 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd',
            'status' => 'requires_payment_method', 'metadata' => ['mycs' => GatewayReference::mark(), 'invoice_id' => (string) $invoice->id]])->assertOk();

        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->fresh()->status, 'the customer\'s own card at the form failed, not the saved charge');
    }

    /** L-NO-DOUBLE-CHARGE-2: the cron waits only CHECKOUT_HOLD_MINUTES for a checkout opened; past them it charges. */
    public function test_l_no_double_charge_2_the_cron_charges_once_the_checkout_hold_is_over(): void
    {
        $checkout = Gateway::query()->create(['extension' => 'S3Checkout', 'name' => 'Card checkout', 'type' => 'gateway', 'enabled' => true]);
        $saved = Gateway::query()->create(['extension' => 'S3Saved', 'name' => 'Saved card', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $saved->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
        $invoice = $this->invoice();
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $checkout->id)->call('processPayment');
        $this->assertTrue(SavedMethodCharge::checkoutOpen($invoice));

        Carbon::setTestNow(Carbon::parse('2026-11-10 08:59:00')->addMinutes(SavedMethodCharge::CHECKOUT_HOLD_MINUTES + 1));
        $this->assertFalse(SavedMethodCharge::checkoutOpen($invoice));
        (new AutoCharge)->run();

        $this->assertSame(['charge:10'], S3Saved::$calls);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** L-NO-DOUBLE-CHARGE-2: a checkout is not opened while a saved method's charge is on its way, and stamps nothing. */
    public function test_l_no_double_charge_2_no_checkout_while_a_charge_is_on_its_way(): void
    {
        $saved = Gateway::query()->create(['extension' => 'S3Saved', 'name' => 'Saved card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $saved->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $invoice = $this->invoice();
        ChargeAttempt::query()->create(['key' => 'k-2', 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $agreement->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now(), 'sent_at' => now()]);

        $this->assertFalse(SavedMethodCharge::openCheckout($invoice));
        $this->assertNull($invoice->fresh()->checkout_opened_at);
    }

    /**
     * L-FAILURES-2 (moved here from Sweep3LFailuresTest, same gateway file): Stripe answers 503 to GET /customers/{id} (an
     * outage) before anything is charged. Not a decline: no «payment failed» mail, no failure counted, the attempt «not sent».
     */
    public function test_l_failures_2_a_stripe_outage_before_the_charge_is_not_counted_as_a_decline(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'unavailable']], 503),
        ]);
        $agreement = $this->stripeAgreement();
        $invoice = $this->invoice();
        Exceptions::fake();

        (new AutoCharge)->run();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payment_intents'));
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(0, $this->failedMails(), 'nothing was declined: the customer is not told the payment failed');
        $this->assertSame(0, (int) $agreement->fresh()->failed_count, 'Stripe was down: the card was never declined');
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'nothing was sent: the invoice is not held');
    }

    /** L-FAILURES-2: a rate limit (429) on the charge itself: Stripe did not run it, so it is no decline either. */
    public function test_l_failures_2_a_rate_limited_charge_is_not_a_decline(): void
    {
        config(['settings.autocharge_max_failures' => 1]);
        Carbon::setTestNow('2026-11-10 09:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_x']),
            'api.stripe.com/v1/payment_intents' => Http::response(['error' => ['type' => 'rate_limit_error']], 429),
        ]);
        $agreement = $this->stripeAgreement();
        $invoice = $this->invoice();

        (new AutoCharge)->run();

        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole()->status);
        $this->assertSame(0, (int) $agreement->fresh()->failed_count);
        $this->assertFalse($agreement->fresh()->stopped(), 'a rate limit never stops the method being charged');
        $this->assertSame(0, $this->failedMails());
    }
}

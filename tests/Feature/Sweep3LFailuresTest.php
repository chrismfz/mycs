<?php

namespace Tests\Feature;

use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\AutoCharge;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use MyCS\Extensions\Gateways\Sweep3TimeoutPay\Sweep3TimeoutPay;
use Tests\TestCase;

/**
 * Sweep 3, lens «failures»: what happens when a gateway call fails (adversarial verifier).
 */
class Sweep3LFailuresTest extends TestCase
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
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => '2026-11-20']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

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

    private function fakeStripeCancelFails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response(['error' => ['type' => 'api_error', 'message' => 'internal']], 500)
                : Http::response(['id' => 'sub_1', 'status' => 'active']),
            'https://api.stripe.com/v1/invoices/*' => Http::response(['id' => 'in_next', 'status' => 'open']),
        ]);
    }

    /**
     * L-FAILURES-1: Stripe's DELETE /subscriptions fails (5xx) on termination; the Updated listener swallows the exception
     * with no report at all (PayPal's at least report()s it), so nobody hears the subscription still bills.
     */
    public function test_l1_a_failed_stripe_cancel_on_termination_is_at_least_reported(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Cache::flush();
        $this->stripeGateway();
        $this->fakeStripeCancelFails();
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->subscribedService(User::factory()->create()->client());
        Exceptions::fake();

        $service->markTerminated();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/subscriptions/sub_1'));
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('sub_1', $service->fresh()->subscription_id, 'the subscription still bills at Stripe');
        // Fails: the exception is caught with «// Ignore exception», never reported, logged, or put before staff.
        Exceptions::assertReported(fn (RequestException $e) => $e->response->status() === 500);
    }

    /**
     * L-FAILURES-1, the consequence: the subscription stays live at Stripe and on the terminated service, and Stripe's next
     * invoice.created is actively finalised and paid for a service that is terminated.
     */
    public function test_l1_the_next_subscription_invoice_of_a_terminated_service_is_not_paid(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Cache::flush();
        $this->stripeGateway();
        $this->fakeStripeCancelFails();
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->subscribedService(User::factory()->create()->client());
        $service->markTerminated();
        $this->assertSame('sub_1', $service->fresh()->subscription_id, 'precondition: the cancel failed');

        $this->event('invoice.created', ['id' => 'in_next', 'object' => 'invoice', 'status' => 'draft',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        // Fails: finalize and pay are POSTed with no look at the service's status.
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices/in_next/pay'));
    }

    /**
     * L-FAILURES-1, the fix: staff are told the subscription still bills, and its next draft invoice is cancelled again
     * and deleted (never finalised or paid). Once the cancel goes through, the service no longer carries it.
     */
    public function test_l1_staff_are_told_and_the_next_invoice_retries_the_cancel_and_deletes_the_draft(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Cache::flush();
        MailFacade::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->stripeGateway();
        $deletes = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => function (Request $request) use (&$deletes) {
                if ($request->method() !== 'DELETE') {
                    return Http::response(['id' => 'sub_1', 'status' => 'active']);
                }

                return ++$deletes === 1 ? Http::response(['error' => ['type' => 'api_error', 'message' => 'internal']], 500)
                    : Http::response(['id' => 'sub_1', 'status' => 'canceled']);
            },
            'https://api.stripe.com/v1/invoices/*' => Http::response(['id' => 'in_next', 'deleted' => true]),
        ]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->subscribedService(User::factory()->create()->client());
        Exceptions::fake();

        $service->markTerminated();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
        $this->assertSame(1, DB::table('email_logs')->where('subject', 'Stripe subscription not cancelled')->count(), 'staff are told');

        $this->event('invoice.created', ['id' => 'in_next', 'object' => 'invoice', 'status' => 'draft',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        $this->assertSame(2, $deletes, 'the cancel is tried again on its next invoice');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/invoices/in_next'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/invoices/in_next/'));
        $this->assertNull($service->fresh()->subscription_id, 'cancelled at last');
        Exceptions::assertReportedCount(1);
    }

    /** L-FAILURES-1: a live service's subscription invoice is still finalised and paid, as before. */
    public function test_l1_a_live_service_subscription_invoice_is_still_paid(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        Cache::flush();
        $this->stripeGateway();
        $this->fakeStripeCancelFails();
        $this->subscribedService(User::factory()->create()->client());

        $this->event('invoice.created', ['id' => 'in_next', 'object' => 'invoice', 'status' => 'draft',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices/in_next/finalize'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices/in_next/pay'));
    }

    /**
     * A client with a Stripe saved card and one renewal invoice due today.
     *
     * @return array{Invoice, BillingAgreement}
     */
    // @phpstan-ignore method.unused (its test is incomplete: L-FAILURES-2 is fixed in l-no-double-charge)
    private function stripeCustomerWithDueInvoice(): array
    {
        $gateway = $this->stripeGateway();
        $client = User::factory()->create()->client();
        $client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        $agreement = BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-10']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return [$invoice->fresh(), $agreement];
    }

    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /**
     * L-FAILURES-2: Stripe answers 503 to GET /customers/{id} (an outage) before anything is charged. It is finished as a
     * decline: failed_count goes up (toward autocharge_max_failures) and the customer is mailed «payment failed».
     */
    public function test_l2_a_stripe_outage_before_the_charge_is_not_counted_as_a_decline(): void
    {
        $this->markTestIncomplete('L-FAILURES-2 is fixed in the l-no-double-charge lens (fix/sweep3-l-no-double-charge), not here.');
        // @phpstan-ignore deadCode.unreachable
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        MailFacade::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'unavailable']], 503),
        ]);
        [$invoice, $agreement] = $this->stripeCustomerWithDueInvoice();
        Exceptions::fake();

        (new AutoCharge)->run();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payment_intents'));
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        // Fails on each: an outage is read as a card decline.
        $this->assertSame(0, $this->failedMails(), 'nothing was declined: the customer is not told the payment failed');
        $this->assertSame(0, (int) $agreement->fresh()->failed_count, 'Stripe was down: the card was never declined');
        $this->assertNotSame(ChargeAttempt::STATUS_FAILED, $attempt->status);
    }

    /**
     * L-FAILURES-3: a cycle change «now» charges the saved method; the gateway gives no answer (a timeout), so the attempt
     * is UNKNOWN (maybe taken), yet the customer is mailed «payment failed» (AutoCharge deliberately does not).
     */
    public function test_l3_a_cycle_change_charge_of_unknown_outcome_does_not_mail_payment_failed(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string'],
            'mail_disable' => [false, 'boolean']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        if (!class_exists(Sweep3TimeoutPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\Sweep3TimeoutPay; class Sweep3TimeoutPay { public static int $calls = 0; public function __construct($config = []) {}
                public function charge($invoice, $amount, $agreement) { self::$calls++; throw new \\Illuminate\\Http\\Client\\ConnectionException("timed out"); } }');
        }
        $product = Product::factory()->create(['name' => 'Hosting']);
        $plans = [];
        foreach (['Yearly' => ['year', 120.00], 'Monthly' => ['month', 12.00]] as $name => [$unit, $price]) {
            $plans[$name] = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $name,
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plans[$name]->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plans['Yearly']->id, 'price' => 120.00, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $paid->items()->create(['description' => 'Renewal', 'price' => 120.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $gateway = Gateway::query()->create(['extension' => 'Sweep3TimeoutPay', 'name' => 'Sweep3TimeoutPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'Card', 'external_reference' => 'c-1']);

        $invoice = ChangeCycle::apply($service->fresh(), $plans['Monthly'], ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame(1, Sweep3TimeoutPay::$calls, 'precondition: charged once'); // @phpstan-ignore class.notFound
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->status, 'precondition: no answer about the money');
        // Fails: the customer is told the payment failed for money that may be taken.
        $this->assertSame(0, $this->failedMails(), 'maybe taken: the customer is not told it failed');
    }
}

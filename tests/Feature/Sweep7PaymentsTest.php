<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\View;
use MyCS\Extensions\Gateways\S7Pay\S7Pay;
use RuntimeException;
use Tests\TestCase;

/**
 * Sweep 7, payments: adversarial verification. Each test fails for the finding it names.
 * S7-PAYMENTS-1 (two overlapping charges, a stale snapshot) needs MariaDB's REPEATABLE READ and two processes: not here.
 */
class Sweep7PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL = 'https://api-m.sandbox.paypal.com';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(S7Pay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\S7Pay; class S7Pay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        S7Pay::$charges = [];
        S7Pay::$answer = null;
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agreement(string $extension, string $reference = 'pm_1'): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $extension], ['name' => $extension, 'type' => 'gateway', 'enabled' => true]);

        return BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => $reference, 'type' => 'card']);
    }

    /** An open renewal invoice of an active service, due on $due. @return array{0: Invoice, 1: Service} */
    private function renewal(string $due): array
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return [$invoice->fresh(), $service];
    }

    /**
     * S7-PAYMENTS-2: Stripe answers the read of the account's customer with a 503 (a blip) while a checkout opens. The
     * account is moved to a new Stripe customer; its saved cards stay on the old one and can no longer be charged.
     */
    public function test_s7_payments_2_a_stripe_blip_does_not_replace_the_accounts_stripe_customer(): void
    {
        $this->client->properties()->create(['key' => 'stripe_id', 'name' => 'Stripe customer', 'value' => 'cus_OLD']);
        $stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_key', 'encrypted' => true]);
        $stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_key']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $invoice->items()->create(['description' => 'x', 'price' => '10.00', 'quantity' => 1]);

        $created = 0;
        Http::fake(function (Request $request) use (&$created) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/customers/cus_OLD')) {
                return Http::response(['error' => ['type' => 'api_error', 'message' => 'Service unavailable']], 503);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/customers')) {
                $created++;

                return Http::response(['id' => 'cus_NEW']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payment_intents')) {
                return Http::response(['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'amount' => 1000, 'currency' => 'usd']);
            }

            return Http::response([], 404);
        });
        View::addNamespace('gateways.stripe', base_path('extensions/Gateways/Stripe/resources/views'));

        try {
            ExtensionHelper::pay($stripe->fresh(), $invoice->fresh());
        } catch (\Throwable) {
            // refusing the checkout during the outage is fine: only the customer id matters here
        }

        $this->assertSame('cus_OLD', $this->client->properties()->where('key', 'stripe_id')->value('value'),
            'the saved cards are attached to cus_OLD: a 503 must not move the account to a new Stripe customer');
        $this->assertSame(0, $created, 'no new Stripe customer for an account that has one');
    }

    /**
     * S7-PAYMENTS-3: a gateway payment that stays «processing» (its final event never came) holds its invoice from every
     * way of paying and its service from suspension, with no limit of time, and staff are never told (only of the imported
     * whmcs-pending-% ones).
     */
    public function test_s7_payments_3_staff_are_told_of_a_gateway_payment_still_processing_after_a_month(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        [$invoice, $service] = $this->renewal('2026-11-10');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10, null, 'pi_never_settled', currency: 'USD');
        $this->assertSame(InvoiceTransactionStatus::Processing, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole()->status);

        Carbon::setTestNow('2026-12-10 09:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        Carbon::setTestNow('2026-12-11 09:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        // What the payment holds, a month on (the reported state; these hold today).
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: 30 days overdue and not suspended, for the payment on its way');
        $this->assertFalse(SavedMethodCharge::openCheckout($invoice->fresh()), 'precondition: the customer cannot pay it');

        $this->assertGreaterThan(0, EmailLog::query()->where('to', 'staff@example.test')->where('body', 'like', "%#{$invoice->id}%")->count(),
            'a native payment processing for a month holds its invoice and its service, and no staff notice names the invoice');
    }

    /**
     * S7-PAYMENTS-4 (a): the gateway timed out during the daily run, so the charge's outcome is unknown, «sent again with
     * its key». The cron never sends it again: once a day the same day, and the next day's run is past the key's 23 hours.
     */
    public function test_s7_payments_4_an_unknown_charge_is_sent_again_by_a_later_run_of_the_cron(): void
    {
        $this->markTestIncomplete('S7-PAYMENTS-4 (low): left as is, recorded only (owner 2026-11-10; docs/sweeps/2026-11-10-seventh.md).');

        MailFacade::fake();
        $agreement = $this->agreement('S7Pay');
        $this->assertSame($agreement->id, $this->client->fresh()->autoChargeAgreement()?->id, 'precondition: the method is the default');
        [$invoice] = $this->renewal('2026-11-10');

        S7Pay::$answer = fn () => throw new RuntimeException('cURL error 28: timed out');
        (new AutoCharge)->run();
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::query()->sole()->status);
        $key = S7Pay::$charges[0]['key'];

        // The gateway is back (nothing was taken); it records the payment when charged, as a webhook would.
        S7Pay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'S7Pay', $amount, null, 'ch_' . count(S7Pay::$charges), currency: 'USD');

            return true;
        };
        // The rest of the day, hourly (the key is still good), and the next three daily runs.
        foreach (['2026-11-10 10:00', '2026-11-10 15:00', '2026-11-10 21:00', '2026-11-11 09:00', '2026-11-12 09:00', '2026-11-13 09:00'] as $at) {
            Carbon::setTestNow($at . ':00');
            (new AutoCharge)->run();
        }

        $this->assertGreaterThan(1, count(S7Pay::$charges), 'the unknown charge was never sent again by the cron: the invoice is held until staff resolve it by hand');
        $this->assertSame($key, S7Pay::$charges[1]['key'] ?? null, 'sent again with its own key');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /**
     * S7-PAYMENTS-4 (b): PayPal's token endpoint is down, so the order was never posted: nothing was sent. The attempt is
     * kept as «unknown» (maybe taken) and holds the invoice, instead of «not sent».
     */
    public function test_s7_payments_4_a_paypal_token_failure_is_a_charge_not_sent(): void
    {
        $this->markTestIncomplete('S7-PAYMENTS-4 (low): left as is, recorded only (owner 2026-11-10; docs/sweeps/2026-11-10-seventh.md).');

        MailFacade::fake();
        $paypal = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $paypal->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $paypal->id,
            'name' => 'PayPal', 'external_reference' => 'vault-token-1', 'type' => 'paypal']);
        [$invoice] = $this->renewal('2026-11-10');
        $orders = 0;
        Http::fake(function (Request $request) use (&$orders) {
            if (str_ends_with($request->url(), '/v1/oauth2/token')) {
                return Http::response(['error' => 'server_error'], 503);
            }
            $orders += str_contains($request->url(), '/v2/checkout/orders') ? 1 : 0;

            return Http::response([], 500);
        });

        $attempt = (new SavedMethodCharge)->charge($invoice, $agreement->fresh(), ChargeAttempt::SOURCE_CRON);

        $this->assertSame(0, $orders, 'precondition: no order reached PayPal');
        $this->assertSame(ChargeAttempt::STATUS_NOT_SENT, $attempt->fresh()->status, 'nothing was sent to PayPal, yet the charge is kept as of unknown outcome');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'and it holds the invoice from every way of paying');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * PR H (decision 5, docs/payments.md «Αυτόματη χρέωση»): a saved method is charged on the due date, not when the invoice is
 * made, and a declined charge is tried again every day (or on the days set) until paid or suspended. Each charge is an
 * attempt under the payment locks with its own idempotency key: never two at once (PAY-3).
 */
class AutoChargeTest extends TestCase
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
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
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
        AcPay::$charges = [];
        AcPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
        $this->assertSame($this->agreement->id, $this->client->autoChargeAgreement()?->id, 'precondition: the first method is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due on $due. */
    private function invoice(string $due, string $status = Service::STATUS_ACTIVE, ?string $subscription = null): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status, 'subscription_id' => $subscription, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function cronOn(string $day): int
    {
        Carbon::setTestNow($day . ' 09:00:00');

        return (new AutoCharge)->run();
    }

    private function declineAll(): void
    {
        AcPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');
    }

    /** The money arrives: the gateway records it, as Stripe's webhook would. */
    private function paysWhenCharged(): void
    {
        AcPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'AcPay', $amount, null, 'ch_' . count(AcPay::$charges), currency: 'USD');

            return true;
        };
    }

    /** The «payment failed» mails queued for the customer. */
    private function failedMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    public function test_charged_on_its_due_date_not_before(): void
    {
        $invoice = $this->invoice('2026-11-17');
        $this->paysWhenCharged();

        $this->assertSame(0, $this->cronOn('2026-11-10'), 'made 7 days before: not charged yet');
        $this->assertSame([], AcPay::$charges);

        $this->assertSame(1, $this->cronOn('2026-11-17'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $attempt = ChargeAttempt::query()->sole();
        $this->assertSame([ChargeAttempt::SOURCE_CRON, ChargeAttempt::STATUS_SUCCEEDED, '10.00'], [$attempt->source, $attempt->status, (string) $attempt->amount]);
        $this->assertSame($attempt->key, AcPay::$charges[0]['key'], 'the attempt\'s key is the gateway\'s idempotency key');
    }

    public function test_days_before_the_due_date_when_set(): void
    {
        config(['settings.autocharge_days_before' => 3]);
        $this->invoice('2026-11-17');

        $this->cronOn('2026-11-13');
        $this->assertSame([], AcPay::$charges);
        $this->cronOn('2026-11-14');
        $this->assertCount(1, AcPay::$charges);
    }

    public function test_a_decline_is_tried_again_every_day_once_a_day(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->declineAll();

        $this->cronOn('2026-11-10');
        $this->cronOn('2026-11-10'); // the cron ran twice
        $this->cronOn('2026-11-11');
        $this->cronOn('2026-11-12');

        $this->assertCount(3, AcPay::$charges);
        $this->assertSame(3, (int) $this->agreement->fresh()->failed_count);
        $this->paysWhenCharged();
        $this->cronOn('2026-11-13');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, (int) $this->agreement->fresh()->failed_count, 'a success starts again');
    }

    public function test_retries_on_the_days_set(): void
    {
        config(['settings.autocharge_retry_days' => '2, 5']);
        $this->invoice('2026-11-10');
        $this->declineAll();

        foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'] as $day) {
            $this->cronOn($day);
        }

        $this->assertCount(3, AcPay::$charges, 'the due date, then days 2 and 5 after it');
    }

    public function test_the_customer_hears_of_the_first_decline_and_the_last_try_not_every_day(): void
    {
        $this->invoice('2026-11-10');
        $this->declineAll();

        foreach (range(10, 18) as $day) {
            $this->cronOn("2026-11-{$day}");
        }

        $this->assertCount(9, AcPay::$charges);
        $this->assertSame(2, $this->failedMails(),
            'the first decline (day 0) and the last try before suspension (day 8)');
        $this->assertSame(9, EmailLog::query()->where('subject', 'Automatic charges declined')->count(), 'staff: one digest a run');
    }

    public function test_stops_after_the_failures_set_and_tells_staff_once(): void
    {
        config(['settings.autocharge_max_failures' => 2]);
        $this->invoice('2026-11-10');
        $this->declineAll();

        foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $day) {
            $this->cronOn($day);
        }

        $this->assertCount(2, AcPay::$charges);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Automatic charges stopped')->count());
        $this->assertNotNull(BillingAgreement::query()->find($this->agreement->id), 'kept, not removed (WHMCS)');
    }

    public function test_the_bank_asking_to_confirm_is_not_retried(): void
    {
        $this->invoice('2026-11-10');
        AcPay::$answer = fn () => throw new ChargeNeedsAction('confirm');

        $this->cronOn('2026-11-10');
        $this->cronOn('2026-11-11');

        $this->assertCount(1, AcPay::$charges);
        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, ChargeAttempt::query()->sole()->status);
        $this->assertSame(1, $this->failedMails());
    }

    /** Until paid or suspended (decision 5); orders, staff invoices and subscriptions are not the cron's to charge. */
    public function test_only_open_renewals_of_active_services(): void
    {
        $this->invoice('2026-11-10', Service::STATUS_SUSPENDED);
        $this->invoice('2026-11-10', Service::STATUS_PENDING);
        $this->invoice('2026-11-10', subscription: 'sub_1');
        Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10'])
            ->items()->create(['description' => 'Staff line', 'price' => '5.00', 'quantity' => 1]);

        $this->cronOn('2026-11-10');

        $this->assertSame([], AcPay::$charges);
    }

    public function test_auto_charge_off_is_not_charged(): void
    {
        $this->invoice('2026-11-10');
        $this->client->update(['auto_charge' => false]);

        $this->cronOn('2026-11-10');

        $this->assertSame([], AcPay::$charges);
    }

    /** PAY-3: a charge on its way holds the invoice; a second click, another tab or the cron is refused. */
    public function test_a_charge_on_its_way_holds_the_invoice(): void
    {
        $invoice = $this->invoice('2026-11-10');
        ChargeAttempt::query()->create(['key' => 'k1', 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $this->agreement->id, 'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now()]);

        try {
            (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
            $this->fail('charged twice');
        } catch (DisplayException $e) {
            $this->assertSame(__('A payment for this invoice is already on its way.'), $e->getMessage());
        }
        $this->cronOn('2026-11-10');
        $this->assertSame([], AcPay::$charges);
    }

    /** Taken by the gateway, its payment still to come (Stripe's webhook): a second click within the hold is refused. */
    public function test_a_charge_taken_and_not_recorded_yet_holds_too(): void
    {
        $invoice = $this->invoice('2026-11-10');

        $first = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $first->status);
        $this->expectException(DisplayException::class);
        (new SavedMethodCharge)->charge($invoice->fresh(), $this->agreement, ChargeAttempt::SOURCE_CLIENT);
    }

    public function test_a_declined_click_frees_the_invoice_and_a_stale_attempt_holds_no_more(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->declineAll();
        $declined = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(ChargeAttempt::STATUS_FAILED, $declined->status);
        $this->assertSame(0, (int) $this->agreement->fresh()->failed_count, 'only the cron\'s declines count');

        ChargeAttempt::query()->create(['key' => 'k-old', 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $this->agreement->id, 'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now()->subMinutes(ChargeAttempt::HOLD_MINUTES + 1)]);
        $this->paysWhenCharged();

        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT)->status);
    }

    public function test_a_processing_payment_holds_the_invoice(): void
    {
        $invoice = $this->invoice('2026-11-10');
        ExtensionHelper::addProcessingPayment($invoice->id, 'AcPay', 10.00, null, 'sepa_1', currency: 'USD');
        $this->assertSame(InvoiceTransactionStatus::Processing, $invoice->transactions()->sole()->status);

        $this->cronOn('2026-11-10');

        $this->assertSame([], AcPay::$charges);
    }

    /** Stripe: the attempt's key goes as Idempotency-Key, and «authentication_required» is the bank asking the customer. */
    public function test_stripe_sends_the_key_and_reads_an_authentication_request(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa',
            'external_reference' => 'pm_s', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        Http::fake([
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/payment_intents' => Http::response(['error' => ['type' => 'card_error', 'code' => 'authentication_required',
                'payment_intent' => ['status' => 'requires_payment_method']]], 402),
        ]);
        $invoice = $this->invoice('2026-11-10');

        $attempt = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CLIENT);

        $this->assertSame(ChargeAttempt::STATUS_ACTION_REQUIRED, $attempt->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment_intents') && $request->header('Idempotency-Key') === [$attempt->key]);
    }
}

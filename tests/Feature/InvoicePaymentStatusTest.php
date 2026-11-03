<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Credit;
use App\Models\CronStat;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\PaymentStatus;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\PsPay\PsPay;
use Tests\TestCase;

/**
 * The invoice's status card (docs/pay-flow-ux.md, PR 2): what happens now, read from the rules the payment follows. A
 * payment the server refuses is not offered, and a charge the cron does not make is not promised.
 */
class InvoicePaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $gateway;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(PsPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\PsPay; class PsPay {
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return self::$answer === null ? true : (self::$answer)($invoice, $amount); } }');
        }
        PsPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'PsPay'], ['name' => 'PsPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due on $due. */
    private function invoice(string $due = '2026-11-12'): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** An invoice the cron never charges: staff made it (no service line). */
    private function manual(?string $due = '2026-11-12'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);

        return $invoice->fresh();
    }

    private function attempt(Invoice $invoice, string $status, string $source = ChargeAttempt::SOURCE_CLIENT): ChargeAttempt
    {
        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $this->agreement->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => $source, 'status' => $status,
            'started_at' => now(), 'sent_at' => now()]);
    }

    private function processing(Invoice $invoice): int
    {
        return DB::table('invoice_transactions')->insertGetId(['invoice_id' => $invoice->id, 'gateway_id' => $this->gateway->id, 'amount' => 10,
            'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa_1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payButton(Invoice $invoice): string
    {
        return e(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]));
    }

    public function test_a_renewal_the_cron_charges_says_when_and_with_which_method(): void
    {
        $status = PaymentStatus::for($this->invoice());
        $this->assertSame(PaymentStatus::SCHEDULED, $status->state);
        $this->assertSame(__('invoices.payment_status.scheduled.title', ['date' => '12/11/2026']), $status->title());
        $this->assertStringContainsString('Visa 4242', $status->body());
        $this->assertTrue($status->payable(), 'the customer may still pay now');
        $this->assertFalse($status->waiting());

        // Charged days before its due date: today, then.
        config(['settings.autocharge_days_before' => 3]);
        $this->assertSame(__('invoices.payment_status.scheduled_today.title'), PaymentStatus::for($this->invoice())->title());
    }

    public function test_the_next_attempt_follows_the_cron_and_agrees_with_will_charge(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->assertSame('2026-11-10', AutoCharge::nextAttempt($invoice)?->toDateString(), 'due today, not tried: today');

        PsPay::$answer = fn () => throw new DisplayException('Card declined');
        (new AutoCharge)->run();
        $status = PaymentStatus::for($invoice->fresh());
        $this->assertSame(PaymentStatus::RETRY, $status->state);
        $this->assertSame('2026-11-11', AutoCharge::nextAttempt($invoice->fresh())?->toDateString(), 'tried today: tomorrow');
        $this->assertSame(__('invoices.payment_status.retry.title'), $status->title());

        // Only the retry days set: the next one of them.
        config(['settings.autocharge_retry_days' => '3,7']);
        $this->assertSame('2026-11-13', AutoCharge::nextAttempt($invoice->fresh())?->toDateString());

        // Past its window: never charged again, and the page says it is overdue instead.
        Carbon::setTestNow('2026-11-30 09:00:00');
        $this->assertNull(AutoCharge::nextAttempt($invoice->fresh()));
        $this->assertFalse(AutoCharge::willCharge($invoice->fresh()));
        $this->assertSame(PaymentStatus::OVERDUE, PaymentStatus::for($invoice->fresh())->state);
    }

    public function test_one_the_cron_leaves_says_its_due_date(): void
    {
        $this->assertSame(PaymentStatus::DUE, PaymentStatus::for($this->manual())->state);
        $this->assertSame(PaymentStatus::OVERDUE, PaymentStatus::for($this->manual('2026-11-01'))->state);
        $this->assertSame(PaymentStatus::UNPAID, PaymentStatus::for($this->manual(null))->state);

        // Automatic payments off for the account: not promised either.
        $this->client->update(['auto_charge' => false]);
        $this->assertSame(PaymentStatus::DUE, PaymentStatus::for($this->invoice())->state);
    }

    public function test_a_held_charge_offers_no_payment_and_says_why(): void
    {
        $invoice = $this->invoice();
        $this->attempt($invoice, ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::SOURCE_CRON);

        $status = PaymentStatus::for($invoice);
        $this->assertSame(PaymentStatus::HELD, $status->state);
        $this->assertFalse($status->payable());
        $this->assertTrue($status->waiting());
        $this->assertStringContainsString('Visa 4242', $status->body());

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice]);
        $page->assertSee($status->title())->assertDontSeeHtml($this->payButton($invoice))->assertSeeHtml('wire:poll.30s="checkPaymentStatus"');
        // An email's ?pay=1 does not open the window either.
        $page->set('showPayModal', true)->assertSet('showPayModal', false);

        // Taken by the gateway, its payment not recorded yet: still held, told differently.
        $other = $this->invoice();
        $this->attempt($other, ChargeAttempt::STATUS_SUCCEEDED);
        $this->assertSame(PaymentStatus::TAKEN, PaymentStatus::for($other)->state);
        $this->assertFalse(PaymentStatus::for($other)->payable());
    }

    public function test_a_processing_payment_offers_no_payment_and_names_it(): void
    {
        $invoice = $this->manual();
        $this->processing($invoice);

        $status = PaymentStatus::for($invoice);
        $this->assertSame(PaymentStatus::PROCESSING, $status->state);
        $this->assertFalse($status->payable());
        $this->assertStringContainsString('PsPay', $status->body());

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertSee($status->title())->assertDontSeeHtml($this->payButton($invoice))->assertSeeHtml('wire:poll.30s="checkPaymentStatus"');
    }

    public function test_a_group_payment_on_its_way_holds_the_groups_other_invoices(): void
    {
        $lead = $this->manual('2026-11-11');
        $other = $this->manual('2026-11-12');
        $group = (new MassPay)->open($this->client, [$lead->id, $other->id], $this->user);
        $group->update(['invoice_transaction_id' => $this->processing($group->invoice)]);

        $this->assertSame(PaymentStatus::PROCESSING, PaymentStatus::for($group->invoice->fresh())->state);
        $theOther = $group->invoice->id === $lead->id ? $other : $lead;
        $this->assertSame(PaymentStatus::PROCESSING_GROUP, PaymentStatus::for($theOther->fresh())->state);
        $this->assertFalse(PaymentStatus::for($theOther->fresh())->payable());
    }

    public function test_the_bank_asking_for_confirmation_asks_the_customer_to_pay_with_the_card(): void
    {
        $invoice = $this->invoice();
        $this->attempt($invoice, ChargeAttempt::STATUS_ACTION_REQUIRED, ChargeAttempt::SOURCE_CRON);

        $status = PaymentStatus::for($invoice);
        $this->assertSame(PaymentStatus::ACTION, $status->state, 'the cron does not charge that method again (agreementFor)');
        $this->assertTrue($status->payable());
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])->assertSee($status->title())->assertSeeHtml($this->payButton($invoice));
    }

    public function test_back_from_a_gateway_it_checks_without_offering_a_second_payment(): void
    {
        $status = PaymentStatus::for($this->manual(), checking: true);
        $this->assertSame(PaymentStatus::CHECKING, $status->state);
        $this->assertFalse($status->payable());
    }

    public function test_a_paid_invoice_says_when_and_how(): void
    {
        $invoice = $this->manual();
        ExtensionHelper::addPayment($invoice->id, 'PsPay', 10, null, 'ch_1', currency: 'USD');

        $status = PaymentStatus::for($invoice->fresh());
        $this->assertSame(PaymentStatus::PAID, $status->state);
        $this->assertSame(__('invoices.payment_status.paid.body', ['date' => '10/11/2026', 'method' => 'PsPay']), $status->body());
        $this->assertFalse($status->payable());
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])->assertSee($status->title())->assertDontSeeHtml($this->payButton($invoice));
    }

    /** Review of #593: «today» is not promised once today's run is over and no hourly run charges; the reminders keep willCharge. */
    public function test_today_is_not_promised_once_todays_run_is_over(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->assertSame('2026-11-10', AutoCharge::nextAttempt($invoice)?->toDateString(), 'the run has not come yet');

        Setting::query()->create(['key' => 'last_cron_run', 'settingable_type' => CronStat::class, 'value' => '2026-11-10 08:00:00', 'type' => 'string']);
        $this->assertSame('2026-11-11', AutoCharge::nextAttempt($invoice)?->toDateString(), 'today\'s run is over: tomorrow');
        $this->assertTrue(AutoCharge::willCharge($invoice), 'willCharge unchanged');
        $this->assertSame(__('invoices.payment_status.scheduled.title', ['date' => '11/11/2026']), PaymentStatus::for($invoice)->title());
    }

    /** Review of #593: a charge declined before its due date is retried the next day (autocharge_days_before), as the run does. */
    public function test_a_decline_before_the_due_date_is_retried_the_next_day(): void
    {
        config(['settings.autocharge_days_before' => 3]);
        $invoice = $this->invoice('2026-11-13');
        $this->assertSame('2026-11-10', AutoCharge::nextAttempt($invoice)?->toDateString(), 'first charged 3 days before');

        PsPay::$answer = fn () => throw new DisplayException('Card declined');
        (new AutoCharge)->run();
        $this->assertSame('2026-11-11', AutoCharge::nextAttempt($invoice->fresh())?->toDateString());
    }

    public function test_paid_from_the_balance_says_so(): void
    {
        config(['settings.credits_enabled' => true]);
        Credit::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD', 'amount' => '50.00']);
        $invoice = $this->manual();
        (new RecordPaymentService)->payWithCredit($invoice);

        $status = PaymentStatus::for($invoice->fresh());
        $this->assertSame(PaymentStatus::PAID, $status->state);
        $this->assertSame(__('invoices.payment_status.paid_credit.body', ['date' => '10/11/2026']), $status->body());
    }

    /** Review of #593: an invoice of an ended service is refused every way of paying (processPayment): not offered, ask us. */
    public function test_an_invoice_of_an_ended_service_offers_no_payment(): void
    {
        $invoice = $this->invoice();
        Service::query()->whereKey($invoice->items()->value('reference_id'))->update(['status' => Service::STATUS_CANCELLED]);

        $status = PaymentStatus::for($invoice->fresh());
        $this->assertSame(PaymentStatus::ENDED, $status->state);
        $this->assertFalse($status->payable());
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee($status->body())->assertDontSeeHtml($this->payButton($invoice))->assertSee(__('invoices.payment_status.contact'));
    }

    public function test_an_emails_pay_link_does_not_open_the_window_of_a_held_invoice(): void
    {
        $invoice = $this->invoice();
        $this->attempt($invoice, ChargeAttempt::STATUS_STARTED);

        $this->livewireAs($this->user)->withQueryParams(['pay' => 1])->test(Show::class, ['invoice' => $invoice])->assertSet('showPayModal', false);
        $this->livewireAs($this->user)->withQueryParams(['pay' => 1])->test(Show::class, ['invoice' => $this->manual()])->assertSet('showPayModal', true);
    }
}

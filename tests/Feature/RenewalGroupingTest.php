<?php

namespace Tests\Feature;

use App\Events\Invoice\Finalized;
use App\Models\BillingAgreement;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\GroupFake\GroupFake;
use RuntimeException;
use Tests\TestCase;

/**
 * PR C (Δ3, sweep Π1.3): the cron bills what falls due the same day for a customer on one invoice, as WHMCS does (nixpal:
 * ~400 renewal invoices of 2-24 services a year). Not a gateway subscription's renewal, nor a customer with «Separate
 * invoices» (myip: 3). The email, credit and saved-method charge come once all its lines are on it. Its lines say the
 * service's options (as WHMCS writes them).
 */
class RenewalGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return Collection<int, Invoice> */
    private function renewalInvoices(): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class))->with('items')->orderBy('id')->get();
    }

    public function test_renewals_due_the_same_day_are_one_invoice(): void
    {
        Event::fake([Finalized::class]);
        $one = $this->service();
        $two = $this->service();

        $this->cron();

        $invoice = $this->renewalInvoices()->sole();
        $this->assertSame([$one->id, $two->id], $invoice->items->pluck('reference_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        $this->assertSame('20.00', number_format((float) $invoice->total, 2, '.', ''));
        $this->assertSame('2026-10-25', $invoice->due_at->toDateString());
        Event::assertDispatchedTimes(Finalized::class, 1); // once all its lines are on it, not when it is created (Discord, email)
        Event::assertDispatched(Finalized::class, fn (Finalized $event) => $event->sendEmail && $event->invoice->is($invoice));
    }

    public function test_paying_the_grouped_invoice_renews_every_service(): void
    {
        $one = $this->service();
        $two = $this->service();
        $this->cron();

        (new RecordPaymentService)->handle($this->renewalInvoices()->sole(), null, 20.0, transactionId: 'wire-g');

        $this->assertSame('2026-11-25', $one->fresh()->expires_at->toDateString());
        $this->assertSame('2026-11-25', $two->fresh()->expires_at->toDateString());
    }

    public function test_different_due_dates_customers_or_currencies_are_separate(): void
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26']);
        $this->service([], User::factory()->create());
        $this->service(['currency_code' => 'EUR']);

        $this->cron();

        $this->assertCount(4, $this->renewalInvoices());
    }

    public function test_a_customer_with_separate_invoices_gets_one_per_service(): void
    {
        $this->user->client()->update(['separate_invoices' => true]);
        $this->service();
        $this->service();

        $this->cron();

        $this->assertCount(2, $this->renewalInvoices());
    }

    public function test_a_gateway_subscription_renewal_is_billed_alone(): void
    {
        $this->service(['subscription_id' => 'sub_123']);
        $this->service();
        $this->service();

        $this->cron();

        $this->assertSame([1, 2], $this->renewalInvoices()->map(fn (Invoice $invoice) => $invoice->items->count())->sort()->values()->all());
    }

    public function test_credit_pays_the_grouped_invoice_once_all_its_lines_are_on_it(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $one = $this->service();
        $two = $this->service();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 2000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');

        $this->cron();

        $this->assertSame(Invoice::STATUS_PAID, $this->renewalInvoices()->sole()->status);
        $this->assertSame('0.00', number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'), 2, '.', ''));
        $this->assertSame('2026-11-25', $one->fresh()->expires_at->toDateString());
        $this->assertSame('2026-11-25', $two->fresh()->expires_at->toDateString());
    }

    public function test_staff_cancelling_the_grouped_invoice_gives_up_every_period(): void
    {
        $this->service();
        $this->service();
        $this->cron();

        $this->renewalInvoices()->sole()->update(['status' => Invoice::STATUS_CANCELLED]);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->renewalInvoices());
        $this->assertSame(2, InvoiceItem::query()->whereNotNull('forgone_at')->count());
    }

    public function test_a_service_cancelled_now_leaves_the_others_on_the_invoice(): void
    {
        $one = $this->service();
        $two = $this->service();
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();

        CancelOpenInvoices::handle([$invoice], 'cancelled', $one);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([(int) $two->id], $invoice->fresh()->items->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_line_says_the_services_options(): void
    {
        $service = $this->service();
        $option = ConfigOption::query()->create(['name' => 'Location', 'type' => 'select', 'hidden' => false]);
        $value = ConfigOption::query()->create(['name' => 'Athens', 'type' => 'select', 'parent_id' => $option->id, 'hidden' => false]);
        $hidden = ConfigOption::query()->create(['name' => 'Internal', 'type' => 'select', 'hidden' => true]);
        $hiddenValue = ConfigOption::query()->create(['name' => 'Yes', 'type' => 'select', 'parent_id' => $hidden->id, 'hidden' => false]);
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);
        $service->configs()->create(['config_option_id' => $hidden->id, 'config_value_id' => $hiddenValue->id]);

        $this->cron();

        $description = $this->renewalInvoices()->sole()->items->sole()->description;
        $this->assertStringContainsString("\nLocation: Athens", $description);
        $this->assertStringNotContainsString('Internal', $description);
    }

    private function agreement(): BillingAgreement
    {
        if (!class_exists(GroupFake::class)) {
            eval('namespace MyCS\Extensions\Gateways\GroupFake; class GroupFake { public static array $charges = []; public function __construct($config = []) {}
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        GroupFake::$charges = [];
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'GroupFake'], ['name' => 'GroupFake', 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'Card', 'external_reference' => 'ref-' . uniqid()]);
    }

    public function test_the_saved_method_is_charged_once_for_the_grouped_invoice(): void
    {
        $agreement = $this->agreement();
        $this->service(['billing_agreement_id' => $agreement->id]);
        $this->service(['billing_agreement_id' => $agreement->id]);

        $this->cron();

        $invoice = $this->renewalInvoices()->sole();
        $this->assertSame([[(int) $invoice->id, '20.00']], GroupFake::$charges);
    }

    public function test_different_saved_methods_are_separate_invoices(): void
    {
        $this->service(['billing_agreement_id' => $this->agreement()->id]);
        $this->service(['billing_agreement_id' => $this->agreement()->id]);
        $this->service();

        $this->cron();

        $this->assertCount(3, $this->renewalInvoices());
    }

    public function test_a_group_invoice_paid_meanwhile_takes_no_more_lines(): void
    {
        $this->service();
        $this->service();
        $paid = false;
        InvoiceItem::created(function (InvoiceItem $line) use (&$paid) {
            if (!$paid) {
                $paid = true;
                DB::table('invoices')->where('id', $line->invoice_id)->update(['status' => Invoice::STATUS_PAID]);
            }
        });

        $this->cron();

        $this->assertSame([1, 1], $this->renewalInvoices()->map(fn (Invoice $invoice) => $invoice->items->count())->all());
    }

    public function test_a_line_of_nothing_goes_alone_and_closes_as_paid(): void
    {
        $this->service(['price' => 0.004]);
        $this->service();

        $this->cron();

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_UNPAID], $this->renewalInvoices()->pluck('status')->all());
    }

    public function test_an_invoice_a_previous_run_left_unfinished_is_finished(): void
    {
        // A run that stopped after billing (a crash) leaves its invoice marked: the next run emails and charges it, once.
        Event::fake([Finalized::class]);
        $agreement = $this->agreement();
        $this->service(['billing_agreement_id' => $agreement->id]);
        $this->service(['billing_agreement_id' => $agreement->id]);
        $this->cron();
        $invoice = $this->renewalInvoices()->sole();
        DB::table('invoices')->where('id', $invoice->id)->update(['finish_pending_at' => now()]);
        GroupFake::$charges = [];

        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->cron();
        $this->cron();

        $this->assertCount(1, $this->renewalInvoices());
        $this->assertNull($invoice->fresh()->finish_pending_at);
        $this->assertSame([[(int) $invoice->id, '20.00']], GroupFake::$charges);
        Event::assertDispatchedTimes(Finalized::class, 2); // the first run, then the one that finished what it left
    }

    public function test_a_credit_failure_does_not_hold_back_the_email_or_the_charge(): void
    {
        Event::fake([Finalized::class]);
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $agreement = $this->agreement();
        $this->service(['billing_agreement_id' => $agreement->id]);
        $this->service(['billing_agreement_id' => $agreement->id]);
        (new CreditLedger)->add($this->user->client()->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');
        InvoiceTransaction::creating(function (InvoiceTransaction $txn) {
            if ($txn->is_credit_transaction) {
                throw new RuntimeException('credit failed');
            }
        });

        try {
            Artisan::call('app:cron-job');
            $this->fail('The credit failure is not reported');
        } catch (RuntimeException $e) {
            $this->assertSame('credit failed', $e->getMessage()); // the run reports what failed, once it has done the rest
        }

        $invoice = $this->renewalInvoices()->sole();
        $this->assertNull($invoice->fresh()->finish_pending_at);
        $this->assertSame('5.00', number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'), 2, '.', ''));
        $this->assertSame([[(int) $invoice->id, '20.00']], GroupFake::$charges);
        Event::assertDispatchedTimes(Finalized::class, 1);
    }

    public function test_options_are_in_the_products_order(): void
    {
        $service = $this->service();
        foreach ([['Second', 2, 'B'], ['First', 1, 'A']] as [$name, $sort, $valueName]) {
            $option = ConfigOption::query()->create(['name' => $name, 'type' => 'select', 'hidden' => false, 'sort' => $sort]);
            $value = ConfigOption::query()->create(['name' => $valueName, 'type' => 'select', 'parent_id' => $option->id, 'hidden' => false]);
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);
        }

        $this->assertSame("First: A\nSecond: B", $service->describeOptions());
    }
}

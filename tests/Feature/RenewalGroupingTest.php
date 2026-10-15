<?php

namespace Tests\Feature;

use App\Events\Invoice\Finalized;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
        Event::assertDispatchedTimes(Finalized::class, 2); // created (no email), then once all its lines are on it (email)
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
}

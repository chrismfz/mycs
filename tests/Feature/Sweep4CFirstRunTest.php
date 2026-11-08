<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 4, lens «c-first-run» (adversarial verifier): what the first cron run does to state as the WHMCS importer leaves it.
 * The importer writes invoices and lines with DB::table (no observer), so the tests write them the same way where it
 * matters (a cancelled invoice: no forgone_at given by InvoiceObserver).
 */
class Sweep4CFirstRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        Mail::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true,
            'settings.cronjob_order_cancel' => 0]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach (['register', 'transfer', 'renew'] as $operation) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);
        }
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

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2027-03-01',
        ]);
    }

    private function domain(array $attributes = []): Domain
    {
        static $n = 0;
        $n++;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => "fr{$n}", 'tld' => 'com', 'fqdn' => "fr{$n}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2027-03-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** An invoice as the importer writes it: the row and its lines with DB::table, no observer. */
    private function imported(string $status, string $due, array $lines): Invoice
    {
        $id = DB::table('invoices')->insertGetId(['client_id' => $this->user->client()->id, 'status' => $status, 'currency_code' => 'EUR',
            'due_at' => $due, 'created_at' => $due, 'updated_at' => $due]);
        foreach ($lines as $line) {
            DB::table('invoice_items')->insert($line + ['invoice_id' => $id, 'quantity' => 1, 'taxed' => false, 'tax_rate' => 0, 'tax_amount' => 0,
                'line_total' => $line['price'], 'kind' => null, 'period_start' => null, 'period_end' => null, 'applied_at' => null, 'forgone_at' => null,
                'created_at' => $due, 'updated_at' => $due]);
        }

        return Invoice::query()->findOrFail($id);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * C-FIRST-RUN-1. An abandoned WHMCS order (a pending service and a pending domain registration, unpaid for four
     * months, orders not cancelled by the cron by default) is paid by the customer's imported credit on the first run:
     * the service starts and the domain goes to its registrar. AutoCharge leaves an order to its customer
     * (AutoCharge::due, D8b); credit has no such rule (CreditAutoApply::eligible).
     */
    public function test_c_first_run_1_credit_does_not_pay_an_abandoned_imported_order(): void
    {
        $service = $this->service(['status' => Service::STATUS_PENDING, 'expires_at' => null]);
        $domain = $this->domain(['status' => DomainStatus::PendingRegister, 'expires_at' => null]);
        $order = $this->imported(Invoice::STATUS_UNPAID, now()->subMonths(4)->toDateString(), [
            ['description' => 'Hosting order', 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id],
            ['description' => 'Domain registration', 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER],
        ]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Imported balance');

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $order->fresh()->status, 'an abandoned order is paid by the customer, not by credit on its own');
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        Queue::assertNotPushed(FulfilDomainOrderJob::class);
    }

    /**
     * C-FIRST-RUN-2. A service's renewal WHMCS invoiced and staff cancelled (WHMCS's nextinvoicedate moved on, so WHMCS
     * never bills it again) comes as a cancelled Hosting line with no period and no forgone_at: the first run bills the
     * period again and suspends the service the same night.
     */
    public function test_c_first_run_2_a_service_renewal_cancelled_in_whmcs_is_not_billed_again_nor_suspended(): void
    {
        $service = $this->service(['expires_at' => now()->subDays(30)->toDateString()]);
        $this->imported(Invoice::STATUS_CANCELLED, now()->subDays(30)->toDateString(), [
            ['description' => 'Hosting renewal', 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id],
        ]);

        $this->cron();

        $open = Invoice::query()->where('client_id', $this->user->client()->id)->where('status', Invoice::STATUS_UNPAID)->count();
        $this->assertSame(0, $open, 'the period WHMCS gave up on is billed again');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    /**
     * C-FIRST-RUN-3. A domain renewal cancelled in WHMCS for a period starting in 10 days (inside the 15 days the cron
     * bills ahead): a cancellation made here would give it up (InvoiceObserver::givePeriodsUp, period_start < billedFrom),
     * the importer gives it up only when period_start <= today, so the first run bills it again.
     */
    public function test_c_first_run_3_a_domain_renewal_cancelled_in_whmcs_within_the_billing_window_is_not_billed_again(): void
    {
        $expiry = now()->addDays(10)->startOfDay();
        $domain = $this->domain(['expires_at' => $expiry->toDateString()]);
        $this->imported(Invoice::STATUS_CANCELLED, now()->subDays(1)->toDateString(), [
            ['description' => 'Domain renewal', 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id,
                'period_start' => $expiry->toDateString(), 'period_end' => $expiry->copy()->addYear()->toDateString()],
        ]);

        $this->cron();

        $open = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->count();
        $this->assertSame(0, $open, 'the renewal WHMCS gave up on is billed again');
    }
}

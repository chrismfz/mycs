<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LicenseIp;
use App\Models\LicenseUsagePeriod;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\BulkUsage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #823 (bulk license billing): failing tests for what the review found. Setup as
 * BulkLicenseBillingTest: tiers «1=1.80, 51=1.50», 60 IPs (105.00 a month), monthly, Europe/Athens.
 */
class BulkLicenseBillingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->storeSettings(['timezone' => 'Europe/Athens']);
        Carbon::setTestNow('2026-09-01 09:00:00');
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'TestSecret', 'bulk' => '1', 'tiers' => '1=1.80, 51=1.50'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => $key === 'bulk' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1,
            'expires_at' => '2026-10-01', 'billing_day' => 1]);
        ExtensionHelper::createServer($this->service);
        $this->ips(60, '2026-09-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** $n IPs on the list from $from, numbered from $first. */
    private function ips(int $n, string $from, int $first = 1): void
    {
        foreach (range($first, $first + $n - 1) as $i) {
            LicenseIp::query()->create(['license_id' => $this->service->fresh()->license->id, 'ip' => '10.0.' . intdiv($i, 250) . '.' . ($i % 250 + 1),
                'added_at' => CarbonImmutable::parse($from, 'Europe/Athens')]);
        }
    }

    /** The cron's renewal step on $day: bills what is due within 7 days. Returns the new invoice, if any. */
    private function bill(string $day): ?Invoice
    {
        Carbon::setTestNow($day . ' 09:00:00');
        $before = Invoice::query()->max('id');
        $billing = new RenewalBilling;
        DB::transaction(fn () => $billing->billRenewal($this->service->id, now()->addDays(7)));
        $billing->finishRenewalInvoices();

        return Invoice::query()->where('id', '>', (int) $before)->latest('id')->first();
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'pay-' . $invoice->id);
    }

    /** @return array<string, string> description => price of the invoice's lines */
    private function lines(Invoice $invoice): array
    {
        return $invoice->items()->orderBy('id')->get()->mapWithKeys(fn (InvoiceItem $line) => [$line->description => number_format((float) $line->price, 2, '.', '')])->all();
    }

    /**
     * ADV-823-1 (high): a month with no IPs on the list on invoicing day renews free (price 0 → RenewServiceService, no
     * line), so it has no paid line and BulkUsage::close never closes it. A customer who takes every IP off the day before
     * the invoice and puts them back the day after uses 60 IPs for November and is never billed for it.
     */
    public function test_adv_823_1_a_month_renewed_free_is_never_closed_so_the_ips_used_in_it_are_never_billed(): void
    {
        $this->pay($this->bill('2026-09-25'));               // October: 60 → 105.00
        Carbon::setTestNow('2026-10-24 10:00:00');
        LicenseIp::query()->update(['removed_at' => now()]); // the list emptied the day before November's invoice
        $this->assertNull($this->bill('2026-10-25'), 'November renews free');
        $this->assertSame('2026-12-01', $this->service->fresh()->expires_at->toDateString());
        $this->ips(60, '2026-10-26 10:00:00');              // and back the day after, for all of November
        $this->pay($this->bill('2026-11-25'));               // December: 60 → 105.00

        $january = $this->bill('2026-12-25');                // November is over

        $this->assertSame(1, LicenseUsagePeriod::query()->whereDate('period_start', '2026-11-01')->count(),
            'November (60 IPs for the whole month) is closed like any other period');
        $this->assertSame(['105.00', '105.00'], array_values($this->lines($january)), 'January in advance, and November\'s 60 IPs');
    }

    /**
     * ADV-823-2 (high): the tiers are a MONTHLY price per IP (product setting help, §6.0), but the advance puts cost(IPs)
     * as the service's price whatever its cycle. On a quarterly plan, three months are billed at one month's cost, and
     * close() scales «actual» to the cycle too (days/cycleDays = 1), so no correction ever makes it up.
     */
    public function test_adv_823_2_a_quarterly_bulk_license_is_billed_three_months_not_one(): void
    {
        $this->service->plan->update(['billing_period' => 3]);

        $line = $this->bill('2026-09-25')->items()->sole();

        $this->assertSame(['2026-10-01', '2027-01-01'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('315.00', number_format((float) $line->price * (int) $line->quantity, 2, '.', ''), '60 IPs for 3 months: 3 × 105.00');
    }

    /**
     * ADV-823-3 (medium): a correction up is marked billed (adjustment_item_id) as soon as it is put on a renewal invoice.
     * When that invoice is cancelled (the customer leaves, staff cancel the renewal, the service is terminated), the line
     * goes with it but the period still points at it, so owed() never returns it again: October's 30.00 of real usage is
     * gone, and nothing tells staff (the docs say an unbilled correction «stays owed in license_usage_periods»).
     */
    public function test_adv_823_3_a_correction_on_a_cancelled_invoice_is_lost(): void
    {
        $this->pay($this->bill('2026-09-25'));
        $this->ips(20, '2026-10-11', 61);
        $this->pay($this->bill('2026-10-25'));
        $december = $this->bill('2026-11-25');
        $this->assertSame(['135.00', '30.00'], array_values($this->lines($december)));

        $december->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(['30.00'], BulkUsage::owed($this->service->fresh()->license)->pluck('adjustment')->all(),
            'October\'s 30.00 was never paid: still owed');
    }

    /**
     * ADV-823-4 (medium, if the company's prices are tax-inclusive): every other renewal price is net (calculatePrice →
     * OrderPricing::line, Δ1: lines store net, the catalog's tax taken out). The tiers are a catalog price too, but the
     * advance puts them on the line as they are, so an inclusive company bills 105.00 + 24% = 130.20 for what its catalog
     * says is 105.00.
     */
    public function test_adv_823_4_tax_inclusive_tiers_are_put_on_the_line_as_net(): void
    {
        $this->storeSettings(['tax_enabled' => true, 'tax_type' => 'inclusive']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);

        $invoice = $this->bill('2026-09-25');

        $this->assertSame('105.00', number_format((float) $invoice->fresh()->total, 2, '.', ''), 'the customer pays the catalog\'s 60 IPs, tax in');
    }

    /**
     * ADV-823-5 (low): the correction of a period goes to whoever has the service when it is closed, not to the customer
     * who paid for it. Moved to another account after October was paid (its paid invoice stays with the first), the
     * credit for October's fewer IPs lands on the new account.
     */
    public function test_adv_823_5_the_credit_goes_to_the_customer_who_paid_the_period(): void
    {
        $first = $this->service->client_id;
        $this->pay($this->bill('2026-09-25')); // October: 60 → 105.00
        Carbon::setTestNow('2026-09-28 10:00:00');
        LicenseIp::query()->whereIn('id', LicenseIp::query()->orderBy('id')->limit(10)->pluck('id'))->update(['removed_at' => now()]);
        $this->pay($this->bill('2026-10-25'));
        $this->service->update(['client_id' => User::factory()->create()->client()->id]); // moved; paid invoices stay

        $this->bill('2026-11-25');

        $this->assertSame(15.0, (float) Credit::query()->where('client_id', $first)->sum('amount'), 'October\'s 15.00 back to who paid it');
    }

    /**
     * ADV-823-6 (low, design question): «billed» is the line's price, so a discount staff give on the advance line (lowered
     * on the unpaid invoice, as they may) is billed back in full as a «usage» correction when the period closes, although
     * the IPs never changed.
     */
    public function test_adv_823_6_a_staff_discount_on_the_line_is_not_billed_back_as_usage(): void
    {
        $october = $this->bill('2026-09-25');
        $october->items()->sole()->update(['price' => 94.50]); // 10% off, staff on the unpaid invoice
        $this->pay($october);
        $this->pay($this->bill('2026-10-25'));

        $december = $this->bill('2026-11-25');

        $this->assertSame(['105.00'], array_values($this->lines($december)), 'the same 60 IPs all October: no correction');
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
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
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Billing\RenewalPrice;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\BulkUsage;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A bulk license's billing (docs/zonecloud-bulk-license.md §6.0, the owner's decisions of 2026-11-12): in advance at the
 * IPs on the list, each paid period closed once over at its distinct IPs; more is billed on the next renewal invoice,
 * less is credit. The examples of §6.0 on real invoices.
 */
class BulkLicenseBillingTest extends TestCase
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

    public function test_the_advance_is_the_ips_on_the_list_at_the_tiers(): void
    {
        $invoice = $this->bill('2026-09-25');

        $line = $invoice->items()->sole();
        $this->assertSame('105.00', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame(1, (int) $line->quantity);
        $this->assertStringContainsString('60 IPs: 50 × 1.80 + 10 × 1.50', $line->description);
        $this->assertSame(['2026-10-01', '2026-11-01'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('105.00', number_format((float) RenewalPrice::for($this->service->fresh(), CarbonImmutable::parse('2026-11-01'), false)->price, 2, '.', ''),
            'the forecast prices it the same way');
    }

    public function test_example_2_twenty_more_ips_in_october_are_billed_as_eighty_on_the_invoice_after_october(): void
    {
        $this->pay($this->bill('2026-09-25'));       // October in advance: 60 → 105.00
        $this->ips(20, '2026-10-11', 61);
        $november = $this->bill('2026-10-25');       // November in advance: 80; October not over yet
        $this->assertSame(['135.00'], array_values($this->lines($november)));
        $this->pay($november);

        $december = $this->bill('2026-11-25');

        $lines = array_values($this->lines($december));
        $this->assertSame(['135.00', '30.00'], $lines, 'December in advance, and October: 80 IPs, 135.00 instead of 105.00');
        $october = LicenseUsagePeriod::query()->whereDate('period_start', '2026-10-01')->sole();
        $this->assertSame([80, '135.00', '105.00', '30.00'], [$october->ips, $october->actual, $october->billed, $october->adjustment]);
        $this->assertSame(InvoiceItem::KIND_CHARGE, InvoiceItem::query()->find($october->adjustment_item_id)->kind);
        $this->assertSame('2026-12-01', $this->service->fresh()->expires_at->toDateString(), 'paid to December, the charge renews nothing');
    }

    public function test_example_3_ips_removed_in_the_month_count_for_it_and_lower_the_next(): void
    {
        $this->ips(20, '2026-09-01', 61); // 80
        $this->pay($this->bill('2026-09-25'));
        Carbon::setTestNow('2026-10-16 10:00:00');
        LicenseIp::query()->whereIn('id', LicenseIp::query()->orderBy('id')->limit(40)->pluck('id'))->update(['removed_at' => now()]);

        $november = $this->bill('2026-10-25');
        $this->assertSame(['72.00'], array_values($this->lines($november)), 'November: the 40 left');
        $this->pay($november);
        $december = $this->bill('2026-11-25');
        $this->assertSame(['72.00'], array_values($this->lines($december)), 'October cost what was billed: no correction line');
        $this->assertSame('0.00', LicenseUsagePeriod::query()->whereDate('period_start', '2026-10-01')->sole()->adjustment);
    }

    public function test_less_than_billed_is_credit_at_once(): void
    {
        $this->pay($this->bill('2026-09-25')); // October: 60 → 105.00
        Carbon::setTestNow('2026-09-28 10:00:00'); // ten leave before October starts
        LicenseIp::query()->whereIn('id', LicenseIp::query()->orderBy('id')->limit(10)->pluck('id'))->update(['removed_at' => now()]);
        $this->pay($this->bill('2026-10-25'));

        $december = $this->bill('2026-11-25');

        $october = LicenseUsagePeriod::query()->whereDate('period_start', '2026-10-01')->sole();
        $this->assertSame([50, '90.00', '105.00', '-15.00'], [$october->ips, $october->actual, $october->billed, $october->adjustment]);
        $this->assertNull($october->adjustment_item_id);
        $this->assertSame(['90.00'], array_values($this->lines($december)), 'no negative line');
        $this->assertSame(15.0, (float) Credit::query()->where('client_id', $this->service->client_id)->sum('amount'), 'the 15.00 is credit');
    }

    public function test_a_period_is_closed_once_and_only_when_paid(): void
    {
        $october = $this->bill('2026-09-25');  // not paid
        $this->assertNull($this->bill('2026-10-25'), 'an open invoice: nothing more billed');
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->assertSame([], BulkUsage::close($this->service->fresh()), 'October over but not paid: it waits');

        $this->pay($october);
        $this->assertCount(1, BulkUsage::close($this->service->fresh()));
        $this->assertSame([], BulkUsage::close($this->service->fresh()), 'never twice');
        $this->assertSame(1, LicenseUsagePeriod::query()->count());
    }

    public function test_a_cancelled_period_is_never_closed(): void
    {
        $october = $this->bill('2026-09-25');
        $october->update(['status' => Invoice::STATUS_CANCELLED]);
        Carbon::setTestNow('2026-11-02 09:00:00');

        $this->assertSame([], BulkUsage::close($this->service->fresh()));
    }

    public function test_a_service_with_its_own_tiers_is_billed_at_them(): void
    {
        $this->service->license->update(['tiers' => '1=1.50']);

        $this->assertSame(['90.00'], array_values($this->lines($this->bill('2026-09-25'))), '60 × 1.50');
    }

    public function test_no_tiers_bills_nothing_and_tells_staff(): void
    {
        Setting::query()->where('key', 'tiers')->delete();
        Carbon::setTestNow('2026-09-25 09:00:00');
        $billing = new RenewalBilling;

        $this->assertSame(0, $billing->billRenewal($this->service->id, now()->addDays(7)));
        $this->assertStringContainsString('no tiers', $billing->notices["invoices_created #{$this->service->id}"]->getMessage());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_bad_tiers_are_refused(): void
    {
        foreach (['2=1.80', '1=1.80, 1=1.50', '1=1.805', '1=abc', '1:1.80'] as $text) {
            try {
                BulkUsage::parseTiers($text);
                $this->fail("accepted «{$text}»");
            } catch (DisplayException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([['from' => '1', 'price' => '1.80'], ['from' => '51', 'price' => '1.50']], BulkUsage::parseTiers(' 1=1.80;51=1.50 '));
    }

    public function test_an_empty_list_is_billed_at_nothing_and_its_month_still_closed(): void
    {
        $this->pay($this->bill('2026-09-25'));
        $this->ips(20, '2026-10-11', 61);
        $this->pay($this->bill('2026-10-25'));
        Carbon::setTestNow('2026-11-20 10:00:00');
        LicenseIp::query()->update(['removed_at' => now()]);

        // December: no IPs, a 0.00 line (never a free renewal, ADV-823-1), and October's 30.00 on the same invoice.
        $december = $this->bill('2026-11-25');
        $this->assertSame(['0.00', '30.00'], array_values($this->lines($december)));
        $this->pay($december);
        $this->assertSame('2027-01-01', $this->service->fresh()->expires_at->toDateString());

        // IPs back in December: billed once December is over, on February's invoice.
        $this->ips(5, '2026-12-03', 200);
        $this->pay($this->bill('2026-12-25'));
        $february = $this->bill('2027-01-25');
        $this->assertSame(['9.00', '9.00'], array_values($this->lines($february)), 'February in advance, and December\'s 5 IPs');
    }

    public function test_a_bulk_license_never_changes_cycle_or_upgrades(): void
    {
        // A cut period would be credited twice: by the change of cycle and by its close (review of #823).
        $this->assertSame(__('A bulk license is billed monthly: its cycle does not change.'),
            ChangeCycle::refusal($this->service->fresh(), $this->service->plan, ChangeCycle::NOW));
        $this->assertFalse($this->service->fresh()->upgradable);
    }

    public static function provideSeeds(): array
    {
        return ['20261112' => [20261112], '821' => [821], '5' => [5]];
    }

    #[DataProvider('provideSeeds')]
    public function test_months_of_random_changes_bill_every_closed_period_exactly_once(int $seed): void
    {
        $this->seedRandom($seed);
        $next = 1000;
        $months = ['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'];
        foreach ($months as $month) {
            // In advance (none: no IPs, renewed free).
            if (($invoice = $this->bill(CarbonImmutable::parse($month . '-01')->subMonth()->format('Y-m') . '-25')) !== null) {
                $this->pay($invoice);
            }
            // Some IPs come and go during the month, some for a day.
            foreach (range(1, $this->rand(0, 6)) as $_) {
                Carbon::setTestNow(CarbonImmutable::parse($month . '-' . sprintf('%02d', $this->rand(1, 24)) . ' ' . $this->rand(0, 23) . ':00:00', 'Europe/Athens'));
                if ($this->rand(0, 1) === 1) {
                    $this->ips($this->rand(1, 5), now()->toDateTimeString(), $next);
                    $next += 10;
                } else {
                    LicenseIp::query()->active()->where('license_id', $this->service->license->id)->inRandomOrder()->limit($this->rand(1, 4))
                        ->get()->each(fn ($row) => $row->update(['removed_at' => now()]));
                }
            }
        }
        if (($invoice = $this->bill('2027-03-25')) !== null) {
            $this->pay($invoice);
        }
        $this->bill('2027-04-25');

        $periods = LicenseUsagePeriod::query()->get();
        $this->assertGreaterThanOrEqual(5, $periods->count(), "seed {$seed}");
        foreach ($periods as $period) {
            // What it cost = billed in advance + its correction, to the cent.
            $this->assertSame(number_format((float) $period->actual, 2), number_format((float) $period->billed + (float) $period->adjustment, 2), "seed {$seed}");
            $line = $period->adjustment_item_id ? InvoiceItem::query()->find($period->adjustment_item_id) : null;
            if ((float) $period->adjustment > 0 && $line !== null) {
                $this->assertSame(number_format((float) $period->adjustment, 2), number_format((float) $line->price, 2), "seed {$seed}");
            }
            $this->assertTrue((float) $period->adjustment <= 0 || $line !== null || $period->period_start->greaterThanOrEqualTo(CarbonImmutable::parse('2027-02-01')),
                "seed {$seed}: a correction up left off the invoices");
        }
        // Every correction line is one period's, never twice.
        $charges = InvoiceItem::query()->where('kind', InvoiceItem::KIND_CHARGE)->pluck('id');
        $this->assertSame($charges->count(), $periods->whereNotNull('adjustment_item_id')->count(), "seed {$seed}");
        // Credit = every correction down, with no tax in this test's account.
        $down = $periods->filter(fn ($period) => (float) $period->adjustment < 0)->sum(fn ($period) => -(float) $period->adjustment);
        $this->assertSame(number_format($down, 2), number_format((float) Credit::query()->sum('amount'), 2), "seed {$seed}");

    }
}

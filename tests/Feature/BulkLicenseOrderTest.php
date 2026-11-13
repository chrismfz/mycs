<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LicenseUsagePeriod;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A bulk license ordered from the cart (owner 2026-11-13): its plan is priced as one IP (1.80), the customer pays that,
 * adds IPs on the service's screen, and the first period is closed at its distinct IPs like any other: the difference is
 * billed on the first renewal invoice after it ends (docs/zonecloud-bulk-license.md §6.3).
 */
class BulkLicenseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->storeSettings(['timezone' => 'Europe/Athens']);
        Carbon::setTestNow('2026-09-10 09:00:00');
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $created = $this->createProduct();
        $this->product = $created->product;
        $this->product->update(['server_id' => $server->id]);
        $created->plan->prices()->update(['price' => '1.80']);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'TestSecret', 'bulk' => '1', 'tiers' => '1=1.80, 51=1.50'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => $key === 'bulk' ? 'boolean' : 'string',
                'settingable_id' => $this->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The customer orders it and pays; the license is made, as the queued job would. */
    private function orderAndPay(): Service
    {
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())]);
        $this->pay($placed->invoice);
        $service = Service::query()->latest('id')->firstOrFail();
        ExtensionHelper::createServer($service->fresh());
        $this->assertNotNull($service->fresh()->license, 'the license is made on payment');

        return $service->fresh();
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'pay-' . $invoice->id);
    }

    /** $n IPs added now by the customer, numbered from $first. */
    private function addIps(Service $service, int $n, int $first = 1): void
    {
        $text = implode("\n", array_map(fn ($i) => '10.0.' . intdiv($i, 250) . '.' . ($i % 250 + 1), range($first, $first + $n - 1)));
        BulkLicenseIps::add($service->fresh()->license, $text, BulkLicenseIps::maxIps($service->fresh()->license));
    }

    /** The cron's renewal step on $day: bills what is due within 7 days. Returns the new invoice, if any. */
    private function bill(Service $service, string $day): ?Invoice
    {
        Carbon::setTestNow($day . ' 09:00:00');
        $before = Invoice::query()->max('id');
        $billing = new RenewalBilling;
        DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7)));
        $billing->finishRenewalInvoices();

        return Invoice::query()->where('id', '>', (int) $before)->latest('id')->first();
    }

    /** @return list<string> the invoice's line prices, in order */
    private function prices(Invoice $invoice): array
    {
        return $invoice->items()->orderBy('id')->get()->map(fn (InvoiceItem $line) => number_format((float) $line->price, 2, '.', ''))->all();
    }

    public function test_ordered_at_one_ip_then_sixty_added_the_first_month_is_billed_at_sixty_after_it_ends(): void
    {
        $service = $this->orderAndPay();
        $first = InvoiceItem::query()->where('reference_id', $service->id)->sole();
        $this->assertSame('1.80', number_format((float) $first->price, 2, '.', ''));
        $this->assertSame(['2026-09-10', '2026-10-10'], [$first->period_start->toDateString(), $first->period_end->toDateString()],
            'the payment gives the line its period: the period can be closed');
        $this->addIps($service, 60);

        $october = $this->bill($service, '2026-10-03');   // 10/10 – 10/11 in advance: 60 IPs; September not over
        $this->assertSame(['105.00'], $this->prices($october));
        $this->pay($october);

        $november = $this->bill($service, '2026-11-03');  // 10/11 – 10/12, and the first month closed

        $this->assertSame(['105.00', '103.20'], $this->prices($november), 'the first month: 60 IPs, 105.00 instead of 1.80');
        $period = LicenseUsagePeriod::query()->whereDate('period_start', '2026-09-10')->sole();
        $this->assertSame([60, '105.00', '1.80', '103.20'], [$period->ips, $period->actual, $period->billed, $period->adjustment]);
    }

    public function test_ordered_pro_rata_the_first_part_month_is_closed_at_its_days(): void
    {
        $this->product->update(['prorata_day' => 1, 'prorata_next_after_day' => null]);
        Carbon::setTestNow('2026-09-20 09:00:00');
        $service = $this->orderAndPay();
        $first = InvoiceItem::query()->where('reference_id', $service->id)->sole();
        $this->assertSame(['2026-09-20', '2026-10-01'], [$first->period_start->toDateString(), $first->period_end->toDateString()]);
        $this->assertSame('0.66', number_format((float) $first->price, 2, '.', ''), '1.80 × 11 / 30');
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-09-25'));  // October: 105.00
        $november = $this->bill($service, '2026-10-25');

        $this->assertSame(['105.00', '37.84'], $this->prices($november), '105.00 × 11 / 30 = 38.50, less 0.66 paid');
    }

    public function test_ordered_and_no_ip_ever_added_the_one_ip_paid_comes_back_as_credit(): void
    {
        $service = $this->orderAndPay();

        $october = $this->bill($service, '2026-10-03');
        $this->assertSame(['0.00'], $this->prices($october), 'an empty list: a 0.00 line, the period still closes');
        $this->bill($service, '2026-11-03');

        $period = LicenseUsagePeriod::query()->whereDate('period_start', '2026-09-10')->sole();
        $this->assertSame([0, '0.00', '1.80', '-1.80'], [$period->ips, $period->actual, $period->billed, $period->adjustment]);
        $this->assertSame(1.8, (float) Credit::query()->where('client_id', $service->client_id)->sum('amount'),
            'the 1.80 is credit (Ε5): an order with no IPs costs nothing');
    }

    /**
     * Known and cut by a rule (owner 2026-11-13): a «next after day» makes a first period longer than a cycle, and its close
     * prices it as one cycle (6 days of the IPs free). A bulk product keeps no next-after day; this pins what it would do.
     */
    public function test_a_first_period_longer_than_a_month_is_closed_as_one_month_so_bulk_keeps_no_next_after_day(): void
    {
        $this->product->update(['prorata_day' => 1, 'prorata_next_after_day' => 20]);
        Carbon::setTestNow('2026-09-25 09:00:00');
        $service = $this->orderAndPay();
        $first = InvoiceItem::query()->where('reference_id', $service->id)->sole();
        $this->assertSame(['2026-09-25', '2026-11-01', '2.16'], [$first->period_start->toDateString(), $first->period_end->toDateString(),
            number_format((float) $first->price, 2, '.', '')]);
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-10-25'));
        $december = $this->bill($service, '2026-11-25');

        $this->assertSame(['105.00', '102.84'], $this->prices($december), '37 days closed at one month: 105.00, not 129.50');
    }
}

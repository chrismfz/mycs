<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LicenseUsagePeriod;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
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
 * Adversarial review of #832 (a bulk license ordered from the cart at one IP): ways the cart-ordered license bills wrong.
 * Each test fails while its defect stands; ADV-832-G* are passing guards.
 */
class BulkLicenseOrderAdversarialTest extends TestCase
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

    private function money(Invoice $invoice): string
    {
        return number_format((float) $invoice->fresh()->total, 2, '.', '');
    }

    /**
     * ADV-832-1 (medium): a setup fee is counted as usage paid. The order line's price is 1.80 + the setup fee (the fee only
     * noted in `setup_fee`), and BulkUsage::lineNet takes price × quantity as «billed». So the first month's correction is
     * cut by the fee: 60 IPs cost 105.00, the customer paid 1.80 for the month and 10.00 for setup, and is charged 93.20
     * instead of 103.20: the setup fee is given back.
     */
    public function test_adv_832_1_a_setup_fee_is_not_counted_as_the_first_month_paid(): void
    {
        $this->product->plans()->first()->prices()->update(['setup_fee' => '10.00']);
        $service = $this->orderAndPay();
        $this->assertSame('11.80', number_format((float) InvoiceItem::query()->where('reference_id', $service->id)->sole()->price, 2, '.', ''));
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-10-03'));
        $november = $this->bill($service, '2026-11-03');

        $this->assertSame(['105.00', '103.20'], $this->prices($november), 'the first month 105.00 less the 1.80 paid for it; the setup fee is not usage');
    }

    /**
     * ADV-832-2 (medium): the same with no IP ever added: the order's 1.80 comes back as credit (as the PR's test wants),
     * but the setup fee comes back with it: 11.80 of credit for an order whose setup fee is never to be given back
     * (PlaceOrderService: «never given back as unused days»).
     */
    public function test_adv_832_2_with_no_ip_the_setup_fee_is_not_given_back_as_credit(): void
    {
        $this->product->plans()->first()->prices()->update(['setup_fee' => '10.00']);
        $service = $this->orderAndPay();

        $this->bill($service, '2026-10-03');
        $this->bill($service, '2026-11-03');

        $this->assertSame(1.8, (float) Credit::query()->where('client_id', $service->client_id)->sum('amount'),
            'only the 1.80 paid for the month comes back, not the 10.00 setup fee');
    }

    /**
     * ADV-832-3 (medium): a first-month coupon that makes the order free (100%, or a fixed 1.80 off, or a staff price of
     * 0.00 on the OrderLine) gives the first month free at ANY number of IPs. Nothing is due, so no invoice and no line:
     * the service is started by RenewServiceService without a line, and BulkUsage::close only closes periods that have a
     * paid line. The customer adds 60 IPs and uses them for a month for nothing (105.00 lost; the coupon was worth 1.80).
     * Correct: the first period is closed like any other at its IPs, against the 0.00 paid (or, if the coupon is meant to
     * be worth one IP, 105.00 − 1.80): e.g. the free start gets a 0.00 paid line with its period, as the renewal does for
     * bulk (ADV-823-1); or a rule: no coupon / no free order on a bulk product.
     */
    public function test_adv_832_3_a_free_first_month_by_coupon_is_still_closed_at_its_ips(): void
    {
        $coupon = Coupon::create(['code' => 'FREE1', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 100, 'recurring' => null]);
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())], $coupon);
        $this->assertNull($placed->invoice, 'free: no invoice');
        $service = Service::query()->latest('id')->firstOrFail();
        ExtensionHelper::createServer($service->fresh());
        $this->assertNotNull($service->fresh()->license);
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-10-03'));   // October in advance, 60 IPs
        $this->bill($service, '2026-11-03');

        $this->assertTrue(LicenseUsagePeriod::query()->whereDate('period_start', '2026-09-10')->exists(),
            'September (60 IPs, 105.00) was used: it is closed and billed, not free');
    }

    /** ADV-832-4 (medium): the same through a staff price of 0.00 on the OrderLine («Create Order»). */
    public function test_adv_832_4_a_staff_price_of_zero_still_closes_the_first_month(): void
    {
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '0.00')]);
        $this->assertNull($placed->invoice);
        $service = Service::query()->latest('id')->firstOrFail();
        ExtensionHelper::createServer($service->fresh());
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-10-03'));
        $this->bill($service, '2026-11-03');

        $this->assertTrue(LicenseUsagePeriod::query()->whereDate('period_start', '2026-09-10')->exists(),
            'September at 60 IPs is closed like any other period');
    }

    /** ADV-832-G1 (passing guard): tax-inclusive prices, no IP: the 1.80 paid comes back whole, tax in. */
    public function test_adv_832_g1_tax_inclusive_order_with_no_ip_credits_what_was_paid(): void
    {
        $this->storeSettings(['tax_enabled' => true, 'tax_type' => 'inclusive']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
        $this->user->client()->primaryContact->update(['country' => 'GR']);
        $service = $this->orderAndPay();
        $this->assertSame('1.80', $this->money(Invoice::query()->oldest('id')->firstOrFail()));

        $this->bill($service, '2026-10-03');
        $this->bill($service, '2026-11-03');

        $this->assertSame(1.8, (float) Credit::query()->where('client_id', $service->client_id)->sum('amount'));
    }

    /** ADV-832-G2 (passing guard): a 50% first-month coupon: the month is still 105.00 at 60 IPs in all (0.90 + 104.10). */
    public function test_adv_832_g2_a_half_coupon_first_month_is_closed_against_what_was_paid(): void
    {
        $coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => null]);
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())], $coupon);
        $this->pay($placed->invoice);
        $service = Service::query()->latest('id')->firstOrFail();
        ExtensionHelper::createServer($service->fresh());
        $this->addIps($service, 60);

        $this->pay($this->bill($service, '2026-10-03'));
        $november = $this->bill($service, '2026-11-03');

        $this->assertSame(['105.00', '104.10'], $this->prices($november));
    }

    /** ADV-832-G3 (passing guard): pro rata, paid late, after its part-month is over: nothing billed twice, nothing lost. */
    public function test_adv_832_g3_a_pro_rata_order_paid_after_its_part_month_is_over(): void
    {
        $this->product->update(['prorata_day' => 1, 'prorata_next_after_day' => null]);
        Carbon::setTestNow('2026-09-20 09:00:00');
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())]);
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->pay($placed->invoice);
        $service = Service::query()->latest('id')->firstOrFail();
        ExtensionHelper::createServer($service->fresh());
        $this->addIps($service, 60);

        $october = $this->bill($service, '2026-10-05');
        $this->assertNotNull($october);
        $this->assertSame(['105.00'], $this->prices($october));
        $this->pay($october);
        $november = $this->bill($service, '2026-10-26');

        $this->assertSame(['105.00'], $this->prices($november), 'September (no IPs) credits 0.66; nothing owed');
        $this->assertSame(0.66, (float) Credit::query()->where('client_id', $service->client_id)->sum('amount'));
    }
}

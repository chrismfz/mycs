<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Pricing\DifferenceDiscount;
use Tests\TestCase;

/**
 * Adversarial review of #685 (staff discount on an upgrade's difference). Same fixture as UpgradeDiscountTest: 16 of 31
 * days left, Business10 (10.00) → Business20 (30.00) is 10.32 net, 12.80 with 24% tax; 10% off is −1.03 net / −1.28.
 */
class Adv685Test extends TestCase
{
    use RefreshDatabase;

    private Product $small;

    private Product $large;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        Mail::fake();
        Queue::fake();
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
        $this->storeSettings(['mail_disable' => false, 'credits_enabled' => true, 'credits_on_downgrade' => false, 'credits_auto_use' => false,
            'tax_enabled' => true, 'tax_type' => 'exclusive', 'eu_vat_enabled' => false]);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $this->small = $this->product('Business10', 10.00);
        $this->large = $this->product('Business20', 30.00);
        DB::table('product_upgrades')->insert([['product_id' => $this->small->id, 'upgrade_id' => $this->large->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $price): Product
    {
        $product = Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function service(Product $product, float $price): Service
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);

        return Service::factory()->create(['client_id' => $user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-07-31', 'domain' => 'shop.example.gr']);
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Adv' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function totalOf(Invoice $invoice): string
    {
        return number_format($invoice->items()->get()->sum(fn (InvoiceItem $line) => $line->totalCents()) / 100, 2, '.', '');
    }

    private function discounted(Service $service, string $percent = '10'): ServiceUpgrade
    {
        $placed = PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_STAFF, null, DifferenceDiscount::PERCENT, $percent);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);

        return $placed->upgrade->fresh();
    }

    /**
     * ADV-685-1. The upgrade's invoice has a third line (staff added a line, or merged it with another open invoice).
     * Staff split it, moving that line and the discount line to a new invoice: both parts bill more than nothing, so
     * SplitInvoice allows it, and InvoiceLineMove points the upgrade's invoice_id at the part that has only the discount.
     * The customer pays the part with the upgrade's charge: the upgrade is applied, and ServiceUpgradeService::shortfall
     * reads the charge lines on upgrade->invoice_id, finds none, and bills the whole difference again.
     * Expected: the discount stays with its charge (the split refused), or nothing more is billed.
     */
    public function test_adv_685_1_a_split_that_takes_the_discount_away_from_its_charge_bills_the_upgrade_twice(): void
    {
        $service = $this->service($this->small, 10.00);
        $upgrade = $this->discounted($service);
        $invoice = $upgrade->invoice;
        $invoice->items()->create(['description' => 'Setup work', 'price' => 5, 'quantity' => 1]);
        $discountLine = $invoice->items()->where('kind', InvoiceItem::KIND_DISCOUNT)->sole();
        $setupLine = $invoice->items()->where('description', 'Setup work')->sole();

        try {
            (new SplitInvoice)->handle($invoice->id, [$setupLine->id, $discountLine->id]);
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused: the discount stays with its charge');

            return;
        }

        (new RecordPaymentService)->handle($invoice->fresh(), null, $this->totalOf($invoice->fresh()), transactionId: 'adv-685-1');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $billed = Invoice::query()->whereKeyNot($invoice->id)->whereNull('split_from_id')->get()
            ->map(fn (Invoice $more) => $more->items()->pluck('description')->implode(', ') . ' ' . $this->totalOf($more))->all();
        $this->assertSame([], $billed, 'no «shortfall» invoice for a difference already paid');
        $this->assertSame((int) $invoice->id, (int) $upgrade->fresh()->invoice_id,
            'the upgrade follows its charge line, not the discount line');
    }

    /**
     * ADV-685-2. The cron no longer reprices a discounted upgrade's line (CronJob::refreshUpgrade returns when
     * `discount` is set). An undiscounted upgrade's line falls every day with the days left; a 10% one stays at the
     * difference of the day it was placed. Ten days later the customer given 10% off owes more than the customer given
     * nothing: 11.52 against 4.80 for the same 6 days left. Expected: a discount never makes it cost more than no discount
     * (a percentage follows the repriced difference; at the very least the line is never above the undiscounted one).
     */
    public function test_adv_685_2_ten_days_later_a_ten_percent_discount_costs_more_than_no_discount(): void
    {
        $plain = $this->service($this->small, 10.00);
        $placed = PlaceUpgrade::handle($plain, $this->large, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $plainInvoice = $placed->invoice;
        $discountedInvoice = $this->discounted($this->service($this->small, 10.00))->invoice;
        $this->assertSame(['12.80', '11.52'], [$this->totalOf($plainInvoice), $this->totalOf($discountedInvoice)]);

        Carbon::setTestNow('2026-07-25 03:00:00');
        $this->artisan('app:cron-job');

        $plainTotal = $this->totalOf($plainInvoice->fresh());
        $discountedTotal = $this->totalOf($discountedInvoice->fresh());
        $this->assertLessThan(1280, (int) round((float) $plainTotal * 100), 'the cron repriced the undiscounted line (fixture check)');
        $this->assertLessThanOrEqual((float) $plainTotal, (float) $discountedTotal,
            "10% off costs {$discountedTotal}, no discount {$plainTotal}");
    }

    /**
     * ADV-685-3. Staff remove the discount line from the open invoice afterwards (an invoice edit). The upgrade still says
     * `discount` 1.03 / 10%: «Package changes» shows a discount the invoice does not give, and the cron still leaves the
     * line alone as «staff priced it by hand», so the full difference of the day it was placed stays billed.
     * Expected: the record follows the invoice (no discount line, no `discount`), and the line is repriced as any.
     */
    public function test_adv_685_3_a_discount_line_removed_by_staff_leaves_the_upgrade_marked_discounted_and_frozen(): void
    {
        $upgrade = $this->discounted($this->service($this->small, 10.00));
        $upgrade->invoice->items()->where('kind', InvoiceItem::KIND_DISCOUNT)->sole()->delete();

        Carbon::setTestNow('2026-07-25 03:00:00');
        $this->artisan('app:cron-job');

        $charge = $upgrade->invoice->fresh()->items()->sole();
        $this->assertNull($upgrade->fresh()->discount, 'no discount line, no discount on record');
        $this->assertLessThan(10.32, (float) $charge->price, 'repriced as an upgrade with no discount');
    }

    /**
     * Probe (passes, kept): staff pick «Waive it» for an upgrade, then change the product to a downgrade, where the
     * discount fields are hidden. The hidden «waive» is not submitted: the downgrade is applied, not refused as
     * «nothing_due».
     */
    public function test_adv_685_4_probe_a_hidden_discount_does_not_refuse_a_downgrade(): void
    {
        $service = $this->service($this->large, 30.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->small->id, 'discount_mode' => DifferenceDiscount::WAIVE])
            ->assertHasNoActionErrors();

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, ServiceUpgrade::query()->sole()->status);
        $this->assertNull(ServiceUpgrade::query()->sole()->discount);
    }

    /**
     * Probe (passes, kept): a service of quantity 3, 12.5% off: the invoice totals what was priced to the cent (no
     * LogicException), the discount at the line's own tax.
     */
    public function test_adv_685_5_probe_quantity_three_and_a_fractional_percent(): void
    {
        $service = $this->service($this->small, 10.00);
        $service->update(['quantity' => 3]);

        $upgrade = $this->discounted($service->fresh(), '12.5');

        $lines = $upgrade->invoice->items()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $full = (float) $lines[0]->price;
        $this->assertSame(number_format(round($full * 0.125 + 1e-9, 2), 2, '.', ''), number_format(-(float) $lines[1]->price, 2, '.', ''));
        $this->assertSame((string) $lines[0]->tax_rate, (string) $lines[1]->tax_rate);
    }
}

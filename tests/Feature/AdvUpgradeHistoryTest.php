<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\ServiceUpgrade\UpgradeQuote;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of fcbb8841 (service upgrade history, staff «Upgrade/Downgrade», UpgradeQuote, PlaceUpgrade).
 * One test per finding (ADV-UPG-n); each fails on fcbb8841 for the reason in its docblock.
 */
class AdvUpgradeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Product $small;

    private Product $large;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 10:00:00');
        Mail::fake();
        Queue::fake();
        DB::table('notification_templates')->delete();
        (new EmailTemplateSeeder)->run();
        $this->storeSettings(['mail_disable' => false, 'credits_enabled' => true, 'credits_on_downgrade' => false, 'credits_auto_use' => false]);
        $this->small = $this->product('Business10', 10.00);
        $this->large = $this->product('Business20', 30.00);
        DB::table('product_upgrades')->insert([['product_id' => $this->small->id, 'upgrade_id' => $this->large->id],
            ['product_id' => $this->large->id, 'upgrade_id' => $this->small->id]]);
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

    private function service(Product $product, float $price, string $expires = '2026-07-31'): Service
    {
        $this->user = User::factory()->create();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires, 'domain' => 'shop.example.gr']);
    }

    private function paidPeriod(Service $service, string $start, string $end, float $price): void
    {
        $invoice = Invoice::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD',
            'status' => Invoice::STATUS_UNPAID, 'due_at' => $start]);
        $invoice->items()->create(['description' => 'Period', 'price' => $price, 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Upg' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function quote(Service $service, Product $to): UpgradeQuote
    {
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $to->id]);
        $upgrade->setRelation('service', $service);
        $upgrade->setRelation('product', $to);
        $upgrade->setRelation('configs', collect());

        return UpgradeQuote::for($upgrade);
    }

    /**
     * ADV-UPG-1: staff's «Upgrade/Downgrade» places the change with no option values ($chosen = []), so a target product
     * with an upgradable (required) option is charged and renewed without it, below what the customer's page charges for
     * the same change (the customer must pick a value: the first one by default). «Same flow and same numbers as the
     * customer's page» (decisions.md SVC-14). On fcbb8841: customer 12.90 (30 + 5 for the 10GB disk, 16/31), staff 10.32,
     * and the service ends on Business20 with no Disk option at all.
     */
    public function test_staff_upgrade_charges_the_option_the_customer_page_charges(): void
    {
        $disk = ConfigOption::create(['name' => 'Disk', 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $disk->products()->attach([$this->large->id]);
        foreach ([['10GB', 5], ['20GB', 10]] as $sort => [$name, $price]) {
            $value = ConfigOption::create(['name' => $name, 'sort' => $sort, 'parent_id' => $disk->id]);
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $name,
                'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }

        $byCustomer = $this->service($this->small, 10.00);
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $byCustomer])
            ->set('upgrade', $this->large->id)->call('nextStep')->call('doUpgrade');
        $customerLine = number_format((float) ServiceUpgrade::query()->where('service_id', $byCustomer->id)->sole()
            ->invoice->items()->sole()->price, 2, '.', '');
        $this->assertSame('12.90', $customerLine, 'the customer page: (30 + 5 − 10) × 16 / 31');

        $byStaff = $this->service($this->small, 10.00);
        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $byStaff->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->large->id]);
        $staffUpgrade = ServiceUpgrade::query()->where('service_id', $byStaff->id)->first();
        if ($staffUpgrade === null) {
            return; // refused (asks for the options or sends staff to set them first): also fine
        }

        $this->assertSame($customerLine, number_format((float) $staffUpgrade->invoice?->items()->sole()->price, 2, '.', ''),
            'staff charge the same change as the customer page would, its option included');
        $this->assertSame(1, $staffUpgrade->configs()->count(), 'the new product\'s upgradable option is set, not dropped');
    }

    /**
     * ADV-UPG-2: a service whose customer asked for its cancellation is not upgradable on the customer's page (CR-10: an
     * upgrade of a service about to end would only be refunded), but `ChangePackage::refusal()` does not check it: staff
     * get «Upgrade/Downgrade» and an upgrade invoice is made for it. On fcbb8841: 1 upgrade, 1 invoice.
     */
    public function test_staff_cannot_change_the_package_of_a_service_asked_to_be_cancelled(): void
    {
        $service = $this->service($this->small, 10.00);
        $service->cancellation()->create(['reason' => 'Moving', 'type' => 'end_of_period']);
        $this->assertFalse($service->fresh()->upgradable, 'the customer page refuses it');

        // Shown disabled with why (owner 2026-11-10); asked for directly, it does not open.
        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionDisabled('changePackage')
            ->mountAction('changePackage')->assertActionNotMounted();

        $this->assertSame(0, ServiceUpgrade::query()->count(), 'no change placed for a service about to end');
        $this->assertSame(0, Invoice::query()->count(), 'no upgrade invoice for a service about to end');
    }

    /**
     * ADV-UPG-3: a renewal paid ahead (expiry 31/08, today 15/07): the upgrade is charged for all 47 days to the expiry
     * (BillingPeriod::remaining, decisions.md: «Ανανέωση πληρωμένη νωρίτερα: όλες οι μέρες ως τη λήξη»), 30.32, but the
     * quote caps the days left at the last period's length and shows «31 / 31», which explains 20.00. The staff modal and
     * the customer page («for the 31 days left of the current cycle») show days that are not what is charged.
     */
    public function test_the_days_shown_are_the_days_charged_when_a_renewal_was_paid_ahead(): void
    {
        $service = $this->service($this->small, 10.00, '2026-08-31');
        $this->paidPeriod($service, '2026-06-30', '2026-07-31', 10.00);
        $this->paidPeriod($service, '2026-07-31', '2026-08-31', 10.00);

        $quote = $this->quote($service->fresh(), $this->large);

        $this->assertSame('30.32', $quote->dueNow, '(30 − 10) × 47 / 31: every day to the expiry');
        $this->assertSame($quote->dueNow, number_format(round(20 * $quote->daysLeft / $quote->daysInPeriod, 2), 2, '.', ''),
            "the days shown ({$quote->daysLeft} / {$quote->daysInPeriod}) are the days charged");
    }

    /**
     * ADV-UPG-4: an upgrade of a service whose period ended (expiry 10/07, today 15/07, still active) costs 0.00 now and
     * PlaceUpgrade applies it at once with no invoice (its renewal bills the new price); staff's summary says before
     * «Create order» that it is «an invoice, applied once paid». On fcbb8841 the modal shows that text, then the service is
     * on Business20 at once with no invoice.
     */
    public function test_staff_summary_does_not_promise_an_invoice_for_a_change_applied_now(): void
    {
        $service = $this->service($this->small, 10.00, '2026-07-10');

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id])
            ->assertMountedActionModalDontSee('an invoice, applied once paid')
            ->callMountedAction();

        $this->assertSame($this->large->id, (int) $service->fresh()->product_id, 'applied now');
        $this->assertSame(0, Invoice::query()->count(), 'with no invoice');
    }
}

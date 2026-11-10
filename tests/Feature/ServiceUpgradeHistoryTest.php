<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\ChangePackage;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\UpgradesRelationManager;
use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use App\Services\ServiceUpgrade\UpgradeQuote;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A change of package as WHMCS records and prices it (owner 2026-11-04, service #14 on mycs.myip.gr): kept on the service
 * (before and after, who, when) and mailed to the customer once applied; staff's «Upgrade/Downgrade» shows the days left of
 * the cycle and what is due before it makes anything; an upgrade is charged now for the days left; a downgrade is 0.00
 * now, its new price from the next renewal, with no credit unless «Credit on downgrade» is on.
 */
class ServiceUpgradeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Product $small;

    private Product $large;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // 16 of the 31 days of a monthly period (2026-06-30 – 2026-07-31) left, as the owner's WHMCS example.
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

    private function service(Product $product, float $price): Service
    {
        $this->user = User::factory()->create();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-07-31', 'domain' => 'shop.example.gr']);
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

    // ------------------------------------------------------------------ the quote

    public function test_the_quote_counts_the_days_left_and_charges_them(): void
    {
        $quote = $this->quote($this->service($this->small, 10.00), $this->large);

        $this->assertSame(16, $quote->daysLeft);
        $this->assertSame(31, $quote->daysInPeriod);
        $this->assertFalse($quote->downgrade);
        $this->assertSame('10.32', $quote->dueNow, '(30 − 10) × 16 / 31');
        $this->assertSame('0.00', $quote->credit);
        $this->assertSame('10.00', $quote->renewsNow);
        $this->assertSame('30.00', $quote->renewsAfter);
    }

    public function test_a_downgrade_is_due_nothing_and_gives_no_credit_by_default_here(): void
    {
        $quote = $this->quote($this->service($this->large, 30.00), $this->small);

        $this->assertTrue($quote->downgrade);
        $this->assertSame('0.00', $quote->dueNow);
        $this->assertSame('0.00', $quote->credit, 'Credit on downgrade is off: as WHMCS, no credit and no days given');
    }

    public function test_a_downgrade_gives_credit_only_with_credit_on_downgrade(): void
    {
        $this->storeSettings(['credits_on_downgrade' => true]);

        $quote = $this->quote($this->service($this->large, 30.00), $this->small);

        $this->assertSame('0.00', $quote->dueNow);
        $this->assertGreaterThan(0, (float) $quote->credit);
    }

    // ------------------------------------------------------------------ the customer

    public function test_the_customer_page_shows_the_days_left_and_what_is_due(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->large->id)
            ->assertSee('16 / 31')
            ->assertSee('$10.32')
            ->assertSee('for the 16 days left of the current cycle', false);
    }

    public function test_the_customer_page_shows_nothing_due_for_a_downgrade(): void
    {
        $service = $this->service($this->large, 30.00);

        $component = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->small->id);

        $this->assertSame(0.0, (float) $component->instance()->totalToday()->price, 'never a negative «Total today»');
        $component->assertSee('31/07/2026')->assertDontSee('data-credit', false);
    }

    public function test_an_upgrade_paid_is_kept_on_the_service_and_mailed(): void
    {
        $service = $this->service($this->small, 10.00);
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->large->id)->call('doUpgrade');
        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame('10.32', number_format((float) $upgrade->invoice->items()->sole()->price, 2, '.', ''));
        $this->assertSame(ServiceUpgrade::SOURCE_CLIENT, $upgrade->source);
        $this->assertNull($upgrade->applied_at, 'applied once paid');

        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $upgrade->refresh();
        $this->assertSame($this->small->id, $upgrade->from_product_id);
        $this->assertSame($this->small->plans()->first()->id, $upgrade->from_plan_id);
        $this->assertSame('10.00', $upgrade->from_price);
        $this->assertSame('30.00', $upgrade->to_price);
        $this->assertNotNull($upgrade->applied_at);
        $mail = EmailLog::query()->where('subject', 'like', 'Service changed:%')->sole();
        $this->assertStringContainsString('from <strong>Business10</strong> to <strong>Business20</strong>', (string) $mail->body);
    }

    public function test_a_downgrade_is_applied_now_at_nothing_kept_and_mailed(): void
    {
        $service = $this->service($this->large, 30.00);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->small->id)->call('doUpgrade');

        $service->refresh();
        $this->assertSame($this->small->id, (int) $service->product_id);
        $this->assertSame('10.00', number_format((float) $service->price, 2, '.', ''), 'the new price, billed from the next renewal');
        $this->assertSame('2026-07-31', $service->expires_at->toDateString(), 'no days given away');
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Credit::query()->count(), 'no credit');
        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->status);
        $this->assertSame($this->large->id, $upgrade->from_product_id);
        $this->assertSame('30.00', $upgrade->from_price);
        $this->assertSame('10.00', $upgrade->to_price);
        $this->assertNull($upgrade->credit_cents);
        $this->assertSame(1, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
    }

    public function test_the_credit_given_is_the_one_the_page_showed(): void
    {
        $this->storeSettings(['credits_on_downgrade' => true]);
        $service = $this->service($this->large, 30.00);
        $shown = $this->quote($service, $this->small)->credit;

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $this->small->id)->call('doUpgrade');

        $this->assertSame((int) round((float) $shown * 100), ServiceUpgrade::query()->sole()->credit_cents);
        $this->assertSame((int) round((float) $shown * 100), (int) round((float) Credit::query()->sole()->amount * 100));
    }

    // ------------------------------------------------------------------ staff

    public function test_staff_see_the_days_left_and_the_amount_before_the_order(): void
    {
        $service = $this->service($this->small, 10.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id])
            ->assertMountedActionModalSee(['Days left on current cycle: 16 / 31', 'Upgrade total due: 10.32 USD (with tax',
                'Renews at: 30.00 USD per period with tax (now 10.00)']);

        $this->assertSame(0, ServiceUpgrade::query()->count(), 'nothing made before «Create order»');
    }

    public function test_staff_upgrade_makes_the_invoice_the_customer_would_get(): void
    {
        $service = $this->service($this->small, 10.00);
        $staff = $this->admin();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $this->large->id])
            ->assertHasNoActionErrors();

        $upgrade = ServiceUpgrade::query()->sole();
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->status);
        $this->assertSame(ServiceUpgrade::SOURCE_STAFF, $upgrade->source);
        $this->assertSame($staff->id, $upgrade->staff_id);
        $this->assertSame('10.32', number_format((float) $upgrade->invoice->items()->sole()->price, 2, '.', ''));
        $this->assertSame($this->small->id, (int) $service->fresh()->product_id, 'applied once paid');
    }

    public function test_staff_downgrade_is_applied_now_at_nothing(): void
    {
        $service = $this->service($this->large, 30.00);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->small->id])
            ->assertMountedActionModalSee(['Downgrade total due: 0.00 USD', 'No credit; the new price applies from the next renewal on 31/07/2026.'])
            ->callMountedAction()
            ->assertNotified('Package changed');

        $this->assertSame($this->small->id, (int) $service->fresh()->product_id);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Credit::query()->count());
        $this->assertSame(1, EmailLog::query()->where('subject', 'like', 'Service changed:%')->count());
    }

    public function test_staff_cannot_place_a_second_change_while_one_waits_for_payment(): void
    {
        $service = $this->service($this->small, 10.00);
        PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_CLIENT);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionVisible('changePackage')->assertActionDisabled('changePackage'); // shown with why (owner 2026-11-10)

        $this->assertSame(__('services.change_package.refused.pending_change'), ChangePackage::refusal($service->fresh()));
        $this->assertSame(PlaceUpgrade::NOT_UPGRADABLE, PlaceUpgrade::handle($service, $this->large, [], ServiceUpgrade::SOURCE_STAFF)->outcome);
        $this->assertSame(1, ServiceUpgrade::query()->count());
    }

    public function test_staff_see_the_changes_on_the_service_page(): void
    {
        $service = $this->service($this->large, 30.00);
        $done = PlaceUpgrade::handle($service, $this->small, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::APPLIED, $done->outcome);
        $this->actingAs($this->admin(), 'staff');

        Livewire::test(UpgradesRelationManager::class, ['ownerRecord' => $service->fresh(), 'pageClass' => EditService::class])
            ->assertCanSeeTableRecords([$done->upgrade])
            ->assertSee('Business20 → Business10')
            ->assertSee('30.00 → 10.00');
    }

    // ------------------------------------------------------------------ code review of #638

    /** An upgradable select on the large product, its first value «10 GB» at 4.00 a month. */
    private function diskOption(): array
    {
        $option = ConfigOption::query()->create(['name' => 'Disk', 'env_variable' => 'disk', 'type' => 'select', 'upgradable' => true]);
        $first = ConfigOption::query()->create(['name' => '10 GB', 'env_variable' => '10', 'type' => 'select', 'parent_id' => $option->id]);
        ConfigOption::query()->create(['name' => '20 GB', 'env_variable' => '20', 'type' => 'select', 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $first->id, 'priceable_type' => ConfigOption::class, 'name' => '10 GB',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 4.00, 'currency_code' => 'USD']);
        DB::table('config_option_products')->insert(['config_option_id' => $option->id, 'product_id' => $this->large->id]);

        return [$option, $first];
    }

    /** CR-1: staff charge the option values the customer's page would, not none. */
    public function test_staff_charge_the_options_the_customer_would(): void
    {
        [$option, $first] = $this->diskOption();
        $customer = $this->service($this->small, 10.00);
        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $customer])
            ->set('upgrade', $this->large->id)->call('nextStep')->call('doUpgrade');
        $customerCharge = ServiceUpgrade::query()->sole()->invoice->items()->sole()->price;

        $service = $this->service($this->small, 10.00);
        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id])
            ->assertMountedActionModalSee(['Disk: 10 GB', 'Upgrade total due: 12.39 USD'])
            ->callMountedAction();

        $staff = ServiceUpgrade::query()->where('service_id', $service->id)->sole();
        $this->assertSame('12.39', number_format((float) $customerCharge, 2, '.', ''), '(34 − 10) × 16 / 31');
        $this->assertSame(number_format((float) $customerCharge, 2, '.', ''), number_format((float) $staff->invoice->items()->sole()->price, 2, '.', ''));
        $this->assertSame([$option->id => $first->id], $staff->configs->pluck('config_value_id', 'config_option_id')->all());
    }

    /** CR-2: not for a service whose cancellation is asked for (CR-10, as the customer's page). */
    public function test_staff_cannot_change_a_service_about_to_end(): void
    {
        $service = $this->service($this->small, 10.00);
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'leaving']);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionVisible('changePackage')->assertActionDisabled('changePackage'); // shown with why (owner 2026-11-10)
        $this->assertSame(__('services.change_package.refused.cancelling'), ChangePackage::refusal($service->fresh()));
    }

    /** CR-3: nothing due (its period ended) is shown as applied at once, which it is. */
    public function test_staff_see_nothing_due_once_the_period_ended(): void
    {
        $service = $this->service($this->small, 10.00);
        $service->update(['expires_at' => '2026-07-10']);

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->mountAction('changePackage')
            ->fillForm(['product_id' => $this->large->id])
            ->assertMountedActionModalSee('Total due: 0.00 USD; applied at once, the new price from the next renewal')
            ->assertMountedActionModalDontSee('an invoice, applied once paid')
            ->callMountedAction()
            ->assertNotified('Package changed');

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame($this->large->id, (int) $service->fresh()->product_id);
    }
}

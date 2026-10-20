<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientGroupResource;
use App\Admin\Resources\ClientGroupResource\Pages\CreateClientGroup;
use App\Events\Service\Updated;
use App\Livewire\Client\Account;
use App\Livewire\Services\Upgrade;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\OrderPricing;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #272 (customer groups with a percentage discount folded into the services' price). Tests named
 * `test_finding_*` fail on the branch as reviewed; `test_holds_*` are attacks that were stopped. Fake data only.
 */
class ClientGroupsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'],
            'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
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

    private function tax(string $type): void
    {
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => [$type, 'string']]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
    }

    private function user(?ClientGroup $group = null): User
    {
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);
        if ($group !== null) {
            $user->client()->update(['client_group_id' => $group->id]);
        }

        return $user;
    }

    private function group(string $percent = '10'): ClientGroup
    {
        return ClientGroup::query()->create(['name' => 'Partners ' . $percent, 'discount_percent' => $percent]);
    }

    private function product(float $price, string $unit = 'year', float $setupFee = 0): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function option(string $name, array $values, int $productId): ConfigOption
    {
        $option = ConfigOption::create(['name' => $name, 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $option->products()->attach($productId);
        foreach ($values as $sort => [$valueName, $price]) {
            $value = ConfigOption::create(['name' => $valueName, 'sort' => $sort, 'parent_id' => $option->id]);
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $valueName,
                'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }

        return $option->load('children');
    }

    private function service(User $user, Product $product, float $price, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $user->client()->id, 'product_id' => $product->id,
            'plan_id' => $product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
    }

    private function line(Product $product): OrderLine
    {
        return new OrderLine($product, $product->plans()->with('prices')->firstOrFail(), 1);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    // ------------------------------------------------------------------------------------------------ findings

    /**
     * G1 (medium). A member of a 10% group adds an option by an options-only upgrade (Disk 10 GB → 20 GB, +10.00 a
     * year). ServiceUpgrade::newRecurringPrice() adds the option's full catalog difference to the already discounted
     * price: 55.80 + 10.00 = 65.80, not (62 + 10) × 0.9 = 64.80. The upgrade invoice charges the undiscounted
     * difference too. And the new price is no longer «the computed one», so ClientGroupPricing treats it as staff's
     * from then on: a later move, a new percentage or leaving the group never reprices it.
     */
    public function test_finding_g1_an_option_added_by_an_upgrade_skips_the_group_discount(): void
    {
        $user = $this->user($this->group('10'));
        $product = $this->product(62.00);
        $disk = $this->option('Disk', [['10 GB', 0], ['20 GB', 10]], $product->id);
        $service = $this->service($user, $product, 55.80);
        $service->configs()->create(['config_option_id' => $disk->id, 'config_value_id' => $disk->children[0]->id]);
        $this->assertSame('55.80', $service->fresh()->calculatePrice(), 'the price is the computed one before the upgrade');

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$disk->id}", $disk->children[1]->id)->call('doUpgrade');
        $this->assertTrue((new ServiceUpgradeService)->handle(ServiceUpgrade::query()->sole()));

        $service = $service->fresh();
        $this->assertSame('64.80', $this->money($service->price), '(62 + 10) less 10%, as a new order of the same would be');
        $this->assertSame($service->calculatePrice(), $this->money($service->price), 'still the computed price, so a move reprices it');
    }

    /**
     * G2 (medium; needs the owner's call). «Is the computed one» is inferred by comparing the stored price with today's
     * catalog. A service bought at 20.00 a month before the catalog went to 22.00 (or imported from WHMCS at its own
     * amount) is not a price staff set by hand, yet ClientGroupPricing reads it as one: its customer joins a 10% group
     * and keeps paying 20.00. Every grandfathered service of the pilot's imported base is left out of the discount, while
     * the admin's helper text promises it «from the next renewal; a price set by hand is kept».
     */
    public function test_finding_g2_a_grandfathered_catalog_price_never_gets_the_group_discount(): void
    {
        $user = $this->user();
        $product = $this->product(22.00, 'month'); // the catalog now; the service was sold at 20.00
        $service = $this->service($user, $product, 20.00);

        $user->client()->update(['client_group_id' => $this->group('10')->id]);

        $this->assertSame('18.00', $this->money($service->fresh()->price), 'a price nobody set by hand takes the 10% off');
    }

    /**
     * G3 (low). Inclusive tax: the stored net price depends on the account's tax decision when it was computed. An
     * account bought at 24.80 gross (20.00 net, 24% VAT) and was then made tax exempt (or its country changed, or a
     * VAT number made it reverse-charge): the price is left at 20.00 net, but calculatePrice() now reads 24.80. A move
     * into a 10% group finds 20.00 ≠ 24.80, takes it for staff's price and gives no discount.
     */
    public function test_finding_g3_inclusive_tax_a_changed_tax_decision_blocks_the_group_discount(): void
    {
        $this->tax('inclusive');
        $user = $this->user();
        $service = (new PlaceOrderService)->place($user->client(), 'USD', [$this->line($this->product(24.80, 'month'))])->order->services()->sole();
        $this->assertSame('20.00', $this->money($service->price), '24.80 gross, 20.00 net');

        $user->client()->update(['tax_exempt' => true]);
        $this->assertSame('20.00', $this->money($service->fresh()->price), 'tax exempt alone does not touch the price');

        $user->client()->update(['client_group_id' => $this->group('10')->id]);

        $this->assertLessThan(20.00, (float) $service->fresh()->price, 'a computed price, never touched by staff, gets the 10% off');
    }

    // ------------------------------------------------------------------------------------------------ holds

    /** A product change upgrade: both sides are discounted, so the difference is too; the new price is the computed one. */
    public function test_holds_an_upgrade_to_another_product_is_discounted_on_both_sides(): void
    {
        $user = $this->user($this->group('10'));
        $from = $this->product(62.00);
        $to = $this->product(100.00);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);
        $service = $this->service($user, $from, 55.80);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service])->set('upgrade', $to->id)->call('doUpgrade');
        $upgrade = ServiceUpgrade::query()->sole();
        $charged = (float) $upgrade->invoice->items()->sole()->price;
        $this->assertTrue((new ServiceUpgradeService)->handle($upgrade));

        $this->assertSame('90.00', $this->money($service->fresh()->price));
        // (90 − 55.80) for the days left, not (100 − 55.80) nor (100 − 62).
        $this->assertSame((float) $service->fresh()->remainingOf('34.20', $service->fresh()->currentPeriodStart(), Carbon::now()), $charged);
    }

    /** 33.33%, a fixed coupon for every cycle and a setup fee: the coupon first, then the group, each rounded once. */
    public function test_holds_odd_percentages_and_a_fixed_coupon(): void
    {
        $coupon = Coupon::create(['code' => 'FIVE', 'type' => 'fixed', 'applies_to' => 'all', 'value' => 5, 'recurring' => 0]);
        $placed = (new PlaceOrderService)->place($this->user($this->group('33.33'))->client(), 'USD', [$this->line($this->product(20.00, 'month', 3.00))], $coupon);

        // Price 20 − 5 = 15, − 33.33% (4.9995 → 5.00) = 10.00; setup 3 (the fixed coupon is used up on the price) − 1.00 = 2.00.
        $this->assertSame('12.00', $this->money($placed->invoice->items()->sole()->price));
        $service = $placed->order->services()->sole();
        $this->assertSame(['10.00', '10.00'], [$this->money($service->price), $service->calculatePrice()]);
    }

    /** Inclusive tax with no change of tax decision: a move reprices the net price from the discounted gross. */
    public function test_holds_inclusive_tax_move_reprices_the_net_price(): void
    {
        $this->tax('inclusive');
        $user = $this->user();
        $service = (new PlaceOrderService)->place($user->client(), 'USD', [$this->line($this->product(24.80, 'month'))])->order->services()->sole();

        $user->client()->update(['client_group_id' => $this->group('10')->id]);

        // 24.80 − 10% = 22.32 gross, 18.00 net.
        $this->assertSame('18.00', $this->money($service->fresh()->price));
    }

    /** A 100% group: nothing to pay, nothing negative, and leaving it brings the full price back. */
    public function test_holds_a_full_discount_group_and_back(): void
    {
        $user = $this->user();
        $service = $this->service($user, $this->product(20.00, 'month'), 20.00);
        $group = $this->group('100');

        $user->client()->update(['client_group_id' => $group->id]);
        $this->assertSame('0.00', $this->money($service->fresh()->price));

        $user->client()->fresh()->update(['client_group_id' => null]);
        $this->assertSame('20.00', $this->money($service->fresh()->price));
    }

    /** A move while a renewal invoice is open: the invoice keeps its line (documented), the next renewal has the new price. */
    public function test_holds_an_open_renewal_invoice_keeps_its_line(): void
    {
        $user = $this->user();
        $service = $this->service($user, $this->product(20.00, 'month'), 20.00);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => 20.00, 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);

        $user->client()->update(['client_group_id' => $this->group('10')->id]);

        $this->assertSame(['20.00', '18.00'], [$this->money($line->fresh()->price), $this->money($service->fresh()->price)]);
    }

    /** Deleting a group with members: they leave it, computed prices go back to full, a staff price stays. */
    public function test_holds_deleting_a_group_with_members(): void
    {
        $group = $this->group('10');
        $user = $this->user($group);
        $product = $this->product(20.00, 'month');
        $computed = $this->service($user, $product, 18.00);
        $byHand = $this->service($user, $product, 7.77);

        $group->delete();

        $this->assertNull($user->client()->fresh()->client_group_id);
        $this->assertSame(['20.00', '7.77'], [$this->money($computed->fresh()->price), $this->money($byHand->fresh()->price)]);
    }

    /** A move reprices a computed price by a save, so a gateway subscription is told the new amount (Service\Updated). */
    public function test_holds_a_move_tells_the_gateway_subscription(): void
    {
        $user = $this->user();
        $service = $this->service($user, $this->product(20.00, 'month'), 20.00, ['subscription_id' => 'sub_fake_1']);
        Event::fake([Updated::class]);

        $user->client()->update(['client_group_id' => $this->group('10')->id]);

        Event::assertDispatched(Updated::class, fn (Updated $event) => (int) $event->service->id === (int) $service->id
            && $this->money($event->changes['price'] ?? null) === '18.00');
    }

    /** A domain-like line (no product) never takes the group discount, even at 100%. */
    public function test_holds_a_line_without_a_product_is_not_discounted(): void
    {
        $client = $this->user($this->group('100'))->client();

        $this->assertSame('12.00', OrderPricing::line(['price' => '12.00', 'setup_fee' => '0.00'], null, $client)->netPrice);
    }

    /** The renewal note is not written on a price staff set by hand. */
    public function test_holds_no_group_note_on_a_staff_price(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->settings(['cronjob_invoice' => [7, 'integer']]);
        $user = $this->user($this->group('10'));
        $service = $this->service($user, $this->product(20.00, 'month'), 15.00, ['expires_at' => '2026-10-25']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_id', $service->id))->sole()->items()->sole();
        $this->assertSame('15.00', $this->money($line->price));
        $this->assertStringNotContainsString('client discount', $line->description);
    }

    /** The admin API cannot move an account into a group (not in UpdateClientRequest's rules). */
    public function test_holds_the_api_cannot_set_the_group(): void
    {
        $token = 'GRP' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'k', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'staff_id' => Staff::factory()->admin()->create()->id, 'permissions' => ['admin.clients.update', 'admin.clients.view']]);
        $client = $this->user()->client();
        $group = $this->group('100');

        $this->putJson("/api/v1/admin/clients/{$client->id}", ['client_group_id' => $group->id],
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);

        $this->assertNull(Client::query()->find($client->id)->client_group_id);
    }

    /** The customer's account form saves only its whitelisted fields: no group. */
    public function test_holds_the_portal_account_form_cannot_set_the_group(): void
    {
        $user = $this->user();
        $group = $this->group('100');

        try {
            $this->livewireAs($user)->test(Account::class)->set('client_group_id', $group->id)->call('submit');
        } catch (\Throwable) {
        }

        $this->assertNull($user->client()->fresh()->client_group_id);
    }

    /** Staff who may only see groups cannot create or edit one. */
    public function test_holds_staff_without_the_permission_cannot_edit_a_group(): void
    {
        $group = $this->group('10');
        $staff = Staff::factory()->withPermissions(['admin.client_groups.viewAny', 'admin.clients.update'])->create();

        $this->actingAs($staff)->get(ClientGroupResource::getUrl('edit', ['record' => $group]))->assertForbidden();
        $this->actingAs($staff)->get(ClientGroupResource::getUrl('create'))->assertForbidden();
    }

    /** A percentage over 100 is refused by the admin form (LinePricer would throw on every price of its members). */
    public function test_holds_a_percentage_over_100_is_refused(): void
    {
        $this->livewireAs(Staff::factory()->admin()->create())->test(CreateClientGroup::class)
            ->fillForm(['name' => 'Too much', 'discount_percent' => 150])->call('create')
            ->assertHasFormErrors(['discount_percent']);

        $this->assertSame(0, ClientGroup::query()->count());
    }
}

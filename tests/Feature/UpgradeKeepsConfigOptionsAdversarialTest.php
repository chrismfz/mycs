<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #62 (SVC-3): an upgrade keeps the options the new product still has.
 */
class UpgradeKeepsConfigOptionsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The expected amounts are «15 of 30 days»: a fixed day whose period has 30 days (2026-06-25 → 07-25). From the
        // real today the period is 30 or 31 days long (on 2026-10-16 it ran to 10-31 and the test failed).
        $this->travelTo(Carbon::parse('2026-07-10 12:00:00'));
    }

    private function option(string $name, bool $upgradable, array $values, array $productIds, bool $hidden = false): ConfigOption
    {
        $option = ConfigOption::create(['name' => $name, 'env_variable' => strtolower(str_replace(' ', '_', $name)), 'type' => 'select',
            'sort' => 0, 'upgradable' => $upgradable, 'hidden' => $hidden]);
        $option->products()->attach($productIds);
        foreach ($values as $sort => [$valueName, $price]) {
            $value = ConfigOption::create(['name' => $valueName, 'env_variable' => $valueName, 'sort' => $sort, 'parent_id' => $option->id]);
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $valueName,
                'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);
        }

        return $option->load('children');
    }

    private function product(float $price): object
    {
        $p = $this->createProduct();
        Price::where('plan_id', $p->plan->id)->update(['price' => $price]);

        return $p;
    }

    private function service(object $product, array $configs, int $daysLeft = 15): Service
    {
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->startOfDay()->addDays($daysLeft), 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1,
        ]);
        foreach ($configs as [$option, $index]) {
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $option->children[$index]->id]);
        }
        $service->update(['price' => $service->fresh()->calculatePrice()]);

        return $service->fresh();
    }

    public function test_holds_product_change_keeps_shared_option_and_recurring_includes_it(): void
    {
        Queue::fake();
        $from = $this->product(10);
        $to = $this->product(20);
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productIds: [$from->product->id, $to->product->id]);
        $service = $this->service($from, [[$ip, 1]]);
        $this->assertEqualsWithDelta(13.0, (float) $service->price, 0.001);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id]);
        // Proration: only the product difference (20 - 10) over 15 of 30 days; the kept option is not charged again.
        $this->assertEqualsWithDelta(5.0, $upgrade->fresh()->calculatePrice()->price, 0.001);

        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $service->refresh();
        $this->assertEqualsWithDelta(23.0, (float) $service->price, 0.001, 'new recurring = new plan + kept option');
        $this->assertSame($ip->children[1]->id, $service->configs->first()->config_value_id);
    }

    public function test_holds_hidden_option_the_new_product_has_is_kept(): void
    {
        Queue::fake();
        $from = $this->product(10);
        $to = $this->product(20);
        $backup = $this->option('Backups', upgradable: true, values: [['Off', 0], ['On', 2]], productIds: [$from->product->id, $to->product->id], hidden: true);
        $service = $this->service($from, [[$backup, 1]]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id]);
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertEqualsWithDelta(22.0, (float) $service->fresh()->price, 0.001);
    }

    public function test_holds_downgrade_keeps_options_and_recurring_is_consistent(): void
    {
        Queue::fake();
        $from = $this->product(20);
        $to = $this->product(10);
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productIds: [$from->product->id, $to->product->id]);
        $service = $this->service($from, [[$ip, 1]]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id]);
        $this->assertEqualsWithDelta(-5.0, $upgrade->fresh()->calculatePrice()->price, 0.001, 'credit is only the plan difference');
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertEqualsWithDelta(13.0, (float) $service->fresh()->price, 0.001);
    }

    public function test_holds_the_upgraded_value_overrides_the_kept_one_and_the_rest_stay(): void
    {
        Queue::fake();
        $p = $this->product(10);
        $ram = $this->option('RAM', upgradable: true, values: [['2 GB', 0], ['4 GB', 5]], productIds: [$p->product->id]);
        $loc = $this->option('Location', upgradable: false, values: [['ATH', 0], ['FRA', 1]], productIds: [$p->product->id]);
        $service = $this->service($p, [[$ram, 0], [$loc, 1]]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $p->product->id, 'plan_id' => $p->plan->id]);
        $upgrade->configs()->create(['config_option_id' => $ram->id, 'config_value_id' => $ram->children[1]->id]);
        $this->assertEqualsWithDelta(2.5, $upgrade->fresh()->calculatePrice()->price, 0.001);

        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $service->refresh();
        $this->assertSame(2, $service->configs()->count(), 'no duplicate rows');
        $this->assertEqualsWithDelta(16.0, (float) $service->price, 0.001);
        // The upgrade's own morph rows are untouched.
        $this->assertSame(1, $upgrade->configs()->count());
        // Provisioning sees the kept option.
        $props = ExtensionHelper::getServiceProperties($service);
        $this->assertSame('FRA', $props['location']);
        $this->assertSame('4 GB', $props['ram']);
    }

    public function test_holds_pending_legacy_renewal_line_is_repriced_with_the_kept_option(): void
    {
        Queue::fake();
        $p = $this->product(10);
        $ram = $this->option('RAM', upgradable: true, values: [['2 GB', 0], ['4 GB', 5]], productIds: [$p->product->id]);
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productIds: [$p->product->id]);
        $service = $this->service($p, [[$ram, 0], [$ip, 1]]);
        $invoice = Invoice::create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'status' => 'unpaid', 'due_at' => now()]);
        $line = $invoice->items()->create(['description' => 'renewal', 'price' => 13, 'quantity' => 1,
            'reference_type' => Service::class, 'reference_id' => $service->id]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $p->product->id, 'plan_id' => $p->plan->id]);
        $upgrade->configs()->create(['config_option_id' => $ram->id, 'config_value_id' => $ram->children[1]->id]);
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertEqualsWithDelta(18.0, (float) $line->fresh()->price, 0.001);
    }

    /**
     * Pre-existing (not introduced by #62), but it is the other half of the claim "only options the new product does
     * not have are removed": a removed priced option leaves the recurring amount, yet the proration of the upgrade does
     * not credit its unused remainder. Same product price, the 3/month IP goes, 15 of 30 days left: the customer should
     * get -1.50, the upgrade costs 0.
     */
    public function test_a_dropped_priced_option_is_credited_in_the_proration(): void
    {
        $this->markTestIncomplete('Already on main, follow-up with SVC-14 (upgrade proration): an option the new product does not have leaves the recurring amount but its unused part is not credited.');

        Queue::fake();
        $from = $this->product(10);
        $to = $this->product(10);
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productIds: [$from->product->id]);
        $service = $this->service($from, [[$ip, 1]]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id]);
        $price = $upgrade->fresh()->calculatePrice()->price;
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertEqualsWithDelta(10.0, (float) $service->fresh()->price, 0.001, 'the option left the recurring amount');
        $this->assertEqualsWithDelta(-1.5, $price, 0.001, 'its unused remainder is credited');
    }
}

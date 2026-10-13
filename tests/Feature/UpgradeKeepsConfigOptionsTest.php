<?php

namespace Tests\Feature;

use App\Models\ConfigOption;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An upgrade keeps the options it does not offer (audit sweep SVC-3). The upgrade form offers only the upgradable
 * options, and the upgrade deleted every other option of the service: an extra IP, a location, a hidden option staff
 * set. Its price left the recurring amount and its value left provisioning.
 */
class UpgradeKeepsConfigOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function option(string $name, bool $upgradable, array $values, int $productId, bool $hidden = false): ConfigOption
    {
        $option = ConfigOption::create(['name' => $name, 'type' => 'select', 'sort' => 0, 'upgradable' => $upgradable, 'hidden' => $hidden]);
        $option->products()->attach($productId);
        foreach ($values as $sort => [$valueName, $price]) {
            $value = ConfigOption::create(['name' => $valueName, 'sort' => $sort, 'parent_id' => $option->id]);
            $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $valueName,
                'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);
        }

        return $option->load('children');
    }

    public function test_an_upgrade_keeps_the_options_it_does_not_offer(): void
    {
        Queue::fake();
        $product = $this->createProduct();
        $productId = $product->product->id;
        $ram = $this->option('RAM', upgradable: true, values: [['2 GB', 0], ['4 GB', 5]], productId: $productId);
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productId: $productId);
        $backup = $this->option('Backups', upgradable: false, values: [['Off', 0], ['On', 2]], productId: $productId, hidden: true);

        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $productId,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
        ]);
        foreach ([[$ram, 0], [$ip, 1], [$backup, 1]] as [$option, $index]) {
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $option->children[$index]->id]);
        }

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $productId, 'plan_id' => $product->plan->id]);
        $upgrade->configs()->create(['config_option_id' => $ram->id, 'config_value_id' => $ram->children[1]->id]); // 2 GB -> 4 GB

        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $configs = $service->fresh()->configs->pluck('config_value_id', 'config_option_id');
        $this->assertSame($ram->children[1]->id, $configs[$ram->id], 'the upgraded option changes');
        $this->assertSame($ip->children[1]->id, $configs[$ip->id] ?? null, 'the extra IP stays');
        $this->assertSame($backup->children[1]->id, $configs[$backup->id] ?? null, 'the hidden option staff set stays');
        // The money: the recurring amount keeps the options it kept (plan + 4 GB 5 + 1 IP 3 + backups 2).
        $planPrice = (float) $product->plan->prices()->where('currency_code', 'USD')->value('price');
        $this->assertEqualsWithDelta($planPrice + 5 + 3 + 2, (float) $service->fresh()->price, 0.001);
    }

    public function test_an_option_the_new_product_does_not_have_goes(): void
    {
        Queue::fake();
        $from = $this->createProduct();
        $to = $this->createProduct();
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productId: $from->product->id);

        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $from->plan->id, 'product_id' => $from->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
        ]);
        $service->configs()->create(['config_option_id' => $ip->id, 'config_value_id' => $ip->children[1]->id]);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $to->product->id, 'plan_id' => $to->plan->id]);
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertSame(0, $service->fresh()->configs()->count());
    }

    public function test_on_the_same_product_an_option_staff_unlinked_since_stays(): void
    {
        Queue::fake();
        $product = $this->createProduct();
        $ip = $this->option('Extra IP', upgradable: false, values: [['None', 0], ['1 IP', 3]], productId: $product->product->id);
        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
        ]);
        $service->configs()->create(['config_option_id' => $ip->id, 'config_value_id' => $ip->children[1]->id]);
        $ip->products()->detach($product->product->id); // unlinked later (or an imported WHMCS option)

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id]);
        (new ServiceUpgradeService)->handle($upgrade->fresh());

        $this->assertSame(1, $service->fresh()->configs()->count());
    }
}

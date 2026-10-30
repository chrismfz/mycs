<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceConfig;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A customer's options-only downgrade never leaves the service with a price below zero (sweep 9γ, owner 2026-10-30, as
 * Blesta): the price it has plus the options' difference, unless that is negative (a price under the catalog's: staff's,
 * or imported from WHMCS); then the catalog's with the options it keeps, as a change of plan prices it.
 */
class DowngradeNeverNegativeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private ConfigOption $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_on_downgrade' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $this->user = User::factory()->create();
        $this->user->client()->primaryContact->update(['country' => 'GR']);
        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 20.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->backup = ConfigOption::create(['name' => 'Backup', 'type' => 'select', 'sort' => 0, 'upgradable' => true, 'hidden' => false]);
        $this->backup->products()->attach($this->product->id);
        foreach ([['None', 0], ['Daily', 30]] as $sort => [$name, $price]) {
            $value = ConfigOption::create(['name' => $name, 'sort' => $sort, 'parent_id' => $this->backup->id]);
            $valuePlan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $name,
                'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $valuePlan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }
        $this->backup->load('children');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A service with daily backups at $price a year, expiring 2027-01-01; the customer takes the backups off. */
    private function dropBackups(float $price): Service
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $service->configs()->create(['config_option_id' => $this->backup->id, 'config_value_id' => $this->backup->children[1]->id]);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set("configOptions.{$this->backup->id}", $this->backup->children[0]->id)->call('doUpgrade');

        return $service->fresh();
    }

    public function test_a_downgrade_that_stays_above_zero_keeps_the_price_it_had_less_the_option(): void
    {
        $this->assertSame('30.00', number_format((float) $this->dropBackups(60.00)->price, 2, '.', ''), '60 − 30, a staff price kept');
    }

    public function test_a_price_under_the_catalogs_less_the_option_is_the_catalogs_never_negative(): void
    {
        $service = $this->dropBackups(25.00); // 25 − 30 = −5

        $this->assertSame('20.00', number_format((float) $service->price, 2, '.', ''), 'the catalog\'s 20 with no backups');
        $this->assertGreaterThan(0, (float) Credit::query()->sum('amount'), 'from 25 to 20: the days left are credited');
        $this->assertNull(ServiceUpgrade::query()->sole()->invoice_id);
    }

    /** The catalog's can be more than the price it had: the customer is shown it and pays the days left, as a plan change. */
    public function test_a_deep_discount_less_the_option_is_billed_up_to_the_catalogs(): void
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'price' => 5.00, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $service->configs()->create(['config_option_id' => $this->backup->id, 'config_value_id' => $this->backup->children[1]->id]);
        $upgrade = new ServiceUpgrade(['service_id' => $service->id, 'product_id' => $this->product->id, 'plan_id' => $service->plan_id]);
        $upgrade->setRelation('service', $service->fresh());
        $upgrade->setRelation('configs', collect([new ServiceConfig(['config_option_id' => $this->backup->id,
            'config_value_id' => $this->backup->children[0]->id])]));

        $this->assertSame('20.00', $upgrade->newRecurringPrice(), '5 − 30 is never the price: the catalog\'s');
        $this->assertGreaterThan(0, $upgrade->calculatePrice()->price, 'charged the days left up to it, never credited');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Index;
use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUTH-6: a draft invoice is the staff's until it is issued (the customer saw it, as a list entry and a PDF; WHMCS
 * hides it). AUTH-5: an upgrade takes only its options' own values (any option's id was taken, a foreign product's
 * too, and priced), and a downgrade gives credit only where credits are on.
 */
class ClientDraftsAndUpgradeValuesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->user = User::factory()->create();
    }

    public function test_the_customer_does_not_see_a_draft(): void
    {
        $draft = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_DRAFT, 'currency_code' => 'USD']);
        $issued = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        $this->assertFalse($this->user->can('view', $draft));
        $this->assertTrue($this->user->can('view', $issued));
        $this->livewireAs($this->user)->test(Index::class)
            ->assertViewHas('invoices', fn ($invoices) => $invoices->pluck('id')->all() === [$issued->id]);
    }

    /** @return array{0: Service, 1: \stdClass, 2: ConfigOption, 3: ConfigOption} */
    private function upgradeWithOption(): array
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        $option = ConfigOption::query()->create(['name' => 'Backups', 'env_variable' => 'backups', 'type' => 'checkbox', 'upgradable' => true]);
        $on = ConfigOption::query()->create(['name' => 'On', 'env_variable' => 'on', 'type' => 'checkbox', 'parent_id' => $option->id]);
        DB::table('config_option_products')->insert(['config_option_id' => $option->id, 'product_id' => $to->product->id]);
        $foreign = ConfigOption::query()->create(['name' => 'Other product value', 'env_variable' => 'x', 'type' => 'select']);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);

        return [$service, $to, $option, $foreign];
    }

    public function test_a_checkbox_takes_its_own_value_not_one_the_customer_sends(): void
    {
        [$service, $to, $option, $foreign] = $this->upgradeWithOption();

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $to->product->id)
            ->set("configOptions.{$option->id}", $foreign->id)
            ->call('doUpgrade');

        $values = ServiceUpgrade::query()->where('service_id', $service->id)->first()?->configs->pluck('config_value_id')->all() ?? [];
        $this->assertNotContains($foreign->id, $values, 'a foreign option priced and saved on the service');
    }

    public function test_a_downgrade_gives_no_credit_where_credits_are_off(): void
    {
        config(['settings.credits_enabled' => false, 'settings.credits_on_downgrade' => true]);
        $from = $this->createProduct();
        $to = $this->createProduct();
        Price::query()->where('plan_id', $to->plan->id)->update(['price' => 2.00]);
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $to->product->id)
            ->call('doUpgrade');

        $this->assertSame(0, Credit::query()->where('client_id', $this->user->client()->id)->where('amount', '>', 0)->count());
    }
}

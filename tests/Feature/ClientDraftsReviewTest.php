<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\ConfigOption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR #86 (AUTH-5 upgrade values).
 */
class ClientDraftsReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->user = User::factory()->create();
    }

    private function priced(ConfigOption $value, float $price): void
    {
        $plan = Plan::factory()->create([
            'priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => $value->name,
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring',
        ]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);
    }

    /**
     * A product with an upgradable checkbox (child priced 6.00/month). A dummy option takes id 1 first, so the checkbox
     * child is not id 1. The service has or has not the checkbox on.
     *
     * @return array{0: Service, 1: ConfigOption, 2: ConfigOption}
     */
    private function serviceWithCheckbox(bool $on): array
    {
        // Occupies id 1 (unpriced, of no product): what a loose `true` resolves to.
        ConfigOption::query()->create(['name' => 'Unrelated', 'env_variable' => 'unrelated', 'type' => 'select']);
        $product = $this->createProduct();
        $option = ConfigOption::query()->create(['name' => 'Backups', 'env_variable' => 'backups', 'type' => 'checkbox', 'upgradable' => true]);
        $child = ConfigOption::query()->create(['name' => 'On', 'env_variable' => 'on', 'type' => 'checkbox', 'parent_id' => $option->id]);
        $this->priced($child, 6.00);
        DB::table('config_option_products')->insert(['config_option_id' => $option->id, 'product_id' => $product->product->id]);
        $service = Service::factory()->create([
            'user_id' => $this->user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'price' => $on ? 16.00 : 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(30),
        ]);
        if ($on) {
            $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $child->id]);
        }

        return [$service->fresh(), $option, $child];
    }

    /** R1: unticking a paid checkbox is silently dropped — «upgrade completed», the option stays and is billed. */
    public function test_unticking_a_paid_checkbox_is_not_reported_as_done_while_the_option_stays(): void
    {
        [$service, $option, $child] = $this->serviceWithCheckbox(true);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set("configOptions.{$option->id}", false)
            ->call('doUpgrade');

        $completed = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->exists();
        $stillOn = $service->fresh()->configs()->where('config_option_id', $option->id)->where('config_value_id', $child->id)->exists();

        $this->assertFalse($completed && $stillOn, 'the upgrade completed but the unticked option is still on the service (and billed)');
    }

    /** R2: a ticked checkbox (Livewire sends `true`) is shown at one price and charged at another. */
    public function test_the_price_shown_for_a_ticked_checkbox_is_the_price_charged(): void
    {
        [$service, $option] = $this->serviceWithCheckbox(false);

        $component = $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set("configOptions.{$option->id}", true);
        $shown = round((float) $component->instance()->totalToday()->price, 2);

        $component->call('doUpgrade');
        $invoice = Invoice::query()->where('user_id', $this->user->id)->latest('id')->first();
        $charged = round((float) ($invoice?->items()->sum('price') ?? 0), 2);

        $this->assertGreaterThan(0, $charged, 'the ticked checkbox is charged');
        $this->assertSame($charged, $shown, 'Total today differs from what the invoice charges');
    }

    /** R3: an upgradable text/number option (no children; its value is a service property) breaks the upgrade page. */
    public function test_an_upgradable_text_option_does_not_break_the_upgrade(): void
    {
        $product = $this->createProduct();
        $text = ConfigOption::query()->create(['name' => 'Hostname', 'env_variable' => 'hostname', 'type' => 'text', 'upgradable' => true]);
        DB::table('config_option_products')->insert(['config_option_id' => $text->id, 'product_id' => $product->product->id]);
        $service = Service::factory()->create([
            'user_id' => $this->user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(30),
        ]);
        $service->properties()->create(['key' => 'hostname', 'name' => 'Hostname', 'value' => 'host.example.test']);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->assertSet('step', 2)
            ->assertHasNoErrors();

        $this->assertSame('host.example.test', $service->fresh()->properties()->where('key', 'hostname')->value('value'));
    }
}

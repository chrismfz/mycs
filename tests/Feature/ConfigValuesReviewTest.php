<?php

namespace Tests\Feature;

use App\Admin\Resources\ConfigOptionResource\Pages\EditConfigOption;
use App\Admin\Resources\CurrencyResource\Pages\ListCurrencies;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial review of PR #79 (DATA-8, DATA-9, DATA-16). */
class ConfigValuesReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    /** @return array{0: ConfigOption, 1: ConfigOption, 2: ConfigOption} */
    private function option(): array
    {
        $option = ConfigOption::query()->create(['name' => 'Disk', 'env_variable' => 'disk', 'type' => 'select']);
        $a = ConfigOption::query()->create(['name' => '10GB', 'env_variable' => '10gb', 'type' => 'select', 'parent_id' => $option->id]);
        $b = ConfigOption::query()->create(['name' => '20GB', 'env_variable' => '20gb', 'type' => 'select', 'parent_id' => $option->id]);

        foreach ([$a, $b] as $value) {
            Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly', 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        }

        return [$option, $a, $b];
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * F1. A deleted service leaves its service_configs (a morph: no foreign key, no cleanup in ServiceObserver). Its value
     * now counts as "used by services" for ever: staff cannot delete it, and there is no service to move.
     */
    public function test_a_value_only_a_deleted_service_had_can_be_deleted(): void
    {
        [$option, $value] = $this->option();
        $service = $this->service();
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);

        $service->delete(); // EditService's Delete, ServiceResource's bulk delete, a user deleted

        $value->delete();
        $this->assertNull(ConfigOption::find($value->id), 'no service uses it any more');
    }

    /**
     * F2. A completed (or cancelled) upgrade keeps its service_configs. They block the value for ever too, though no
     * service has it (the service moved on since, or the upgrade never applied).
     */
    public function test_a_value_only_a_finished_upgrade_chose_can_be_deleted(): void
    {
        [$option, $a, $b] = $this->option();
        $service = $this->service();
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $a->id]);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $service->product_id, 'plan_id' => $service->plan_id, 'status' => ServiceUpgrade::STATUS_CANCELLED]);
        $upgrade->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $b->id]);

        $b->delete();
        $this->assertNull(ConfigOption::find($b->id), 'no service has it; a cancelled upgrade chose it once');
    }

    /** F3. The currencies list's bulk Delete skips every guard of EditCurrency, the new invoice one (DATA-16) too. */
    public function test_the_bulk_delete_refuses_a_currency_with_invoices(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);

        // Fixed by removing the bulk delete: currencies are deleted one at a time, through the edit page's checks.
        $this->livewireAs($this->admin())->test(ListCurrencies::class)->assertTableBulkActionDoesNotExist('delete');

        $this->assertNotNull(Currency::find('EUR'), 'its invoices keep their currency');
    }

    /** Probe: the repeater's delete guard finds the item's id and cancels (the form keeps it). */
    public function test_removing_a_used_value_in_the_form_is_refused(): void
    {
        [$option, $value] = $this->option();
        $this->service()->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);

        $page = $this->livewireAs($this->admin())->test(EditConfigOption::class, ['record' => $option->getRouteKey()]);
        $page->callAction(TestAction::make('delete')->schemaComponent('Options', 'form')->arguments(['item' => 'record-' . $value->id]));
        $page->assertNotified('Whoops!');
        $page->call('save');

        $this->assertNotNull(ConfigOption::find($value->id));
    }

    /** Probe: a value used after it was removed from the form: Save is refused with a message, nothing else saved. */
    public function test_save_refuses_a_value_used_meanwhile(): void
    {
        [$option, $value] = $this->option();
        $page = $this->livewireAs($this->admin())->test(EditConfigOption::class, ['record' => $option->getRouteKey()]);
        $page->callAction(TestAction::make('delete')->schemaComponent('Options', 'form')->arguments(['item' => 'record-' . $value->id]));
        $this->service()->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);
        $page->set('data.name', 'Renamed')->call('save');

        $page->assertNotified('Option not saved');
        $this->assertNotNull(ConfigOption::find($value->id));
        $this->assertSame('Disk', $option->fresh()->name, 'rolled back');
    }

    /** Probe: Delete of the whole option, its values used. */
    public function test_deleting_the_whole_option_from_its_page(): void
    {
        [$option, $value] = $this->option();
        $this->service()->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);

        $this->livewireAs($this->admin())->test(EditConfigOption::class, ['record' => $option->getRouteKey()])->callAction('Delete');

        $this->assertSame(0, ConfigOption::query()->count());
    }

    /**
     * F4 (round 2). The deleting hook checks, then deletes every row pointing at the value, without a lock: a service that
     * takes the value in between (an order checked out meanwhile) loses it silently, and the foreign key, which would have
     * refused the delete, is bypassed. Only the dead rows should go, so a live one left makes the foreign key refuse.
     */
    public function test_a_service_that_takes_the_value_meanwhile_keeps_it(): void
    {
        [$option, $value] = $this->option();
        $service = $this->service();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $service, $option, $value) {
            if (!$fired && str_contains($query->sql, 'exists') && str_contains($query->sql, 'service_configs')) {
                $fired = true; // the check has run: the order is placed now
                DB::table('service_configs')->insert(['configurable_type' => Service::class, 'configurable_id' => $service->id,
                    'config_option_id' => $option->id, 'config_value_id' => $value->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            $value->delete();
        } catch (\Throwable) {
            // refused: right
        }

        $this->assertTrue($fired);
        $this->assertSame(1, $service->configs()->count(), 'the service keeps its value');
        $this->assertNotNull(ConfigOption::find($value->id));
    }

    /** Probe (round 2): a cancelled service that still exists, and a pending upgrade, keep the value. */
    public function test_a_cancelled_service_and_a_pending_upgrade_keep_the_value(): void
    {
        [$option, $a, $b] = $this->option();
        $service = $this->service(['status' => 'cancelled']);
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $a->id]);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $service->product_id, 'plan_id' => $service->plan_id, 'status' => ServiceUpgrade::STATUS_PENDING]);
        $upgrade->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $b->id]);

        $this->assertTrue($a->fresh()->valueInUse());
        $this->assertTrue($b->fresh()->valueInUse());
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\CurrencyResource\Pages\EditCurrency;
use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DATA-8: a config option's value services use is not deleted with them (it under-billed them, provisioning lost it).
 * DATA-9: a removed saved payment method no longer fails the cron (and the customer is told to pay).
 * DATA-16: a currency with invoices is not deleted.
 */
class ConfigValuesAgreementsCurrenciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    /** @return array{0: ConfigOption, 1: ConfigOption, 2: ConfigOption} option, a used value, an unused value */
    private function option(Service $service): array
    {
        $option = ConfigOption::query()->create(['name' => 'Disk', 'env_variable' => 'disk', 'type' => 'select']);
        $used = ConfigOption::query()->create(['name' => '10GB', 'env_variable' => '10gb', 'type' => 'select', 'parent_id' => $option->id]);
        $unused = ConfigOption::query()->create(['name' => '20GB', 'env_variable' => '20gb', 'type' => 'select', 'parent_id' => $option->id]);
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $used->id]);

        return [$option, $used, $unused];
    }

    public function test_a_value_services_use_is_not_deleted(): void
    {
        $service = $this->service();
        [, $used, $unused] = $this->option($service);

        try {
            $used->delete();
            $this->fail('deleted while in use');
        } catch (DisplayException) {
        }
        $this->assertSame(1, $service->configs()->count(), 'the service keeps its value');

        $unused->delete();
        $this->assertNull(ConfigOption::find($unused->id), 'an unused value goes');
    }

    public function test_the_database_refuses_it_too(): void
    {
        [, $used] = $this->option($this->service());

        $this->expectException(QueryException::class);
        DB::table('config_options')->where('id', $used->id)->delete();
    }

    public function test_deleting_the_whole_option_as_staff_confirm_it_still_works(): void
    {
        $service = $this->service();
        [$option] = $this->option($service);

        $option->serviceConfigs()->delete(); // what EditConfigOption's Delete does, after its warning
        $option->delete();

        $this->assertSame(0, $service->configs()->count());
        $this->assertSame(0, ConfigOption::query()->count());
    }

    public function test_a_removed_payment_method_leaves_its_services(): void
    {
        $user = User::factory()->create();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $agreement = BillingAgreement::create(['client_id' => $user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal']);
        $service = $this->service(['client_id' => $user->client()->id, 'billing_agreement_id' => $agreement->id]);

        $agreement->delete(); // soft: the customer took it off

        $this->assertNull($service->fresh()->billing_agreement_id);
    }

    public function test_the_cron_survives_a_service_whose_payment_method_is_gone(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        $user = User::factory()->create();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $agreement = BillingAgreement::create(['client_id' => $user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal', 'external_reference' => 'VAULT-1', 'type' => 'paypal']);
        $service = $this->service(['client_id' => $user->client()->id, 'billing_agreement_id' => $agreement->id, 'expires_at' => '2026-10-25']);
        DB::table('billing_agreements')->where('id', $agreement->id)->update(['deleted_at' => now()]); // removed before this fix: the service kept it

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->count(), 'billed; the customer pays it');
    }

    public function test_a_currency_with_invoices_is_not_deleted(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($owner)->test(EditCurrency::class, ['record' => 'EUR'])->callAction('delete');

        $this->assertNotNull(Currency::find('EUR'));
    }
}

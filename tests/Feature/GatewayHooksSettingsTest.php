<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\CreateGateway;
use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A gateway's hooks (enabled on create, updated on save) get the settings just saved, not those its record read before
 * the save (2026-11-02: Stripe created on the test site was asked for its webhook with no key, so none was made).
 */
class GatewayHooksSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => ['*']])->id]);
        Http::fake([
            'api.stripe.com/v1/webhook_endpoints' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['id' => 'we_1', 'secret' => 'whsec_new']),
        ]);
    }

    private function assertWebhookCreatedWith(string $key): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/webhook_endpoints'
            && $request->header('Authorization') === ['Bearer ' . $key]);
        Http::assertNotSent(fn (Request $request) => $request->header('Authorization') !== ['Bearer ' . $key]);
    }

    public function test_creating_stripe_creates_its_webhook_with_the_key_typed_in(): void
    {
        $this->livewireAs($this->staff)->test(CreateGateway::class)
            ->fillForm([
                'name' => 'Stripe',
                'extension' => 'Stripe',
                'settings' => ['stripe_secret_key' => 'rk_test_typed', 'stripe_publishable_key' => 'pk_test_typed'],
            ])
            ->call('create')->assertHasNoErrors();

        $this->assertWebhookCreatedWith('rk_test_typed');
        $gateway = Gateway::query()->where('extension', 'Stripe')->firstOrFail();
        $this->assertSame('whsec_new', $gateway->settings()->where('key', 'stripe_webhook_secret')->first()?->value);
    }

    public function test_a_key_changed_on_edit_is_the_one_the_webhook_is_created_with(): void
    {
        // As on the test site: created, the webhook never made (no secret stored).
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'rk_test_old', 'encrypted' => true]);
        $gateway->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_old']);

        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $gateway->getRouteKey()])
            ->set('data.settings.stripe_secret_key', 'rk_test_new')
            ->call('save')->assertHasNoErrors();

        $this->assertWebhookCreatedWith('rk_test_new');
        $this->assertSame('rk_test_new', $gateway->settings()->where('key', 'stripe_secret_key')->first()?->value);
        $this->assertSame('whsec_new', $gateway->settings()->where('key', 'stripe_webhook_secret')->first()?->value);
    }

    public function test_a_stored_key_left_empty_on_edit_is_the_one_used(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'rk_test_stored', 'encrypted' => true]);
        $gateway->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_stored']);

        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $gateway->getRouteKey()])
            ->assertSet('data.settings.stripe_secret_key', null)
            ->call('save')->assertHasNoErrors();

        $this->assertWebhookCreatedWith('rk_test_stored');
        $this->assertSame('whsec_new', $gateway->settings()->where('key', 'stripe_webhook_secret')->first()?->value);
    }

    /**
     * Review of this fix: as on the site after `artisan optimize`, the routes cached and Stripe created after it. Called
     * directly: inside a Livewire test call the router holds a plain collection again.
     */
    public function test_a_stripe_gateway_created_after_the_routes_were_cached_gets_its_webhook(): void
    {
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        ExtensionHelper::saveSettings($gateway, ['stripe_secret_key' => 'rk_test_typed', 'stripe_publishable_key' => 'pk_test_typed'], skipNull: true);

        $router = app('router');
        $router->setCompiledRoutes($router->getRoutes()->compile());
        $this->assertInstanceOf(CompiledRouteCollection::class, Route::getRoutes());
        // In the cache whatever the database held when it was built (#579): before it, missing here.
        $this->assertTrue(Route::has('extensions.gateways.stripe.webhook'));

        ExtensionHelper::call($gateway, 'enabled', [$gateway]); // not mayFail: an error fails the test

        $this->assertWebhookCreatedWith('rk_test_typed');
        $this->assertSame('whsec_new', $gateway->settings()->where('key', 'stripe_webhook_secret')->first()?->value);
    }
}

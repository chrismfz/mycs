<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Models\Gateway;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Stripe gateway's page says whether its webhook is saved (owner 2026-11-02, after a live payment that was never
 * recorded: the secret had been saved empty, and a stored secret is never shown, so nobody could tell).
 */
class StripeWebhookStatusTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Gateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.stripe.com/v1/webhook_endpoints*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['id' => 'we_made', 'secret' => 'whsec_made_here']),
            'api.stripe.com/*' => Http::response(['object' => 'balance', 'available' => [], 'pending' => []])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $this->stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_live_key', 'encrypted' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_live_key']);
    }

    public function test_an_empty_secret_is_shown_as_not_saved(): void
    {
        $this->stripe->settings()->create(['key' => 'stripe_webhook_secret', 'value' => '', 'encrypted' => true]);

        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $this->stripe->getRouteKey()])
            ->assertSee('Not saved: payments made with Stripe are not recorded');
    }

    public function test_once_saved_it_shows_its_endpoint_never_its_secret(): void
    {
        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $this->stripe->getRouteKey()])->call('save')->assertHasNoErrors();

        $page = $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $this->stripe->getRouteKey()]);
        $page->assertSee('(endpoint we_made)')->assertSee(route('extensions.gateways.stripe.webhook'))->assertDontSee('whsec_made_here');
    }

    public function test_a_secret_typed_in_by_hand_is_not_taken_as_made_here(): void
    {
        $this->stripe->settings()->create(['key' => 'stripe_webhook_secret', 'value' => 'whsec_typed', 'encrypted' => true]);

        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $this->stripe->getRouteKey()])
            ->assertSee('not made for this key and this site')->assertDontSee('whsec_typed');
    }
}

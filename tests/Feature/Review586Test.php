<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\CreateGateway;
use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Models\Gateway;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Independent review of #586 (webhook status line). */
class Review586Test extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Gateway $stripe;

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.stripe.com/v1/webhook_endpoints*' => function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }
            $this->posts++;

            return Http::response(['id' => 'we_made' . $this->posts, 'secret' => 'whsec_made_here' . $this->posts]);
        },
            'api.stripe.com/*' => Http::response(['object' => 'balance', 'available' => [], 'pending' => []])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $this->stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_live_key', 'encrypted' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_live_key']);
    }

    private function page()
    {
        return $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $this->stripe->getRouteKey()]);
    }

    /**
     * R1: an encrypted empty secret is ciphertext in the column; updated() reads it with ->value('value') (raw, never
     * decrypted: only `value` is selected, so `encrypted` is unknown) and takes it as saved. The page says «Not saved:
     * press Save to create it», and Save does nothing.
     */
    public function test_r1_not_saved_then_save_creates_it(): void
    {
        $for = substr(hash('sha256', 'sk_live_key|' . route('extensions.gateways.stripe.webhook')), 0, 16);
        $this->stripe->settings()->create(['key' => 'stripe_webhook_secret', 'value' => '', 'encrypted' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_webhook_for', 'value' => $for]);
        $this->assertNotSame('', (string) $this->stripe->settings()->where('key', 'stripe_webhook_secret')->value('value'), 'raw ciphertext of an empty string');

        $this->page()->assertSee('Not saved: payments made with Stripe are not recorded')->call('save')->assertHasNoErrors();

        $this->assertSame(1, $this->posts, 'Save, as the page says, creates the webhook');
        $this->page()->assertSee('(endpoint we_made1)');
    }

    /**
     * R2: a secret typed in by hand over one made here keeps the old fingerprint: updated() keeps it, and the page says
     * ✓ Saved with the old endpoint, while webhook signatures will fail.
     */
    public function test_r2_hand_typed_secret_over_a_made_one_is_not_shown_as_ok(): void
    {
        $this->page()->call('save')->assertHasNoErrors();
        $this->page()->assertSee('(endpoint we_made1)');

        // Fixed: the secret is part of what it was made for, so the Save that typed it makes the webhook again (its own
        // secret replaces the typed one); never ✓ with an endpoint whose signatures would all fail.
        $this->page()->set('data.settings.stripe_webhook_secret', 'whsec_typed')->call('save')->assertHasNoErrors();
        $this->assertSame('whsec_made_here2', Setting::query()->where('settingable_id', $this->stripe->id)->where('key', 'stripe_webhook_secret')->first()->value);

        $this->page()->assertDontSee('(endpoint we_made1)')->assertSee('(endpoint we_made2)');
    }

    /** R3: «Saved <date>» is the last Save of the form, not when the webhook was made (the secret is re-encrypted each Save). */
    public function test_r3_saved_date_is_when_it_was_made(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->setTime(10, 0));
        $this->page()->call('save')->assertHasNoErrors();
        $this->travelTo(now()->setDate(2026, 3, 3)->setTime(12, 0));
        $this->page()->set('data.name', 'Stripe 2')->call('save')->assertHasNoErrors();

        $this->assertSame(1, $this->posts);
        preg_match('/Saved (20[0-9-]+ [0-9:]+)/', $this->page()->html(), $m);
        $this->assertSame('2026-01-01 10:00', $m[1] ?? 'none');
    }

    /** No secret in the Livewire snapshot (not only the HTML), after a save in the same component. */
    public function test_no_secret_in_snapshot(): void
    {
        $page = $this->page()->call('save')->assertHasNoErrors();
        $this->assertStringNotContainsString('whsec_made_here', json_encode($page->snapshot));
        $this->assertStringNotContainsString('sk_live_key', json_encode($page->snapshot));
        $page->assertDontSee('whsec_made_here')->assertDontSee('sk_live_key');
        $this->assertStringNotContainsString('whsec_made_here', json_encode($this->page()->snapshot));
    }

    /** The create page and other gateways render without the line, and without errors. */
    public function test_create_page_and_other_gateway(): void
    {
        $this->livewireAs($this->staff)->test(CreateGateway::class)->set('data.extension', 'Stripe')->assertOk()->assertDontSee('Not saved:');
        $other = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $other->getRouteKey()])->assertOk()->assertDontSee('Could not be read');
        $broken = Gateway::query()->create(['extension' => 'NoSuchGateway', 'name' => 'Broken', 'type' => 'gateway', 'enabled' => true]);
        $this->livewireAs($this->staff)->test(EditGateway::class, ['record' => $broken->getRouteKey()])->assertOk();
    }

    /** stripe_webhook_id survives a plain Save and is not wiped by saveSettings. */
    public function test_webhook_id_survives_save(): void
    {
        $this->page()->call('save')->assertHasNoErrors();
        $this->page()->set('data.name', 'Stripe 2')->call('save')->assertHasNoErrors();
        $this->assertSame('we_made1', $this->stripe->settings()->where('key', 'stripe_webhook_id')->value('value'));
    }
}

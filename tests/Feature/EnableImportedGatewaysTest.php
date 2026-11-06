<?php

namespace Tests\Feature;

use App\Models\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cut-over's gateways on (owner 2026-11-06): each imported one that is whole and answers, with its webhook; any other
 * left disabled and said. Fake keys, no real data.
 */
class EnableImportedGatewaysTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array{0: string, 1: bool}>  $settings */
    private function gateway(string $extension, array $settings = []): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => $extension, 'name' => $extension . ' (imported)', 'type' => 'gateway', 'enabled' => false]);
        foreach ($settings as $key => [$value, $encrypted]) {
            $gateway->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => $encrypted]);
        }

        return $gateway;
    }

    private function value(Gateway $gateway, string $key): ?string
    {
        return $gateway->settings()->where('key', $key)->first()?->value;
    }

    public function test_whole_gateways_that_answer_are_enabled_with_their_webhooks_and_the_rest_are_said(): void
    {
        $stripe = $this->gateway('Stripe', ['stripe_secret_key' => ['sk_live_Example1', true], 'stripe_publishable_key' => ['pk_live_Example1', false]]);
        $paypal = $this->gateway('PayPal', ['client_id' => ['ExampleClient', false], 'client_secret' => ['ExampleSecret', true], 'test_mode' => ['0', false]]);
        $bank = $this->gateway('BankTransfer', ['accounts' => ["Example Bank\nIBAN GR16 0110 1250 0000 0001 2300 695", false]]);
        $emptyBank = $this->gateway('BankTransfer');
        $cardlink = $this->gateway('Cardlink');
        Http::fake([
            'https://api.stripe.com/v1/balance' => Http::response(['available' => [], 'pending' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => []]) : Http::response(['id' => 'we_Example1', 'secret' => 'whsec_Example1']),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21Example']),
            'https://api-m.paypal.com/v1/notifications/webhooks' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['webhooks' => [['id' => 'WH-WHMCS', 'url' => 'https://old.example.test/modules/gateways/callback/paypal_ppcpv.php']]])
                : Http::response(['id' => 'WH-Example1']),
        ]);

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])
            ->expectsOutputToContain('Gateway «BankTransfer (imported)» (BankTransfer) left disabled: its accounts are empty.')
            ->expectsOutputToContain('Gateway «Cardlink (imported)» (Cardlink) left disabled: set it up and enable it by hand.')
            ->assertSuccessful();

        $this->assertSame([true, true, true, false, false], array_map(fn (Gateway $g) => (bool) $g->fresh()->enabled, [$stripe, $paypal, $bank, $emptyBank, $cardlink]));
        $this->assertSame('whsec_Example1', $this->value($stripe, 'stripe_webhook_secret'));
        $this->assertSame('WH-Example1', $this->value($paypal, 'webhook_id'));
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api-m.paypal.com/v1/notifications/webhooks') {
                return false;
            }
            $events = array_column($request['event_types'], 'name');

            return $request['url'] === route('extensions.gateways.paypal.webhook') && in_array('CUSTOMER.DISPUTE.CREATED', $events, true)
                && in_array('BILLING.SUBSCRIPTION.CANCELLED', $events, true) && in_array('VAULT.PAYMENT-TOKEN.DELETED', $events, true);
        });
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'WH-WHMCS'));
    }

    public function test_keys_that_do_not_answer_keep_it_disabled_and_paypals_own_webhook_is_reused(): void
    {
        $stripe = $this->gateway('Stripe', ['stripe_secret_key' => ['sk_live_Revoked', true], 'stripe_publishable_key' => ['pk_live_Revoked', false]]);
        $paypal = $this->gateway('PayPal', ['client_id' => ['ExampleClient', false], 'client_secret' => ['ExampleSecret', true], 'test_mode' => ['1', false]]);
        $noKeys = $this->gateway('Stripe');
        Http::fake([
            'https://api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401),
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21Example']),
            'https://api-m.sandbox.paypal.com/v1/notifications/webhooks' => Http::response(['webhooks' => [['id' => 'WH-Ours', 'url' => route('extensions.gateways.paypal.webhook')]]]),
            'https://api-m.sandbox.paypal.com/v1/notifications/webhooks/WH-Ours' => Http::response(['id' => 'WH-Ours']),
        ]);

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])
            ->expectsOutputToContain('Gateway «Stripe (imported)» (Stripe) left disabled: its keys did not come.')
            ->assertFailed();

        $this->assertFalse((bool) $stripe->fresh()->enabled, 'refused keys: never on');
        $this->assertFalse((bool) $noKeys->fresh()->enabled);
        $this->assertTrue((bool) $paypal->fresh()->enabled);
        $this->assertSame('WH-Ours', $this->value($paypal, 'webhook_id'), 'its own, events brought up to date, no second one');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request[0]['path'] === '/event_types');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/notifications/webhooks'));
    }

    public function test_without_yes_it_asks_and_a_no_changes_nothing(): void
    {
        $bank = $this->gateway('BankTransfer', ['accounts' => ['Example Bank', false]]);

        $this->artisan('app:enable-imported-gateways')->expectsConfirmation('Turn on the disabled gateways that are whole and answer, with live charging from this database?', 'no')
            ->assertFailed();

        $this->assertFalse((bool) $bank->fresh()->enabled);
    }
}

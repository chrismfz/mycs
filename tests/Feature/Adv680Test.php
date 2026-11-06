<?php

namespace Tests\Feature;

use App\Models\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Adversarial review of #680 (app:enable-imported-gateways). Each test is a way the command turns on, or keeps on, a
 * gateway that should not take customers' money, or hides that it did not. Fake keys and fake hosts only.
 */
class Adv680Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://clients.example.test');
    }

    /** @param  array<string, array{0: string, 1: bool}>  $settings */
    private function gateway(string $extension, array $settings = [], bool $enabled = false): Gateway
    {
        $gateway = Gateway::query()->create(['extension' => $extension, 'name' => $extension . ' (adv)', 'type' => 'gateway', 'enabled' => $enabled]);
        foreach ($settings as $key => [$value, $encrypted]) {
            $gateway->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => $encrypted]);
        }

        return $gateway;
    }

    private function fakeStripeOk(array $existing = []): void
    {
        Http::fake([
            'https://api.stripe.com/v1/balance' => Http::response(['available' => [], 'pending' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => $existing]) : Http::response(['id' => 'we_New', 'secret' => 'whsec_New']),
            'https://api.stripe.com/v1/webhook_endpoints/*' => Http::response(['deleted' => true]),
        ]);
    }

    private function fakePayPalOk(string $host): void
    {
        Http::fake([
            "https://{$host}/v1/oauth2/token" => Http::response(['access_token' => 'A21Example']),
            "https://{$host}/v1/notifications/webhooks" => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['webhooks' => []]) : Http::response(['id' => 'WH-New']),
        ]);
    }

    /**
     * ADV-680-1: Stripe TEST keys (the importer brings them when WHMCS held test keys, WhmcsCredentialImport says «WHMCS
     * holds TEST keys») are answered by Stripe, so the gateway goes on in production: anyone pays an invoice with a test
     * card (4242…) and it is recorded as paid; the imported cards of live customers never charge.
     */
    public function test_adv_680_1_stripe_test_keys_are_never_enabled_on_a_live_cutover(): void
    {
        $stripe = $this->gateway('Stripe', ['stripe_secret_key' => ['sk_test_Example1', true], 'stripe_publishable_key' => ['pk_test_Example1', false]]);
        $this->fakeStripeOk();

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->run();

        $this->assertFalse((bool) $stripe->fresh()->enabled, 'test keys: the site would record test-card payments as paid');
    }

    /**
     * ADV-680-2: PayPal in SANDBOX (test_mode 1, imported when WHMCS ran useSandbox) is enabled, with a sandbox webhook:
     * invoices are paid with sandbox money (any developer.paypal.com account makes sandbox buyers) and marked paid.
     */
    public function test_adv_680_2_paypal_sandbox_is_never_enabled_on_a_live_cutover(): void
    {
        $paypal = $this->gateway('PayPal', ['client_id' => ['ExampleClient', false], 'client_secret' => ['ExampleSecret', true], 'test_mode' => ['1', false]]);
        $this->fakePayPalOk('api-m.sandbox.paypal.com');

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->run();

        $this->assertFalse((bool) $paypal->fresh()->enabled, 'sandbox: fake money recorded as real payments');
    }

    /**
     * ADV-680-3: a Stripe gateway staff already run (enabled, its webhook made for this address) and a second, disabled
     * Stripe record: the command enables the second, whose «updated» deletes every endpoint of this address, the live
     * gateway's among them. Its payments are no longer recorded (and two Stripe records make the bare webhook throw).
     */
    public function test_adv_680_3_a_second_stripe_never_deletes_the_webhook_of_the_one_staff_run(): void
    {
        $url = route('extensions.gateways.stripe.webhook');
        $this->gateway('Stripe', ['stripe_secret_key' => ['sk_live_StaffOne', true], 'stripe_publishable_key' => ['pk_live_StaffOne', false],
            'stripe_webhook_secret' => ['whsec_Staff', true], 'stripe_webhook_id' => ['we_Staff', false]], enabled: true);
        $second = $this->gateway('Stripe', ['stripe_secret_key' => ['sk_live_Second', true], 'stripe_publishable_key' => ['pk_live_Second', false]]);
        $this->fakeStripeOk([['id' => 'we_Staff', 'url' => $url]]);

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->run();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/webhook_endpoints/we_Staff'));
        $this->assertFalse((bool) $second->fresh()->enabled, 'a second record of an extension: its webhook cannot tell them apart');
    }

    /**
     * ADV-680-4: Stripe answers its keys but refuses the webhook (a restricted key without Webhook Endpoints): the gateway
     * stays off, rightly, but the command exits 0, and the cut-over's script goes on as if Stripe were taking payments.
     */
    public function test_adv_680_4_a_webhook_not_made_fails_the_command(): void
    {
        $this->gateway('Stripe', ['stripe_secret_key' => ['rk_live_NoWebhooks', true], 'stripe_publishable_key' => ['pk_live_NoWebhooks', false]]);
        Http::fake([
            'https://api.stripe.com/v1/balance' => Http::response(['available' => [], 'pending' => []]),
            'https://api.stripe.com/v1/webhook_endpoints' => Http::response(['error' => ['message' => 'The provided key does not have the required permissions']], 403),
        ]);

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])
            ->expectsOutputToContain('left disabled: its webhook was not made')
            ->assertFailed();
    }

    /**
     * ADV-680-5: PayPal answers 2xx to the webhook POST with an empty id: left disabled, exit 0 (same hole as ADV-680-4).
     */
    public function test_adv_680_5_paypal_without_a_webhook_id_fails_the_command(): void
    {
        $this->gateway('PayPal', ['client_id' => ['ExampleClient', false], 'client_secret' => ['ExampleSecret', true], 'test_mode' => ['0', false]]);
        Http::fake([
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21Example']),
            'https://api-m.paypal.com/v1/notifications/webhooks' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['webhooks' => []]) : Http::response(['id' => '']),
        ]);

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])
            ->expectsOutputToContain('PayPal gave no webhook id')
            ->assertFailed();
    }

    /**
     * ADV-680-6: a second run turns back on what staff turned off after the cut-over (an incident, a revoked app): the
     * command takes «disabled» for «imported and never enabled», with nothing that marks which.
     */
    public function test_adv_680_6_a_second_run_never_turns_on_a_gateway_staff_turned_off(): void
    {
        $bank = $this->gateway('BankTransfer', ['accounts' => ["Example Bank\nIBAN GR00 0000 0000 0000 0000 0000 000", false]]);
        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->assertSuccessful();
        $this->assertTrue((bool) $bank->fresh()->enabled);

        $bank->fresh()->forceFill(['enabled' => false])->save(); // staff, later
        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->run();

        $this->assertFalse((bool) $bank->fresh()->enabled, 'staff turned it off: a re-run of the cut-over step must not undo that');
    }

    /**
     * ADV-680-7: the webhooks take whatever address the app has now. Not https (here http://localhost, the default of an
     * unset APP_URL in a console): Stripe's test mode and PayPal's sandbox accept it, a live one points nowhere. The
     * command prints the address and, with --yes, goes on.
     */
    public function test_adv_680_7_no_webhook_is_made_for_an_address_that_is_not_https(): void
    {
        URL::forceRootUrl('http://localhost');
        $paypal = $this->gateway('PayPal', ['client_id' => ['ExampleClient', false], 'client_secret' => ['ExampleSecret', true], 'test_mode' => ['0', false]]);
        $this->fakePayPalOk('api-m.paypal.com');

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->assertFailed();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/notifications/webhooks'));
        $this->assertFalse((bool) $paypal->fresh()->enabled);
    }

    /**
     * ADV-680-8: a live secret key with a test publishable key (typed by hand on the disabled gateway; the importer
     * refuses the pair, the command does not): enabled, and checkout fails for every customer (Stripe.js on test, the
     * intent on live).
     */
    public function test_adv_680_8_keys_of_two_modes_are_never_enabled(): void
    {
        $stripe = $this->gateway('Stripe', ['stripe_secret_key' => ['sk_live_Example1', true], 'stripe_publishable_key' => ['pk_test_Example1', false]]);
        $this->fakeStripeOk();

        $this->artisan('app:enable-imported-gateways', ['--yes' => true])->run();

        $this->assertFalse((bool) $stripe->fresh()->enabled);
    }
}

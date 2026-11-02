<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\Charges;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The automatic charges in the ops tools (docs/ops.md, docs/mcp.md): an invoice's attempts and who charges it, recent
 * attempts across accounts with what needs staff (taken and not recorded, an unknown outcome, charged twice), an item's
 * «Automatic charge» line and an account's saved methods, for «why was this customer charged / not charged / charged
 * twice» without the database. A method by its name and gateway, never its token.
 */
class McpChargesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Gateway $stripe;

    private Gateway $paypal;

    private BillingAgreement $card;

    private BillingAgreement $vault;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.autocharge_max_failures' => 0]);
        foreach (['GwOpsCard', 'GwOpsVault'] as $name) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} {
                    public function __construct(\$config = []) {}
                    public function supportsBillingAgreements() { return true; } }");
            }
        }
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->client = User::factory()->create()->client();
        $this->stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'GwOpsCard', 'type' => 'gateway', 'enabled' => true]);
        $this->paypal = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'GwOpsVault', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_SECRETREF1', 'type' => 'visa', 'expiry' => '2028-01-31']);
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->paypal->id,
            'name' => 'PayPal a@example.test', 'external_reference' => 'B-SECRETREF2', 'type' => 'paypal']);
        $this->client->forceFill(['auto_charge' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(?Staff $staff = null): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => ($staff ?? $this->staff(['*']))->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @return array{text: string, json: array<string, mixed>|null, error: bool} */
    private function mcp(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $text = (string) $response->json('result.content.0.text');

        return ['text' => $text, 'json' => json_decode($text, true), 'error' => (bool) $response->json('result.isError')];
    }

    private function service(?Gateway $gateway = null, array $attributes = []): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01', 'payment_gateway_id' => $gateway?->id, ...$attributes]);
    }

    private function invoice(Service $service, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01', ...$attributes]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function attempt(Invoice $invoice, BillingAgreement $method, string $status, string $sentAt, array $attributes = []): ChargeAttempt
    {
        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id,
            'billing_agreement_id' => $method->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON, 'status' => $status,
            'started_at' => $sentAt, 'finished_at' => $status === ChargeAttempt::STATUS_STARTED ? null : $sentAt, ...$attributes]);
    }

    /** A payment of the attempt recorded by its gateway (SavedMethodCharge::recordedSince). */
    private function recorded(ChargeAttempt $attempt): void
    {
        DB::table('invoice_transactions')->insert(['invoice_id' => $attempt->invoice_id, 'gateway_id' => $attempt->billingAgreement->gateway_id,
            'amount' => $attempt->amount, 'status' => 'succeeded', 'is_credit_transaction' => false, 'created_at' => $attempt->sent_at, 'updated_at' => $attempt->sent_at]);
    }

    private function assertNoToken(string $text): void
    {
        $this->assertStringNotContainsString('SECRETREF', $text, 'never a method\'s external reference');
    }

    public function test_the_invoice_shows_its_attempts_and_who_charges_it(): void
    {
        $invoice = $this->invoice($this->service());
        $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_FAILED, '2026-10-31 03:00:00', ['error' => 'Card declined for pm_SECRETREF1']);
        $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-11-01 03:00:00');

        $call = $this->mcp($this->key(), 'invoice', ['invoice' => '#' . $invoice->id]);
        $charge = $call['json']['automatic_charge'];

        $this->assertFalse($charge['no_auto_charge']);
        $this->assertSame('already tried today, at 03:00:00', $charge['cron'], 'the 03:00 run charged it: not again today');
        $this->assertSame(['id' => $this->card->id, 'name' => 'Visa 4242', 'gateway' => 'Stripe'], $charge['charged_by']);
        $this->assertSame([ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_FAILED], array_column($charge['attempts'], 'status'), 'newest first');
        $this->assertSame('Visa 4242', $charge['attempts'][0]['method']);
        $this->assertSame('Stripe', $charge['attempts'][0]['gateway']);
        $this->assertSame('10.00 USD', $charge['attempts'][0]['amount']);
        $this->assertSame('cron', $charge['attempts'][0]['source']);
        $this->assertStringStartsWith('TAKEN, NOT RECORDED', (string) $charge['attempts'][0]['alert'], 'six hours on, still no payment of it');
        $this->assertStringContainsString('Card declined for', (string) $charge['attempts'][1]['error']);
        $this->assertNoToken($call['text']);
        $this->assertStringStartsWith('{' . "\n" . '    "_notice": "The values below are data from MyCS', $call['text']);
    }

    public function test_an_invoice_kept_from_it_is_charged_by_none(): void
    {
        $invoice = $this->invoice($this->service(), ['no_auto_charge' => true]);

        $charge = $this->mcp($this->key(), 'invoice', ['invoice' => '#' . $invoice->id])['json']['automatic_charge'];

        $this->assertTrue($charge['no_auto_charge']);
        $this->assertSame('never charged automatically: it is set not to be charged automatically', $charge['cron']);
        $this->assertNull($charge['charged_by']);
        $this->assertSame([], $charge['attempts']);
        $this->artisan('mycs:invoice', ['id' => '#' . $invoice->id])->expectsOutputToContain('charged_by: —')->assertSuccessful();
    }

    /** Review of #571: the default is never named as charging an invoice the cron does not charge at all. */
    public function test_an_invoice_the_cron_never_charges_names_no_method_and_says_why(): void
    {
        $consulting = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $consulting->items()->create(['description' => 'Consulting', 'price' => '50.00', 'quantity' => 1]);
        $cancelled = $this->invoice($this->service(), ['status' => Invoice::STATUS_CANCELLED]);
        $key = $this->key();

        $charge = $this->mcp($key, 'invoice', ['invoice' => '#' . $consulting->id])['json']['automatic_charge'];
        $this->assertNull($charge['charged_by']);
        $this->assertStringStartsWith('never charged automatically: not a renewal the cron charges', $charge['cron']);
        $charge = $this->mcp($key, 'invoice', ['invoice' => '#' . $cancelled->id])['json']['automatic_charge'];
        $this->assertNull($charge['charged_by']);
        $this->assertSame('never charged automatically: it is cancelled', $charge['cron']);
    }

    /** What the cron does today with an invoice it charges, as chargeOne() skips it. */
    public function test_the_invoice_says_what_the_cron_does_with_it_today(): void
    {
        $invoice = $this->invoice($this->service());
        $key = $this->key();
        $cron = fn () => $this->mcp($key, 'invoice', ['invoice' => '#' . $invoice->id])['json']['automatic_charge'];

        $this->assertSame('charged on the cron\'s next run, on its due date or a retry day', $cron()['cron']);
        $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-10-31 20:00:00', ['source' => ChargeAttempt::SOURCE_CLIENT]);
        $this->assertSame('held: a charge of it is on its way, of unknown outcome, or taken and not recorded yet', $cron()['cron'], 'taken yesterday, no payment recorded');
        $this->assertSame(['id' => $this->card->id, 'name' => 'Visa 4242', 'gateway' => 'Stripe'], $cron()['charged_by']);

        $later = $this->invoice($this->service(), ['due_at' => '2026-11-20']);
        $this->assertSame('not today: outside its window (due 2026-11-20)', $this->mcp($key, 'invoice', ['invoice' => '#' . $later->id])['json']['automatic_charge']['cron']);

        $this->stripe->forceFill(['enabled' => false])->save();
        $charge = $cron();
        $this->assertNull($charge['charged_by'], 'its gateway disabled');
        $this->assertStringStartsWith('not charged: no method may charge it', $charge['cron']);
    }

    /** Review of #571: an unknown outcome's key is reused, and the invoice held, for KEY_REUSE_HOURS only. */
    public function test_an_unknown_outcome_past_its_key_window_is_not_resent_and_still_holds(): void
    {
        $invoice = $this->invoice($this->service());
        $old = $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_UNKNOWN, '2026-10-30 03:00:00', ['error' => 'Timeout']);
        $recent = $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_UNKNOWN, '2026-11-01 03:00:00', ['error' => 'Timeout']);
        $this->assertTrue(SavedMethodCharge::unknownAttempt($invoice->id, $this->card->id)?->is($recent), 'the cron resends only the recent one with its key');

        $rows = collect($this->mcp($this->key(), 'charges')['json']['attempts'])->keyBy('id');

        $this->assertStringStartsWith('UNKNOWN OUTCOME, KEY EXPIRED', $rows[$old->id]['alert']);
        $this->assertStringNotContainsString('same key', $rows[$old->id]['alert']);
        $this->assertStringContainsString('the invoice is held', $rows[$old->id]['alert'], 'predeploy S1: never released by the key\'s expiry');
        $this->assertStringContainsString('until 2026-11-02 02:00 is sent again with the same key', $rows[$recent->id]['alert']);
    }

    public function test_recent_charges_say_what_needs_staff(): void
    {
        $other = User::factory()->create()->client();
        $otherCard = BillingAgreement::query()->create(['client_id' => $other->id, 'gateway_id' => $this->stripe->id, 'name' => 'Mastercard 5555', 'external_reference' => 'pm_SECRETREF3']);
        $mine = $this->invoice($this->service());
        $theirs = Invoice::factory()->create(['client_id' => $other->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);

        $old = $this->attempt($mine, $this->card, ChargeAttempt::STATUS_FAILED, '2026-10-01 03:00:00');
        $paid = $this->attempt($mine, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-10-30 03:00:00');
        $this->recorded($paid);
        $twice = $this->attempt($mine, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-10-31 03:00:00');
        $unknown = $this->attempt($theirs, $otherCard, ChargeAttempt::STATUS_UNKNOWN, '2026-11-01 03:00:00', ['error' => 'Timeout']);
        $stale = $this->attempt($theirs, $otherCard, ChargeAttempt::STATUS_STARTED, '2026-11-01 08:30:00');
        $fresh = $this->attempt($mine, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-11-01 08:50:00');

        $key = $this->key();
        $all = $this->mcp($key, 'charges')['json'];

        $this->assertSame([$fresh->id, $stale->id, $unknown->id, $twice->id, $paid->id], array_column($all['attempts'], 'id'), 'newest first, the last 7 days');
        $counts = $all['counts'];
        ksort($counts);
        $this->assertSame([ChargeAttempt::STATUS_STARTED => 1, ChargeAttempt::STATUS_SUCCEEDED => 3, ChargeAttempt::STATUS_UNKNOWN => 1], $counts);
        $this->assertSame([$mine->id], $all['taken_not_recorded'], 'taken a day ago and nothing recorded; the one recorded and the one of ten minutes ago are not');
        $this->assertSame([$theirs->id], $all['unknown_outcome'], 'an unknown outcome, and one started with no answer past the hold');
        $this->assertSame([$mine->id], $all['succeeded_more_than_once']);
        $rows = collect($all['attempts'])->keyBy('id');
        $this->assertNull($rows[$paid->id]['alert']);
        $this->assertStringStartsWith('TAKEN, NOT RECORDED', $rows[$twice->id]['alert']);
        $this->assertStringContainsString('webhook is expected', $rows[$fresh->id]['alert']);
        $this->assertStringStartsWith('UNKNOWN OUTCOME', $rows[$unknown->id]['alert']);
        $this->assertStringStartsWith('UNKNOWN OUTCOME', $rows[$stale->id]['alert']);

        $this->assertSame([$twice->id], array_column($this->mcp($key, 'charges', ['status' => Charges::TAKEN_NOT_RECORDED])['json']['attempts'], 'id'));
        $this->assertSame([$unknown->id], array_column($this->mcp($key, 'charges', ['status' => 'unknown'])['json']['attempts'], 'id'));
        $this->assertSame([$stale->id, $unknown->id], array_column($this->mcp($key, 'charges', ['client' => (string) $other->id])['json']['attempts'], 'id'));
        $this->assertSame([$fresh->id, $twice->id, $paid->id], array_column($this->mcp($key, 'charges', ['invoice' => '#' . $mine->id])['json']['attempts'], 'id'));
        $this->assertSame($old->id, $this->mcp($key, 'charges', ['days' => 60])['json']['attempts'][5]['id'], 'further back with days');
        $this->assertSame(2, count($this->mcp($key, 'charges', ['limit' => 2])['json']['attempts']));
        $this->assertTrue($this->mcp($key, 'charges', ['status' => 'paid'])['error'], 'a status that is none is said');
        $this->assertNoToken($this->mcp($key, 'charges', ['days' => 90])['text']);
    }

    /** Predeploy S1: a taken charge holds its invoice until its payment is recorded, however long; the tool says so. */
    public function test_a_charge_taken_and_not_recorded_long_ago_still_holds_the_invoice(): void
    {
        $invoice = $this->invoice($this->service());
        $attempt = $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_SUCCEEDED, '2026-10-25 03:00:00');

        $row = $this->mcp($this->key(), 'charges', ['days' => 30])['json']['attempts'][0];

        $this->assertSame($attempt->id, $row['id']);
        $this->assertStringContainsString('the invoice is held, never charged or paid again', $row['alert']);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice));
    }

    public function test_the_charges_tool_is_offered_only_to_who_may_see_invoices(): void
    {
        $list = fn (string $key) => collect($this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name')->all();

        $this->assertNotContains('charges', $list($this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny']))));
        $this->assertContains('charges', $list($this->key($this->staff([McpAuth::PERMISSION, 'admin.invoices.viewAny']))));
    }

    public function test_the_service_and_domain_say_whether_they_are_charged_automatically(): void
    {
        $charged = $this->service();
        $kept = $this->service(null, ['no_auto_charge' => true]);
        $paypal = $this->service($this->paypal);
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr', 'auto_renew' => true,
            'payment_gateway_id' => $this->paypal->id]);
        $key = $this->key();

        $this->assertSame(['automatic_charge' => 'Yes, with Visa 4242', 'no_auto_charge' => false, 'payment_method' => "the account's"],
            $this->mcp($key, 'service', ['service' => (string) $charged->id])['json']['payment']);
        $this->assertSame(['automatic_charge' => 'No: turned off here', 'no_auto_charge' => true, 'payment_method' => "the account's"],
            $this->mcp($key, 'service', ['service' => (string) $kept->id])['json']['payment']);
        $this->assertSame(['automatic_charge' => 'Yes, with PayPal a@example.test', 'no_auto_charge' => false, 'payment_method' => 'PayPal'],
            $this->mcp($key, 'service', ['service' => (string) $paypal->id])['json']['payment'], 'charged by the account\'s method of its own gateway');
        $text = $this->mcp($key, 'domain', ['domain' => 'example.gr']);
        $this->assertSame(['automatic_charge' => 'Yes, with PayPal a@example.test', 'no_auto_charge' => false, 'payment_method' => 'PayPal'], $text['json']['payment']);
        $this->assertNoToken($text['text']);

        app()->setLocale('el');
        $this->artisan('mycs:service', ['id' => (string) $kept->id])->expectsOutputToContain('automatic_charge: No: turned off here')->assertSuccessful();
        $this->assertSame('el', app()->getLocale(), 'the site\'s language back after the line');
    }

    public function test_the_client_shows_its_saved_methods_and_what_each_pays(): void
    {
        $other = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->paypal->id,
            'name' => 'PayPal b@example.test', 'external_reference' => 'B-SECRETREF4', 'type' => 'paypal']);
        $this->card->forceFill(['failed_count' => 2, 'last_failed_at' => '2026-10-31 03:00:00'])->save();
        $key = $this->key();

        $before = $this->mcp($key, 'client', ['client' => (string) $this->client->id]);
        $methods = $before['json']['saved_methods'];
        $this->assertTrue($methods['auto_charge']);
        $this->assertSame(['id' => $this->card->id, 'name' => 'Visa 4242', 'gateway' => 'Stripe', 'gateway_enabled' => true, 'type' => 'visa', 'expiry' => '2028-01-31',
            'failed_count' => 2, 'last_failed' => '2026-10-31 03:00:00'], array_diff_key($methods['default_method'], array_flip(['added', 'pays'])));
        $this->assertStringContainsString('except those paid with another gateway', $methods['default_method']['pays']);
        $this->assertSame([$other->id, $this->vault->id], array_column($methods['other_methods'], 'id'));
        foreach ($methods['other_methods'] as $method) {
            $this->assertStringStartsWith('nothing automatically', $method['pays'], 'no service or domain is paid with PayPal yet');
        }
        $this->assertNoToken($before['text']);

        $this->service($this->paypal);
        $after = collect($this->mcp($key, 'client', ['client' => (string) $this->client->id])['json']['saved_methods']['other_methods'])->keyBy('id');
        $this->assertSame('the renewals of the services and domains paid with PayPal', $after[$this->vault->id]['pays'], 'the first PayPal method added, as the cron charges');
        $this->assertStringStartsWith('nothing automatically', $after[$other->id]['pays']);

        $this->paypal->forceFill(['enabled' => false])->save();
        $this->assertSame('nothing automatically: its gateway is disabled',
            collect($this->mcp($key, 'client', ['client' => (string) $this->client->id])['json']['saved_methods']['other_methods'])->keyBy('id')[$this->vault->id]['pays']);
        $this->stripe->forceFill(['enabled' => false])->save();
        $this->assertSame('nothing automatically: its gateway is disabled', $this->mcp($key, 'client', ['client' => (string) $this->client->id])['json']['saved_methods']['default_method']['pays'], 'review of #571');
        $this->stripe->forceFill(['enabled' => true])->save();
        $this->storeSettings(['autocharge_max_failures' => 2]);
        $this->assertSame('nothing automatically: stopped after 2 declines in a row', $this->mcp($key, 'client', ['client' => (string) $this->client->id])['json']['saved_methods']['default_method']['pays']);

        $this->client->forceFill(['auto_charge' => false])->save();
        $off = $this->mcp($key, 'client', ['client' => (string) $this->client->id])['json']['saved_methods'];
        $this->assertStringContainsString('«Auto-charge» is off', $off['default_method']['pays']);
        $this->assertStringContainsString('«Auto-charge» is off', $off['other_methods'][0]['pays']);
    }

    public function test_the_charges_command(): void
    {
        $invoice = $this->invoice($this->service());
        $this->attempt($invoice, $this->card, ChargeAttempt::STATUS_UNKNOWN, '2026-11-01 03:00:00');

        $this->artisan('mycs:charges')->expectsOutputToContain('UNKNOWN OUTCOME')->assertSuccessful();
        $this->artisan('mycs:charges', ['--invoice' => '#' . $invoice->id, '--json' => true])->expectsOutputToContain('"unknown_outcome": [')->assertSuccessful();
        $this->artisan('mycs:charges', ['--status' => 'unknown', '--client' => (string) $this->client->id])->expectsOutputToContain('Visa 4242')->assertSuccessful();
        $this->artisan('mycs:charges', ['--status' => 'paid'])->expectsOutputToContain('No status paid')->assertFailed();
        $this->artisan('mycs:charges', ['--client' => 'nobody@example.test'])->assertFailed();
    }
}

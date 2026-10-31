<?php

namespace Tests\Feature;

use App\Console\Commands\Simulate;
use App\Enums\DomainStatus;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Simulation\Ledger;
use App\Services\Simulation\SimPay;
use App\Services\Simulation\SimRegistrar;
use App\Services\Simulation\SimRegistry;
use App\Services\Simulation\SimServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Adversarial review of #566 (mycs:simulate). Invented data. */
class SimulateAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->csv = sys_get_temp_dir() . '/adv-ledger-' . uniqid() . '.csv';
        Simulate::$networkProbe = fn () => false;
        Simulate::$isCopy = fn () => true; // a copy, as bin/simulate names it (the test of the refusal resets it)
        SimServer::$calls = [];
        SimServer::$state = [];
        SimPay::$charges = [];
        SimPay::$declined = [];
        SimRegistrar::$calls = [];
    }

    protected function tearDown(): void
    {
        Simulate::$networkProbe = null;
        Simulate::$isCopy = null;
        Carbon::setTestNow();
        @unlink($this->csv);
        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private function ledger(): array
    {
        $rows = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES));
        $head = array_shift($rows);

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    private function db(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    private function domainWithPaidRenewal(string $registrar, string $tld): Domain
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake(); // the payment's renewal job is "lost": the cron's domains_renewed step picks the line up
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $connection = DomainRegistrarConnection::query()->create(['registrar' => $registrar, 'label' => 'R', 'is_active' => true, 'mode' => 'production']);
        $user = User::factory()->create();
        $tldRow = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([1 => '23.33', 2 => '46.66'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tldRow->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'advtest', 'tld' => $tld, 'fqdn' => "advtest.{$tld}",
            'domain_tld_id' => $tldRow->id, 'registrar_connection_id' => $connection->id, 'registrar_domain_id' => '777', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'adv-tx-' . $line->id);

        return $domain;
    }

    /**
     * ADV-566-1: a paid domain renewal is the cron's job (domains_renewed); in the simulation the registrar stand-in must be
     * asked to renew it, and the ledger must say so. SimRegistrar::syncDomain answers with no status, so the renewal
     * service sees «not active» and hands the line to staff: the ledger shows no renewal at all and looks fine.
     */
    public function test_a_paid_domain_renewal_reaches_the_registrar_stand_in_and_the_ledger(): void
    {
        $domain = $this->domainWithPaidRenewal('openprovider', 'com');

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--from' => '2026-10-21', '--days' => 2, '--out' => $this->csv])->assertSuccessful();

        $asked = collect($this->ledger())->where('event', 'registrar asked')->pluck('ref')->all();
        $this->assertTrue(collect($asked)->contains(fn (string $r) => str_starts_with($r, "renew {$domain->fqdn}")), 'the paid renewal is asked of the registrar; asked: ' . json_encode($asked));
    }

    /**
     * ADV-566-2: every registrar key becomes the same SimRegistrar with DomainRegistrarCapabilities::none(). The real
     * adapters differ in what changes the cron's decisions: the .gr registry bills and renews in steps of 2 years and renews
     * in its grace, CentralNic expires when the name goes. The simulation bills and decides for them as if they were plain.
     */
    public function test_the_stand_in_registrar_has_the_capabilities_of_the_registrar_it_replaces(): void
    {
        $real = app(DomainRegistrarRegistry::class);
        $sim = new SimRegistry;
        $differ = [];
        foreach ($real->keys() as $key) {
            if ($real->for($key)->capabilities() != $sim->for($key)->capabilities()) {
                $differ[] = $key;
            }
        }
        $this->assertSame([], $differ, 'registrars whose capabilities (renewYearStep, renewsInGrace, supportsRenewal, expiryIsWhenItGoes...) the simulation changes');
    }

    /**
     * ADV-566-3: the guard is «the database typed is the database configured» plus «two TCP probes fail». Run without
     * bin/simulate (a host that filters 1.1.1.1/8.8.8.8 but not SMTP/HTTPS, a firewall allow-list) nothing forces the sync
     * queue: the cron's mails and server jobs are queued in the database the site serves, and the live worker sends them.
     * The command must refuse unless the queue is sync (what bin/simulate sets) and the database is a copy.
     */
    public function test_it_refuses_to_run_with_a_real_queue_even_when_the_database_is_typed(): void
    {
        config(['queue.default' => 'database']);
        $before = DB::table('extensions')->count();

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--days' => 1])->assertFailed();

        $this->assertSame(0, DB::table('jobs')->count(), 'nothing was queued for a worker');
        $this->assertSame($before, DB::table('extensions')->count());
    }

    /** ADV-566-3b: same guard; the database name carries no mark of being a copy (bin/simulate always names it <db>_sim). */
    public function test_it_refuses_a_database_that_is_not_a_simulation_copy(): void
    {
        Simulate::$isCopy = null; // the default rule
        $name = $this->db();
        $this->assertStringEndsNotWith('_sim', $name, 'precondition: a live-looking name');

        $this->artisan('mycs:simulate', ['--confirm-database' => $name, '--days' => 1])->assertFailed();
    }

    /**
     * ADV-566-4: the ledger reads statuses at the end of each day. An invoice the cron makes and credit pays at once (the
     * same step) is listed as «invoice created» and nothing else: no paid event, no credit used, so a day of renewals
     * paid from credit looks like a day of unpaid invoices.
     */
    public function test_an_invoice_paid_from_credit_the_day_it_is_made_is_in_the_ledger_as_paid(): void
    {
        Queue::fake();
        foreach (['credits_enabled' => '1', 'credits_auto_use' => '1'] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => 'boolean']);
        }
        SettingsProvider::flushCache();
        $server = Server::query()->create(['name' => 'S', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create()->client();
        Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => 100]);
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1, 'expires_at' => '2026-11-03 00:00:00']);

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--from' => '2026-11-01', '--days' => 1, '--out' => $this->csv])->assertSuccessful();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status, 'precondition: credit paid it');
        $rows = collect($this->ledger());
        $this->assertTrue($rows->contains(fn (array $r) => $r['ref'] === "invoice #{$invoice->id}" && (str_contains($r['detail'], 'paid') || str_contains($r['event'], 'paid') || str_contains($r['event'], 'credit'))),
            'the ledger says the invoice was paid / credit used; it says: ' . json_encode($rows->where('ref', "invoice #{$invoice->id}")->values()->all()));
    }

    /**
     * ADV-566-5: a server without an API (OfflineVm, OfflineDedicated, OfflineSsl) is a staff task in the real cron (a
     * Pending Module Action and a mail to staff, ExtensionHelper::serverAction). The swap turns every server row into
     * SimServer, so the simulation suspends it «on the server» and closes the action: staff's manual work is invisible.
     */
    public function test_a_server_without_an_api_stays_a_staff_task_in_the_simulation(): void
    {
        $server = Server::query()->create(['name' => 'Rack', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1,
            'expires_at' => '2026-10-17 00:00:00']);

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--from' => '2026-11-01', '--days' => 1, '--out' => $this->csv])->assertSuccessful();

        $rows = collect($this->ledger());
        $this->assertFalse($rows->contains(fn (array $r) => $r['event'] === 'server asked' && str_contains($r['ref'], "#{$service->id}")),
            'an offline server is never asked: staff do it by hand; the ledger says: ' . json_encode($rows->values()->all()));
    }

    /**
     * ADV-566-6: SimPay names its payments «sim_ch_<n>» with n = the charges of the day (Ledger::forget empties the list
     * before each day). The same customer charged on two different days gets the same transaction id twice; the second is
     * a «replay» for RecordPaymentService, no payment is recorded, SimPay answers true: the ledger says «card charged»
     * and the invoice stays unpaid, to be suspended later.
     */
    public function test_a_customer_charged_on_two_days_has_both_invoices_paid(): void
    {
        $server = Server::query()->create(['name' => 'S', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create()->client();
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa', 'external_reference' => 'pm_x', 'type' => 'card']);
        $client->refresh();
        $client->forceFill(['auto_charge' => true])->save();
        foreach (['2026-11-02 00:00:00', '2026-11-04 00:00:00'] as $due) {
            Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1, 'expires_at' => $due]);
        }

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--from' => '2026-11-01', '--days' => 6, '--out' => $this->csv])->assertSuccessful();

        $this->assertSame(2, collect($this->ledger())->where('event', 'card charged')->count(), 'precondition: two charges');
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count(), 'both charged invoices are paid');
    }

    /**
     * ADV-566-7: the swap makes every gateway SimPay and enabled, and SimPay charges any saved method. In the real cron a
     * method of a gateway staff disabled is not charged (ExtensionHelper::gatewayEnabled), nor one of a gateway with no
     * charge() (Cardlink, Mollie, bank transfer: only Stripe and PayPal charge). The summary's «cards charged» then
     * counts customers who would be left to pay by hand.
     */
    public function test_a_saved_method_of_a_disabled_gateway_is_not_charged_by_the_simulation(): void
    {
        $server = Server::query()->create(['name' => 'S', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $gateway = Gateway::query()->create(['name' => 'Stripe (disabled by staff)', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create()->client();
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa', 'external_reference' => 'pm_y', 'type' => 'card']);
        $client->refresh();
        $client->forceFill(['auto_charge' => true])->save();
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1, 'expires_at' => '2026-11-02 00:00:00']);

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->db(), '--from' => '2026-11-01', '--days' => 3, '--keep-gateways' => true, '--out' => $this->csv])->assertSuccessful();

        $this->assertSame(0, collect($this->ledger())->where('event', 'card charged')->count(), 'the real cron does not charge a method of a disabled gateway');
    }
}

<?php

namespace Tests\Feature;

use App\Console\Commands\Simulate;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Simulation\SimPay;
use App\Services\Simulation\SimServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `mycs:simulate` (ROADMAP 9β, 2): the real cron, day by day, on a copy, with servers, gateways and registrars replaced. It
 * must refuse where it could reach anything, and tell what the cron did. Invented data.
 */
class SimulateTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csv = sys_get_temp_dir() . '/ledger-' . uniqid() . '.csv';
        Simulate::$networkProbe = fn () => false; // closed, as bin/simulate makes it
        Simulate::$isCopy = fn () => true; // a copy, as bin/simulate names it
        SimServer::$calls = [];
        SimServer::$state = [];
        SimPay::$charges = [];
        SimPay::$declined = [];
    }

    protected function tearDown(): void
    {
        Simulate::$networkProbe = null;
        Simulate::$isCopy = null;
        Carbon::setTestNow();
        @unlink($this->csv);
        parent::tearDown();
    }

    private function database(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /** @return list<array<string, string>> the ledger */
    private function ledger(): array
    {
        $rows = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES));
        $head = array_shift($rows);

        return array_map(fn (array $row) => array_combine($head, $row), $rows);
    }

    public function test_it_does_not_run_without_the_database_typed(): void
    {
        $this->artisan('mycs:simulate', ['--days' => 1])->expectsOutputToContain('Type the database name to confirm')->assertFailed();
    }

    public function test_it_does_not_run_on_a_database_that_is_not_a_copy_nor_with_a_real_queue(): void
    {
        Simulate::$isCopy = null; // the default: the name must end in «_sim»

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->database(), '--days' => 1])->expectsOutputToContain('is not a copy made for the simulation')->assertFailed();

        Simulate::$isCopy = fn () => true;
        config(['queue.default' => 'database']);
        $this->artisan('mycs:simulate', ['--confirm-database' => $this->database(), '--days' => 1])->expectsOutputToContain('not «sync»')->assertFailed();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_it_does_not_run_where_the_network_is_open(): void
    {
        Simulate::$networkProbe = fn () => true;

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->database(), '--days' => 1])->expectsOutputToContain('The network is open')->assertFailed();

        $this->assertSame(0, DB::table('extensions')->where('extension', 'MycsSimServer')->count(), 'nothing was changed');
    }

    public function test_the_cron_runs_day_by_day_and_the_ledger_tells_what_it_did(): void
    {
        $server = Server::query()->create(['name' => 'Real cPanel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $new = fn (array $attributes) => Service::factory()->create(array_merge(['plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1], $attributes));
        // A customer with a saved card, due on the second day; another with nothing saved, 15 days overdue.
        $carded = User::factory()->create()->client();
        BillingAgreement::query()->create(['client_id' => $carded->id, 'gateway_id' => $gateway->id, 'name' => 'Visa', 'external_reference' => 'pm_1', 'type' => 'card']);
        $carded->refresh();
        $carded->forceFill(['auto_charge' => true])->save();
        $new(['client_id' => $carded->id, 'expires_at' => '2026-11-02 00:00:00']);
        $overdue = $new(['client_id' => User::factory()->create()->client()->id, 'expires_at' => '2026-10-17 00:00:00']);

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->database(), '--from' => '2026-11-01', '--days' => 3, '--out' => $this->csv])->assertSuccessful();

        $events = array_map(fn (array $row) => "{$row['day']} {$row['event']} {$row['ref']}", $this->ledger());
        $this->assertContains("2026-11-01 server asked suspend #{$overdue->id}", $events, 'the overdue service is suspended, by the stand-in');
        $this->assertContains("2026-11-01 service status service #{$overdue->id}", $events);
        $this->assertTrue(collect($this->ledger())->contains(fn (array $row) => $row['event'] === 'invoice created' && str_contains($row['detail'], "client #{$carded->id}")), 'the due service is invoiced');
        $this->assertSame(['MycsSimServer', 'MycsSimPay'], [DB::table('extensions')->where('id', $server->id)->value('extension'), DB::table('extensions')->where('id', $gateway->id)->value('extension')],
            'the real extensions are swapped for the stand-ins in this database');
        $this->assertTrue((bool) DB::table('extensions')->where('id', $gateway->id)->value('enabled'));
        $this->assertSame(1, collect($this->ledger())->where('event', 'card charged')->count(), 'the saved card is charged once, by the stand-in');
    }

    public function test_a_declined_card_is_declined_and_the_service_stays_unpaid(): void
    {
        $server = Server::query()->create(['name' => 'Real cPanel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => false]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create()->client();
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa', 'external_reference' => 'pm_2', 'type' => 'card']);
        $client->refresh();
        $client->forceFill(['auto_charge' => true])->save();
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'server_id' => $server->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.0, 'quantity' => 1, 'expires_at' => '2026-11-02 00:00:00']);

        $this->artisan('mycs:simulate', ['--confirm-database' => $this->database(), '--from' => '2026-11-01', '--days' => 2, '--decline-clients' => (string) $client->id, '--out' => $this->csv])->assertSuccessful();

        $events = array_column($this->ledger(), 'event');
        fwrite(STDERR, 'LEDGER ' . json_encode($this->ledger()) . "\n");
        $this->assertContains('card declined', $events);
        $this->assertNotContains('card charged', $events);
    }
}

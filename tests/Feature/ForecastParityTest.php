<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Console\Commands\WhmcsForecastParity as ForecastCommand;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsForecastParity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `mycs:forecast-parity` (ROADMAP 9β, 1): what WHMCS and this instance will do with each live service, side by side.
 * Invented data.
 */
class ForecastParityTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60]);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('AutoSuspension', 'on'), ('AutoSuspensionDays', '8'), ('AutoTermination', 'on'), ('AutoTerminationDays', '60'), ('CreateInvoiceDaysBefore', '7')",
            'CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, gateway_name TEXT, deleted_at TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, billingcycle TEXT, amount REAL, nextduedate TEXT, nextinvoicedate TEXT,
                paymentmethod TEXT, subscriptionid TEXT, overideautosuspend INT, overidesuspenduntil TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    /** One service in both: due 2026-11-20, 10.00 a month. */
    private function service(array $whmcs = [], array $ours = []): Service
    {
        $client = User::factory()->create()->client();
        $plan = new Plan;
        $plan->forceFill(['name' => 'Monthly', 'priceable_type' => Product::class, 'priceable_id' => Product::factory()->create()->id,
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month'])->save();
        $service = Service::factory()->create(array_merge(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE, 'plan_id' => $plan->id,
            'price' => 10.0, 'expires_at' => '2026-11-20 00:00:00'], $ours));
        $row = array_merge(['id' => $service->id, 'userid' => $client->id, 'domainstatus' => 'Active', 'billingcycle' => 'Monthly', 'amount' => 10.0,
            'nextduedate' => '2026-11-20', 'nextinvoicedate' => '2026-11-20', 'paymentmethod' => 'stripe', 'subscriptionid' => '', 'overideautosuspend' => 0,
            'overidesuspenduntil' => '0000-00-00'], $whmcs);
        $this->whmcs->prepare('INSERT INTO tblhosting VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

        return $service;
    }

    /** @return array<string, list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}>> the differences by field */
    private function differences(): array
    {
        $out = [];
        foreach ((new WhmcsForecastParity($this->whmcs))->run()['differences'] as $d) {
            $out[$d['field']][] = $d;
        }

        return $out;
    }

    public function test_a_service_that_will_do_the_same_differs_only_by_the_day_after_in_suspension_and_termination(): void
    {
        $this->service();

        $differences = $this->differences();

        $this->assertSame(['suspended on', 'terminated on'], array_keys($differences), 'everything else is the same');
        $this->assertSame(['2026-11-28', '2026-11-29'], [$differences['suspended on'][0]['whmcs'], $differences['suspended on'][0]['mycs']]);
        $this->assertSame(['2027-01-19', '2027-01-20'], [$differences['terminated on'][0]['whmcs'], $differences['terminated on'][0]['mycs']]);
    }

    /** The day this report says the cron suspends is the one it suspends: it is pinned to CronJob::suspendable(). */
    public function test_the_day_after_is_the_day_the_cron_suspends(): void
    {
        $service = $this->service();
        $cron = app(CronJob::class);
        $suspendable = new ReflectionMethod($cron, 'suspendable');
        $first = null;
        foreach (['2026-11-27', '2026-11-28', '2026-11-29', '2026-11-30'] as $day) {
            Carbon::setTestNow("{$day} 09:00:00");
            if ($suspendable->invoke($cron)->whereKey($service->id)->exists()) {
                $first = $day;
                break;
            }
        }
        Carbon::setTestNow();

        $this->assertSame($this->differences()['suspended on'][0]['mycs'], $first);
    }

    public function test_an_amount_a_cycle_a_due_date_and_an_invoicing_day_that_differ_are_listed(): void
    {
        $this->service(['amount' => 12.0, 'billingcycle' => 'Annually', 'nextduedate' => '2026-11-22', 'nextinvoicedate' => '2026-11-22']);

        $differences = $this->differences();

        $this->assertArrayHasKey('renewal amount', $differences);
        $this->assertArrayHasKey('cycle', $differences);
        $this->assertArrayHasKey('next due date', $differences);
        $this->assertSame('2026-11-15', $differences['invoiced on'][0]['whmcs']);
        $this->assertSame('2026-11-13', $differences['invoiced on'][0]['mycs']);
    }

    public function test_a_hold_in_whmcs_and_none_here_is_a_difference_and_stops_the_suspension_forecast_there(): void
    {
        $this->service(['overideautosuspend' => 1, 'overidesuspenduntil' => '2026-12-31']);

        $differences = $this->differences();

        $this->assertSame(['2026-12-31', 'none'], [$differences['suspension hold'][0]['whmcs'], $differences['suspension hold'][0]['mycs']]);
        $this->assertSame('never', $differences['suspended on'][0]['whmcs']);
    }

    public function test_a_service_missing_here_is_a_difference(): void
    {
        $this->service();
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (99999, 1, 'Active', 'Monthly', 5, '2026-12-01', '2026-12-01', 'stripe', '', 0, '0000-00-00')");

        $this->assertArrayHasKey('exists', $this->differences());
    }

    public function test_charging_by_a_saved_method_is_compared_and_a_gateway_subscription_is_not_a_saved_method(): void
    {
        $paid = $this->service();
        $subscribed = $this->service(['paymentmethod' => 'paypal_ppcpv', 'subscriptionid' => 'I-ABC']);
        $this->whmcs->exec("INSERT INTO tblpaymethods VALUES (1, {$paid->client_id}, 'stripe', NULL), (2, {$subscribed->client_id}, 'paypal_ppcpv', NULL)");

        $differences = $this->differences();

        // Here nothing is saved: WHMCS charges the first by its saved method and not the second (its subscription pays it).
        $this->assertSame([[$paid->id, 'yes', 'no']], array_map(fn (array $d) => [$d['id'], $d['whmcs'], $d['mycs']], $differences['charged by a saved method']));
    }

    public function test_the_command_prints_a_summary_and_fails_while_anything_differs(): void
    {
        $this->service();
        ForecastCommand::$connect = fn () => $this->whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=not-asked');

        try {
            $this->artisan('mycs:forecast-parity', ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'])
                ->expectsOutputToContain('suspended on')
                ->assertFailed();
        } finally {
            putenv('WHMCS_SOURCE_PASSWORD');
            ForecastCommand::$connect = null;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}

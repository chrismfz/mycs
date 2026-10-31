<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsForecastParity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #565 (`mycs:forecast-parity`): each test is a place where the report says «same» (or says nothing)
 * and the two systems will not behave the same. Invented data. Each asserts what the report SHOULD say.
 */
class ForecastParityAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60,
            'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_terminate_enabled' => true]);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('AutoSuspension', 'on'), ('AutoSuspensionDays', '8'), ('AutoTermination', 'on'), ('AutoTerminationDays', '60'), ('CreateInvoiceDaysBefore', '7'), ('CreateDomainInvoiceDaysBefore', '15')",
            'CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, gateway_name TEXT, deleted_at TEXT)',
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, groupid INT)',
            'CREATE TABLE tblclientgroups (id INTEGER PRIMARY KEY, susptermexempt TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, billingcycle TEXT, amount REAL, nextduedate TEXT, nextinvoicedate TEXT,
                paymentmethod TEXT, subscriptionid TEXT, overideautosuspend INT, overidesuspenduntil TEXT, qty INT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, expirydate TEXT, nextduedate TEXT, nextinvoicedate TEXT, recurringamount REAL,
                donotrenew INT, paymentmethod TEXT, subscriptionid TEXT, registrationperiod INT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $whmcs = [], array $ours = [], array $clientAttrs = []): Service
    {
        $client = User::factory()->create()->client();
        if ($clientAttrs !== []) {
            $client->forceFill($clientAttrs)->save();
        }
        $plan = new Plan;
        $plan->forceFill(['name' => 'Monthly', 'priceable_type' => Product::class, 'priceable_id' => Product::factory()->create()->id,
            'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month'])->save();
        $service = Service::factory()->create(array_merge(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE, 'plan_id' => $plan->id,
            'price' => 10.0, 'expires_at' => '2026-11-20 00:00:00'], $ours));
        $row = array_merge(['id' => $service->id, 'userid' => $client->id, 'domainstatus' => 'Active', 'billingcycle' => 'Monthly', 'amount' => 10.0,
            'nextduedate' => '2026-11-20', 'nextinvoicedate' => '2026-11-20', 'paymentmethod' => 'stripe', 'subscriptionid' => '', 'overideautosuspend' => 0,
            'overidesuspenduntil' => '0000-00-00', 'qty' => 1], $whmcs);
        $cols = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tblhosting ({$cols}) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

        return $service;
    }

    private function domain(array $whmcs = [], array $ours = []): Domain
    {
        $client = User::factory()->create()->client();
        $domain = Domain::factory()->create(array_merge(['client_id' => $client->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-12-20',
            'recurring_amount' => '12.00', 'auto_renew' => true, 'currency_code' => 'USD'], $ours));
        $row = array_merge(['id' => $domain->id, 'userid' => $client->id, 'status' => 'Active', 'expirydate' => '2026-12-20', 'nextduedate' => '2026-12-20',
            'nextinvoicedate' => '2026-12-05', 'recurringamount' => 12.0, 'donotrenew' => 0, 'paymentmethod' => 'stripe', 'subscriptionid' => '',
            'registrationperiod' => 1], $whmcs);
        $cols = implode(', ', array_keys($row));
        $this->whmcs->prepare("INSERT INTO tbldomains ({$cols}) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

        return $domain;
    }

    /** @return array<string, list<array{kind: string, id: int, field: string, whmcs: string, mycs: string}>> */
    private function differences(): array
    {
        $out = [];
        foreach ((new WhmcsForecastParity($this->whmcs))->run()['differences'] as $d) {
            $out[$d['field']][] = $d;
        }

        return $out;
    }

    /** ADV-565-1: the cron's own «Enable Suspension / Termination» is off here, it never suspends, and the report names a day. */
    public function test_adv_565_1_suspension_and_termination_switched_off_here_are_forecast_as_never(): void
    {
        config(['settings.cronjob_suspend_enabled' => false, 'settings.cronjob_terminate_enabled' => false]);
        $this->service();

        $differences = $this->differences();

        $this->assertSame('never', $differences['suspended on'][0]['mycs'] ?? null, 'CronJob::suspendServices returns at once when cronjob_suspend_enabled is off');
        $this->assertSame('never', $differences['terminated on'][0]['mycs'] ?? null, 'CronJob::terminateServices likewise');
    }

    /** ADV-565-2: a client (or group) exempt from dunning is never suspended or terminated here (CronJob::suspendable/terminable). */
    public function test_adv_565_2_a_dunning_exempt_client_is_forecast_as_never_suspended_here(): void
    {
        $this->service([], [], ['dunning_exempt' => true]);

        $differences = $this->differences();

        $this->assertSame('never', $differences['suspended on'][0]['mycs'] ?? null);
        $this->assertSame('never', $differences['terminated on'][0]['mycs'] ?? null);
    }

    /** ADV-565-3: WHMCS's client group «Exempt from Suspend/Terminate» (susptermexempt, imported as dunning_exempt): WHMCS never acts. */
    public function test_adv_565_3_a_client_group_exempt_in_whmcs_is_forecast_as_never_suspended_there(): void
    {
        $service = $this->service();
        $this->whmcs->exec("INSERT INTO tblclientgroups VALUES (7, 'on')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$service->client_id}, 7)");

        $differences = $this->differences();

        $this->assertSame('never', $differences['suspended on'][0]['whmcs'] ?? null);
        $this->assertSame('never', $differences['terminated on'][0]['whmcs'] ?? null);
    }

    /** ADV-565-4: the invoice line is price × quantity (RenewalBilling::billRenewal); the report compares the unit price only. */
    public function test_adv_565_4_a_different_quantity_is_a_different_renewal_amount(): void
    {
        $service = $this->service(['qty' => 1], ['quantity' => 3]);
        $billed = (new RenewalBilling)->billRenewal($service->id, Carbon::parse('2026-12-01'));
        $this->assertSame(1, $billed);
        $line = $service->invoiceItems()->first();
        $this->assertSame(30.0, round((float) $line->price * (int) $line->quantity, 2), 'the customer is billed 3 × 10.00 here, 1 × 10.00 in WHMCS');

        $this->assertArrayHasKey('renewal amount', $this->differences(), 'the report said «same»');
    }

    /** ADV-565-5: suspensions and terminations wait for the dunning window's next open day (DunningWindow); the report ignores it. */
    public function test_adv_565_5_the_dunning_window_moves_the_day_the_cron_suspends(): void
    {
        // 2026-11-29 is a Sunday, 2027-01-20 a Wednesday.
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5]]);
        $this->service();

        $differences = $this->differences();

        $this->assertSame('2026-11-30', $differences['suspended on'][0]['mycs'] ?? null, 'a Sunday suspension waits for Monday');
    }

    /** ADV-565-6: a WHMCS service that is Terminated/Cancelled but still live here (billed, suspended here) is not even looked at. */
    public function test_adv_565_6_a_service_ended_in_whmcs_and_active_here_is_a_difference(): void
    {
        $service = $this->service(['domainstatus' => 'Terminated']);

        $differences = $this->differences();

        $this->assertSame([$service->id], array_map(fn (array $d) => $d['id'], $differences['status'] ?? []));
    }

    /** ADV-565-7: a service suspended by hand here (no dunning_suspended_at) is never terminated by the cron (terminable()). */
    public function test_adv_565_7_a_manually_suspended_service_is_forecast_as_never_terminated_here(): void
    {
        $this->service(['domainstatus' => 'Suspended'], ['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => null]);

        $differences = $this->differences();

        $this->assertSame('never', $differences['terminated on'][0]['mycs'] ?? null, 'terminable() needs dunning_suspended_at');
    }

    /** ADV-565-8: «Bill domain renewals» off here (domain_billing_enabled): the cron invoices no domain, the report names a day. */
    public function test_adv_565_8_domain_billing_switched_off_here_is_forecast_as_never_invoiced(): void
    {
        config(['settings.domain_billing_enabled' => false, 'settings.domain_invoice_days' => 15]);
        $this->domain();

        $differences = $this->differences();

        $this->assertSame('never', $differences['invoiced on'][0]['mycs'] ?? null);
    }

    /** ADV-565-9: a domain's line is OrderPricing::toNet(renewal price): with prices incl. VAT the line is not the recurring amount. */
    public function test_adv_565_9_a_domain_renewal_with_prices_including_tax_is_not_the_recurring_amount(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'inclusive', 'settings.eu_vat_enabled' => false,
            'settings.domain_invoice_days' => 15, 'settings.tax_home_country' => null]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
        $domain = $this->domain();

        $this->assertSame(1, (new RenewalBilling)->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $billed = (float) $domain->invoiceItems()->first()->price;
        $this->assertNotSame(12.0, $billed, 'the line is net of tax');

        $mine = $this->differences()['renewal amount'][0]['mycs'] ?? null;
        $this->assertSame(number_format($billed, 2, '.', ''), $mine, 'the report must say what the invoice will carry');
    }
}

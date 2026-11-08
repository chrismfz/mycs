<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 5, adversarial verification of «g-money-service» (the renewals WHMCS gave up, #756, and the abandoned-order
 * refusal). Each test fails on main for the reason its finding gives.
 */
class Sweep5GMoneyServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 10:00:00');
        Queue::fake();
        Mail::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [true, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_order_cancel' => 0]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $client = $this->user->client()->id;
        foreach ([
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INT, rate REAL, "default" INT)',
            "INSERT INTO tblcurrencies VALUES (1, 'EUR', '', '€', 3, 1, 1)",
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            "INSERT INTO tblclients VALUES ({$client}, 1)",
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            "INSERT INTO tblconfiguration VALUES ('DateFormat', 'DD/MM/YYYY')",
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
                billingcycle TEXT, nextduedate TEXT, nextinvoicedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
                dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, orderid INT, type TEXT, registrationdate TEXT, domain TEXT, firstpaymentamount REAL,
                recurringamount REAL, registrar TEXT, registrationperiod INT, expirydate TEXT, status TEXT, nextduedate TEXT, additionalnotes TEXT,
                dnsmanagement INT, emailforwarding INT, idprotection INT, donotrenew INT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
                status TEXT, total REAL, credit REAL, taxrate REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
                amount REAL, taxed INT, duedate TEXT)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
                amountout REAL, fees REAL, transid TEXT)',
            'CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    private function whmcsService(PDO $whmcs, int $id, string $due, string $nextInvoice): void
    {
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, '2024-01-01', 'example.test', 1, 10, 'Monthly', ?, ?, 'Active', '', '', '', '', '', '', 0, '0000-00-00')")
            ->execute([$id, $this->user->client()->id, $product->product->id, $due, $nextInvoice]);
    }

    /** @param  list<array{0: int, 1: string, 2: int, 3: string, 4: float|int, 5: string}>  $lines */
    private function whmcsInvoice(PDO $whmcs, int $id, string $status, string $date, string $due, array $lines): void
    {
        $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, ?, '', ?, ?, '0000-00-00 00:00:00', ?, ?, 0, 0)")
            ->execute([$id, $this->user->client()->id, $date, $due, $status, array_sum(array_column($lines, 4))]);
        foreach ($lines as [$lineId, $type, $relid, $description, $amount, $lineDue]) {
            $whmcs->prepare('INSERT INTO tblinvoiceitems VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)')
                ->execute([$lineId, $id, $this->user->client()->id, $type, $relid, $description, $amount, $lineDue]);
        }
    }

    private function command(PDO $whmcs): ImportFromWhmcs
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return $command;
    }

    private function import(PDO $whmcs, array $steps): void
    {
        $command = $this->command($whmcs);
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * G-MONEY-SERVICE-1. The customer asked WHMCS to end the service at the end of its billing period (D); WHMCS cancelled
     * the renewal invoice it had already made for D, and nextinvoicedate had moved on. renewalsGivenUp takes it for a
     * renewal staff gave up and moveDatesOverGivenUp moves expires_at to D + 1 month: MyCS ends it a whole period late,
     * a period nobody pays for. WHMCS ends it on D.
     */
    public function test_g_money_service_1_an_end_of_period_cancellation_ends_the_service_on_whmcs_s_due_date(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->addDays(5)->toDateString();
        $this->whmcsService($whmcs, 9511, $due, Carbon::parse($due)->addMonthNoOverflow()->toDateString());
        $this->whmcsInvoice($whmcs, 9611, 'Cancelled', now()->subDays(2)->toDateString(), $due, [[96111, 'Hosting', 9511, 'Hosting (example.test)', 10, $due]]);
        $whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '" . now()->subDay()->toDateTimeString() . "', 9511, 'Moving away', 'End of Billing Period')");

        $this->import($whmcs, ['importServices', 'importCancellations', 'importInvoices', 'importInvoiceItems']);

        $this->assertSame($due, Service::query()->findOrFail(9511)->expires_at->toDateString(), 'its end of period moved past the day the customer asked for');
        Carbon::setTestNow(Carbon::parse($due)->setTime(10, 0));
        $this->cron();
        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->findOrFail(9511)->status, 'not ended on its due date, as the customer asked and WHMCS does');
    }

    /**
     * G-MONEY-SERVICE-2. A service and its addon (same due date D) renewed on one invoice, staff cancelled it in WHMCS and
     * nextinvoicedate moved on. Only the Hosting line is given up and its service's date moved; the addon's line is left
     * billable and its date left at D, so the first cron bills the addon's period from D (already past) on its own,
     * while its parent's same period was written off, and the addon falls off its parent's billing day. Since the fix the
     * import refuses the source (cancelledRenewals) and no line is given up by the importer.
     */
    public function test_g_money_service_2_the_addon_on_a_given_up_renewal_invoice_is_given_up_with_its_parent(): void
    {
        $whmcs = $this->whmcs();
        $due = now()->subDays(3)->toDateString();
        $next = Carbon::parse($due)->addMonthNoOverflow()->toDateString();
        $this->whmcsService($whmcs, 9521, $due, $next);
        $this->import($whmcs, ['importServices']);
        $parent = Service::query()->findOrFail(9521);
        $addonProduct = $this->createProduct();
        $addonProduct->plan->update(['name' => 'Monthly']);
        $addon = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $addonProduct->plan->id, 'product_id' => $addonProduct->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 5.00, 'quantity' => 1, 'expires_at' => $due,
            'parent_service_id' => $parent->id, 'whmcs_addon_id' => 77,
        ]);
        $this->whmcsInvoice($whmcs, 9621, 'Cancelled', Carbon::parse($due)->subDays(7)->toDateString(), $due, [
            [96211, 'Hosting', 9521, 'Hosting (example.test)', 10, $due],
            [96212, 'Addon', 77, 'Dedicated IP', 5, $due],
        ]);

        // Fixed (owner's decision 2026-11-08): the import refuses such a source, settled in WHMCS for the service and its
        // addons together; nothing is given up line by line any more, so the two never part.
        $this->assertSame([9521], (new ReflectionMethod(ImportFromWhmcs::class, 'cancelledRenewals'))->invoke($this->command($whmcs)));
        $this->import($whmcs, ['importInvoices', 'importInvoiceItems']);

        $this->assertNull(InvoiceItem::query()->findOrFail(96211)->forgone_at, 'the parent\'s line given up alone');
        $this->assertNull(InvoiceItem::query()->findOrFail(96212)->forgone_at);
        $this->assertSame($parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString(), 'the addon off its parent\'s billing day');
    }

    /**
     * G-MONEY-SERVICE-3. An old unpaid DomainRegister line of a domain WHMCS has Active (registered before payment; the
     * customer still owes it): paying it here sets nothing up (DomainOrderFulfilment sends pending domains only), yet the
     * import refuses it as an abandoned order and tells staff to cancel the invoice, a real debt.
     */
    public function test_g_money_service_3_an_unpaid_register_line_of_an_active_domain_is_no_abandoned_order(): void
    {
        $whmcs = $this->whmcs();
        $client = $this->user->client()->id;
        $whmcs->exec("INSERT INTO tbldomains (id, userid, type, registrationdate, domain, recurringamount, registrar, registrationperiod, expirydate, status, nextduedate)
            VALUES (8801, {$client}, 'Register', '" . now()->subDays(30)->toDateString() . "', 'owed.example', 12, 'openprovider', 1, '"
            . now()->addDays(335)->toDateString() . "', 'Active', '" . now()->addDays(335)->toDateString() . "')");
        $date = now()->subDays(30)->toDateString();
        $this->whmcsInvoice($whmcs, 9631, 'Unpaid', $date, $date, [[96311, 'DomainRegister', 8801, 'Domain Registration - owed.example - 1 Year/s', 12, $date]]);

        $orders = (new ReflectionMethod(ImportFromWhmcs::class, 'abandonedOrders'))->invoke($this->command($whmcs));

        $this->assertSame([], $orders, 'an Active domain\'s unpaid registration refuses the import as an order credit would set up');
    }
}

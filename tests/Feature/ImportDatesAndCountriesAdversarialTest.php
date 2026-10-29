<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Contact;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #528 (the importer's dates at midnight, countries the app knows).
 */
class ImportDatesAndCountriesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");

        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    }

    private function accountsTables(): void
    {
        $this->whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');
    }

    private function customer(int $id, ?string $country): void
    {
        $this->whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, 'First', 'Last', "login{$id}@example.test", Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $this->whmcs->prepare("INSERT INTO tblclients VALUES (?, 'First', 'Last', '', ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?,
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')")->execute([$id, "billing{$id}@example.test", $country]);
        $this->whmcs->prepare('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (?, ?, 1)')->execute([$id, $id]);
    }

    /**
     * ADV-528-1: a country name in another case than the app's list. The old rule (first two letters) got «CYPRUS»,
     * «greece», «FRANCE» right; EuVat::iso matches a name only as the list spells it, so they are now none: the
     * customer cannot order, and a Cypriot business with a valid VAT number pays the company's VAT instead of reverse charge.
     */
    public function test_a_country_name_in_another_case_is_still_its_country(): void
    {
        $this->accountsTables();
        $this->customer(401, 'CYPRUS');
        $this->customer(402, 'greece');
        $this->customer(403, 'FRANCE');

        (new ReflectionMethod($this->command, 'importUsers'))->invoke($this->command);

        $this->assertSame([401 => 'CY', 402 => 'GR', 403 => 'FR'],
            Contact::query()->whereNotNull('primary_of_client_id')->orderBy('primary_of_client_id')->pluck('country', 'primary_of_client_id')->all());
    }

    /**
     * ADV-528-2: a Mass Pay parent paid from account credit with no datepaid: its allocation is dated by the parent's day
     * (validateDate, now midnight), not flagged day-only, so the journal puts it at 00:00 before the same day's timed
     * overpayment that brought the credit (ADV-523-4 puts a day-only move out after that day's timed money): the
     * balance after it goes below zero.
     */
    public function test_a_mass_pay_parents_own_credit_without_datepaid_is_spent_after_the_days_timed_credit(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");

        // 2026-09-01 10:00: invoice 103 (25) paid 30, 5 to credit.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (103, {$clientId}, '', '2026-09-01', '2026-09-10', '2026-09-01 10:00:00', 'Paid', 25, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (1, 103, {$clientId}, 'Hosting', 0, 'Line', 25, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (1, {$clientId}, 103, 'banktransfer', '2026-09-01 10:00:00', 30, 0, 0, 'tr_1', 0)");
        $this->whmcs->exec("INSERT INTO tblcredit (clientid, date, description, amount) VALUES ({$clientId}, '2026-09-01', 'Invoice #103 Overpayment', 5)");
        // The same day: Mass Pay 900 of child 801 (5), paid from that credit; WHMCS left no datepaid on the parent.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (801, {$clientId}, '', '2026-09-01', '2026-09-10', '2026-09-01 18:00:00', 'Paid', 0, 5, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (2, 801, {$clientId}, 'Hosting', 0, 'Line', 5, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (900, {$clientId}, '', '2026-09-01', '2026-09-10', '0000-00-00 00:00:00', 'Paid', 0, 5, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (3, 900, {$clientId}, 'Invoice', 801, 'Line', 5, 0)");

        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay', 'journalMoney'] as $step) {
            (new ReflectionMethod($this->command, $step))->invoke($this->command);
        }

        $moves = MoneyEvent::query()->whereIn('kind', ['credit_in', 'credit_out'])->orderBy('id')->get()
            ->map(fn (MoneyEvent $event) => [$event->kind->value, $event->cause, (string) $event->credit_balance_after])->all();
        $this->assertSame([['credit_in', MoneyTrail::CAUSE_OVERPAYMENT, '5.00'], ['credit_out', MoneyTrail::CAUSE_CREDIT_PAYMENT, '0.00']], $moves,
            'the credit is spent after it came, never below zero');
        $this->assertSame(0, DB::table('money_events')->where('credit_balance_after', '<', 0)->count());
    }
}

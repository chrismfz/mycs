<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Contact;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The importer's dates and countries (2026-10-29): a date with no time is midnight, never the time of the run (a run at
 * 03:00 on a day the clocks went forward stopped the myip import); a customer's country is one the app knows
 * (EuVat::iso), and the ones left without are listed for staff (#524 stops their orders until they set one).
 */
class ImportDatesAndCountriesTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');

        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->output = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
    }

    private function customer(int $id, ?string $country): void
    {
        $this->whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, 'First', 'Last', "login{$id}@example.test", Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $this->whmcs->prepare("INSERT INTO tblclients VALUES (?, 'First', 'Last', '', ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?,
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')")->execute([$id, "billing{$id}@example.test", $country]);
        $this->whmcs->prepare('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (?, ?, 1)')->execute([$id, $id]);
    }

    public function test_a_date_without_a_time_is_midnight_not_the_time_of_the_run(): void
    {
        $validate = new ReflectionMethod($this->command, 'validateDate');

        $this->assertSame('2011-03-27 00:00:00', $validate->invoke($this->command, '2011-03-27'), 'a day the clocks went forward');
        $this->assertSame('2026-09-01 00:00:00', $validate->invoke($this->command, '2026-09-01'));
        $this->assertSame('2026-09-01 10:30:00', $validate->invoke($this->command, '2026-09-01 10:30:00'));
    }

    public function test_a_country_is_one_the_app_knows_and_the_ones_without_are_listed(): void
    {
        $this->customer(301, 'GR');
        $this->customer(302, 'Germany'); // the first two letters would be GE, Georgia
        $this->customer(303, 'el');
        $this->customer(304, 'XX');
        $this->customer(305, '');
        $this->whmcs->exec("INSERT INTO tblhosting (userid, domainstatus) VALUES (305, 'Active'), (305, 'Suspended'), (304, 'Terminated')");

        (new ReflectionMethod($this->command, 'importUsers'))->invoke($this->command);

        $this->assertSame([301 => 'GR', 302 => 'DE', 303 => 'GR', 304 => null, 305 => null],
            Contact::query()->whereNotNull('primary_of_client_id')->orderBy('primary_of_client_id')->pluck('country', 'primary_of_client_id')->all());
        $output = $this->output->fetch();
        $this->assertStringContainsString('2 customer(s) without a country the app knows', $output);
        $this->assertMatchesRegularExpression('/305\s*\|\s*\(empty\)\s*\|\s*2\s*\|.*\n.*304\s*\|\s*XX\s*\|\s*0/s', $output,
            'the ones with active services first: they are billed first');
    }
}

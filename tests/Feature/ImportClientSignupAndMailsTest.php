<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
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
 * From tblclients (#544): an account older than WHMCS's created_at signs up on its datecreated (430 on myip, 12 on
 * nixpal, else the day of the import); its email_preferences are its primary contact's (none set: every kind).
 */
class ImportClientSignupAndMailsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

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
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT,
            datecreated TEXT, email_preferences TEXT)');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');
    }

    private function customer(int $id, string $created, ?string $datecreated, ?string $preferences): void
    {
        $this->whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, 'First', 'Last', "login{$id}@example.test", Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $this->whmcs->prepare("INSERT INTO tblclients VALUES (?, 'First', 'Last', '', ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, ?, '2025-01-01 00:00:00', ?, ?)")->execute([$id, "billing{$id}@example.test", $created, $datecreated, $preferences]);
        $this->whmcs->prepare('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (?, ?, 1)')->execute([$id, $id]);
    }

    public function test_the_signup_date_and_the_mails_it_takes(): void
    {
        $this->customer(401, '0000-00-00 00:00:00', '2009-05-14', '{"general":"1","invoice":"1","support":"1","product":"1","domain":"1","affiliate":"0"}');
        $this->customer(402, '2024-03-01 10:00:00', '2024-03-01', '{"general":0,"invoice":1,"support":0,"product":0,"domain":1,"affiliate":0}');
        $this->customer(403, '0000-00-00 00:00:00', '0000-00-00', null);
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $this->assertSame('2009-05-14', Client::query()->find(401)->created_at->toDateString(), 'older than created_at: its datecreated');
        $this->assertSame('2024-03-01 10:00:00', Client::query()->find(402)->created_at->toDateTimeString());
        $this->assertTrue(Client::query()->find(403)->created_at->isToday(), 'neither: the import\'s day');
        $preferences = fn (int $id) => Contact::query()->where('primary_of_client_id', $id)->sole()->email_preferences;
        $this->assertSame(['general', 'invoice', 'support', 'product', 'domain'], $preferences(401));
        $this->assertSame(['invoice', 'domain'], $preferences(402));
        $this->assertNull($preferences(403), 'none set: every kind, as WHMCS');
    }
}

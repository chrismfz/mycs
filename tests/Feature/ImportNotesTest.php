<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Import\WhmcsNoteImport;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Staff's notes from WHMCS no other step brings (owner 2026-10-30): tblnotes by their admin, sticky and dated; the
 * account's own notes; an invoice's (on its account, naming it); an addon's (on its service). Whole, unescaped.
 */
class ImportNotesTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblnotes (id INTEGER PRIMARY KEY, userid INT, adminid INT, created TEXT, modified TEXT, note TEXT, sticky INT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, notes TEXT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, notes TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, userid INT, name TEXT, notes TEXT)');
    }

    public function test_every_kind_of_note_comes_whole(): void
    {
        $client = User::factory()->create()->client();
        $staff = Staff::factory()->create();
        $service = Service::factory()->create(['client_id' => $client->id, 'notes' => 'Moved to server 2']);
        $long = str_repeat('Πληρώνει πάντα με έμβασμα. ', 200);
        $this->whmcs->prepare('INSERT INTO tblnotes VALUES (1, ?, ?, ?, ?, ?, 1)')->execute([$client->id, $staff->id, '2019-12-12 10:40:36', '2020-01-02 09:00:00', $long]);
        $this->whmcs->prepare("INSERT INTO tblnotes VALUES (2, ?, 999, '2015-01-01 00:00:00', '2015-01-01 00:00:00', 'Owner&#039;s brother<br />calls often', 0)")->execute([$client->id]);
        $this->whmcs->prepare("INSERT INTO tblnotes VALUES (3, 424242, 1, '2015-01-01 00:00:00', '2015-01-01 00:00:00', 'Of nobody here', 0)")->execute();
        $this->whmcs->prepare('INSERT INTO tblclients VALUES (?, ?)')->execute([$client->id, 'VIP &amp; friend']);
        $this->whmcs->prepare("INSERT INTO tblinvoices VALUES (77, ?, '', '2024-05-01', 'Paid by hand at the office')")->execute([$client->id]);
        $this->whmcs->prepare("INSERT INTO tblhostingaddons VALUES (5, ?, ?, 'Backup', 'Daily at 03:00')")->execute([$service->id, $client->id]);
        // A service WHMCS deleted: its account's (code review 1); of nobody here: listed.
        $this->whmcs->prepare("INSERT INTO tblhostingaddons VALUES (6, 999999, ?, 'Old IP', 'Was 192.0.2.9')")->execute([$client->id]);
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (7, 999998, 424242, 'X', 'Lost')");

        $result = (new WhmcsNoteImport($this->whmcs))->run();

        $notes = DB::table('client_notes')->where('client_id', $client->id)->orderBy('id')->get();
        $this->assertSame([trim($long), $staff->id, 1, '2019-12-12 10:40:36'], [$notes[0]->body, (int) $notes[0]->staff_id, (int) $notes[0]->sticky, $notes[0]->created_at],
            'whole, by its admin, sticky, dated');
        $this->assertSame(["(WHMCS admin #999)\nOwner's brother\ncalls often", null], [$notes[1]->body, $notes[1]->staff_id], 'unescaped; an admin not here: named');
        $this->assertSame("From WHMCS (the account's notes):\nVIP & friend", $notes[2]->body);
        $this->assertSame("From WHMCS (invoice #77's notes):\nPaid by hand at the office", $notes[3]->body);
        $this->assertSame("Moved to server 2\n\nAddon «Backup» (WHMCS notes):\nDaily at 03:00", $service->fresh()->notes);
        $this->assertSame("From WHMCS (addon «Old IP» of service #999999, which WHMCS deleted):\nWas 192.0.2.9", $notes[4]->body);
        $this->assertSame(['notes' => 2, 'account_notes' => 1, 'invoice_notes' => 1, 'addon_notes' => 2, 'skipped' => 2], $result['counts']);
        $this->assertStringContainsString('customer #424242, not here', implode("\n", $result['notes']));
    }

    public function test_a_whmcs_without_a_table_imports_the_rest(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, notes TEXT)');
        $client = User::factory()->create()->client();
        $whmcs->prepare('INSERT INTO tblclients VALUES (?, ?)')->execute([$client->id, 'Old customer']);

        $result = (new WhmcsNoteImport($whmcs))->run();

        $this->assertSame(1, $result['counts']['account_notes']);
        $this->assertStringContainsString('could not be read on this WHMCS', implode("\n", $result['notes']));
    }

    /** A cancelled invoice's WHMCS notes are its cancellation reason too, as staff see it (code review 3 of #546). */
    public function test_a_cancelled_invoices_notes_are_its_cancellation_reason(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL, notes TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, {$clientId}, '', '2026-09-01', '2026-09-10', '', 'Cancelled', 10, 0, 0, 'Automatic Cancellation after 60 days overdue &amp; more')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (2, {$clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 10, 0, 0, 'Paid in cash')");
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        (new \ReflectionMethod($command, 'importInvoices'))->invoke($command);

        $this->assertSame(['Automatic Cancellation after 60 days overdue & more', null],
            [Invoice::query()->find(1)->cancellation_reason, Invoice::query()->find(2)->cancellation_reason]);
    }
}

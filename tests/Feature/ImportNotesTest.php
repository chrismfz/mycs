<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Import\WhmcsNoteImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
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
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, name TEXT, notes TEXT)');
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
        $this->whmcs->prepare("INSERT INTO tblhostingaddons VALUES (5, ?, 'Backup', 'Daily at 03:00')")->execute([$service->id]);

        $result = (new WhmcsNoteImport($this->whmcs))->run();

        $notes = DB::table('client_notes')->where('client_id', $client->id)->orderBy('id')->get();
        $this->assertSame([trim($long), $staff->id, 1, '2019-12-12 10:40:36'], [$notes[0]->body, (int) $notes[0]->staff_id, (int) $notes[0]->sticky, $notes[0]->created_at],
            'whole, by its admin, sticky, dated');
        $this->assertSame(["Owner's brother\ncalls often", null], [$notes[1]->body, $notes[1]->staff_id], 'unescaped; an admin not here: none');
        $this->assertSame("From WHMCS (the account's notes):\nVIP & friend", $notes[2]->body);
        $this->assertSame("From WHMCS (invoice #77's notes):\nPaid by hand at the office", $notes[3]->body);
        $this->assertSame("Moved to server 2\n\nAddon «Backup» (WHMCS notes):\nDaily at 03:00", $service->fresh()->notes);
        $this->assertSame(['notes' => 2, 'account_notes' => 1, 'invoice_notes' => 1, 'addon_notes' => 1, 'skipped' => 1], $result['counts']);
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
}

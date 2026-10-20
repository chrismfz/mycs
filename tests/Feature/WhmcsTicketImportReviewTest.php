<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use MyCS\TicketMail\Tests\Eml;
use PDO;
use Tests\TestCase;

/** Independent code review of PR #256 (WHMCS ticket import): each test fails on the PR's head. */
class WhmcsTicketImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private User $customer;

    /** The customer's account: WHMCS's userid is its id (the full import keeps it). */
    private int $clientId;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create(['email' => 'customer@example.com']);
        $this->clientId = $this->customer->client()->id;
        Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Admin']);
        $this->whmcs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->whmcs->exec('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, email TEXT, clientsonly INT, hidden INT, "order" INT)');
        $this->whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, attachments_removed INT DEFAULT 0, lastreply TEXT, merged_ticket_id INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT)');
        $this->whmcs->exec('CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY, ticketid INT, admin TEXT, date TEXT, message TEXT, attachments TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT)');
        $this->whmcs->exec("INSERT INTO tblticketdepartments VALUES (1, 'Support', 'support@myip.test', 0, 0, 1)");
        $this->folder = storage_path('framework/testing/whmcs-review-' . uniqid());
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        foreach (TicketMessage::query()->with('attachments')->get()->flatMap->attachments as $file) {
            @unlink($file->localPath);
        }
        parent::tearDown();
    }

    private function ticket(int $id, array $columns = []): void
    {
        $this->insert('tbltickets', $columns + ['id' => $id, 'tid' => (string) (100000 + $id), 'did' => 1, 'userid' => $this->clientId, 'name' => '', 'email' => '',
            'date' => '2025-03-01 10:00:00', 'title' => "Ticket {$id}", 'message' => "Opening {$id}", 'status' => 'Open', 'urgency' => 'Medium',
            'admin' => '', 'attachment' => '', 'lastreply' => '2025-03-02 10:00:00', 'merged_ticket_id' => 0]);
    }

    private function insert(string $table, array $row): void
    {
        $columns = implode(', ', array_map(fn ($column) => '"' . $column . '"', array_keys($row)));
        $this->whmcs->prepare("INSERT INTO {$table} ({$columns}) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }

    private function import(): array
    {
        return (new WhmcsTicketImport($this->whmcs, $this->folder))->run();
    }

    /** R1: the admin and the guest page list messages by id; a note (or a merged opening) must not jump to the end. */
    public function test_messages_are_stored_in_the_order_whmcs_wrote_them(): void
    {
        $this->ticket(7);
        $this->insert('tblticketreplies', ['id' => 1, 'tid' => 7, 'userid' => 0, 'date' => '2025-03-01 11:00:00', 'message' => 'Looking', 'admin' => 'Nikos Admin', 'attachment' => '']);
        $this->insert('tblticketreplies', ['id' => 2, 'tid' => 7, 'userid' => $this->clientId, 'date' => '2025-03-01 13:00:00', 'message' => 'Thanks', 'admin' => '', 'attachment' => '']);
        $this->insert('tblticketnotes', ['id' => 1, 'ticketid' => 7, 'admin' => 'Nikos Admin', 'date' => '2025-03-01 12:00:00', 'message' => 'Reseller', 'attachments' => '']);

        $this->import();

        $this->assertSame(['Opening 7', 'Looking', 'Reseller', 'Thanks'], Ticket::query()->findOrFail(7)->messages()->orderBy('id')->pluck('message')->all());
    }

    /** R2: a sixth file is dropped by array_slice and counted nowhere. */
    public function test_every_file_beyond_the_limit_is_counted(): void
    {
        $names = [];
        foreach (range(1, 6) as $i) {
            file_put_contents("{$this->folder}/10000{$i}_r{$i}.pdf", Eml::pdf());
            $names[] = "10000{$i}_r{$i}.pdf";
        }
        $this->ticket(4, ['attachment' => implode('|', $names)]);

        $counts = $this->import();

        $this->assertSame(6, $counts['files'] + $counts['files_refused'] + $counts['files_missing'], 'every listed file is either brought or counted');
    }

    /** R3: WHMCS keeps the names after «Remove attachments» / pruning (attachments_removed = 1): not «missing». */
    public function test_attachments_whmcs_removed_are_not_counted_missing(): void
    {
        $this->ticket(4, ['attachment' => '123456_gone.pdf', 'attachments_removed' => 1]);

        $this->assertSame(0, $this->import()['files_missing']);
    }

    /** R4: replies or notes still under a merged ticket's id are never read. */
    public function test_replies_and_notes_under_a_merged_ticket_are_not_lost(): void
    {
        $this->ticket(10);
        $this->ticket(11, ['merged_ticket_id' => 10]);
        $this->insert('tblticketreplies', ['id' => 5, 'tid' => 11, 'userid' => 0, 'date' => '2025-03-01 11:00:00', 'message' => 'Reply on the duplicate', 'admin' => 'Nikos Admin', 'attachment' => '']);
        $this->insert('tblticketnotes', ['id' => 5, 'ticketid' => 11, 'admin' => 'Nikos Admin', 'date' => '2025-03-01 11:00:00', 'message' => 'Note on the duplicate', 'attachments' => '']);

        $this->import();

        $this->assertSame(1, TicketMessage::query()->where('message', 'Reply on the duplicate')->count());
        $this->assertSame(1, TicketMessage::query()->where('message', 'Note on the duplicate')->count());
    }

    /** R5: merged into a ticket that was itself merged: it comes as itself, and parity fails. */
    public function test_a_chain_of_merges_keeps_parity(): void
    {
        $this->ticket(10);
        $this->ticket(11, ['merged_ticket_id' => 10]);
        $this->ticket(12, ['merged_ticket_id' => 11]);

        $counts = $this->import();

        $this->assertNull(Ticket::query()->where('whmcs_id', 12)->first());
        $this->assertSame($counts['parity']['whmcs'], $counts['parity']['mycs']);
    }

    /** R6: WHMCS leaves name/email empty on a client's ticket; a client not imported → a guest ticket with no one to answer. */
    public function test_a_ticket_of_a_client_not_imported_keeps_who_wrote_it(): void
    {
        $this->insert('tblclients', ['id' => 999999, 'firstname' => 'Old', 'lastname' => 'Client', 'email' => 'old@client.example']);
        $this->ticket(2, ['userid' => 999999]);

        $this->import();

        $ticket = Ticket::query()->findOrFail(2);
        $this->assertSame('old@client.example', $ticket->guest_email);
        $this->assertNotSame('', (string) $ticket->guest_name);
    }

    /** R7: «what MyCS made is never touched»: a status staff set after the cut-over is reverted by a re-run. */
    public function test_a_rerun_keeps_a_status_staff_set_in_mycs(): void
    {
        $this->ticket(5);
        $this->import();
        Ticket::query()->findOrFail(5)->update(['status' => Ticket::STATUS_CLOSED]);

        $this->import();

        $this->assertSame(Ticket::STATUS_CLOSED, Ticket::query()->findOrFail(5)->status);
    }
}

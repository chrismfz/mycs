<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of PR Δ (WHMCS tickets importer). Each test is a finding: it fails on the branch as it is.
 * The WHMCS fixture is the same in-memory SQLite shape as WhmcsTicketImportTest, plus tblclients where needed.
 */
class WhmcsTicketImportAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private User $customer;

    private string $folder;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create(['email' => 'customer@example.com']);
        Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Admin']);
        $this->whmcs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->whmcs->exec('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, email TEXT, clientsonly INT, hidden INT, "order" INT, host TEXT, login TEXT, password TEXT)');
        $this->whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, merged_ticket_id INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT)');
        $this->whmcs->exec('CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY, ticketid INT, admin TEXT, date TEXT, message TEXT, attachments TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT)');
        $this->whmcs->exec("INSERT INTO tblticketdepartments VALUES (1, 'Support', 'support@myip.test', 0, 0, 1, '', '', '')");
        $this->folder = storage_path('framework/testing/whmcs-attachments-adv-' . uniqid());
        $this->outside = storage_path('framework/testing/whmcs-outside-adv-' . uniqid());
        File::ensureDirectoryExists($this->folder);
        File::ensureDirectoryExists($this->outside);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        File::deleteDirectory($this->outside);
        foreach (TicketMessage::query()->with('attachments')->get()->flatMap->attachments as $file) {
            @unlink($file->localPath);
        }
        parent::tearDown();
    }

    private function ticket(int $id, array $columns = []): void
    {
        $row = $columns + ['id' => $id, 'tid' => (string) (100000 + $id), 'did' => 1, 'userid' => $this->customer->id, 'name' => '', 'email' => '',
            'date' => '2025-03-01 10:00:00', 'title' => "Ticket {$id}", 'message' => "Opening {$id}", 'status' => 'Open', 'urgency' => 'Medium',
            'admin' => '', 'attachment' => '', 'lastreply' => '2025-03-02 10:00:00', 'merged_ticket_id' => 0];
        $this->insert('tbltickets', $row);
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

    /**
     * F1. clientOf() trusts that «WHMCS userid N» is «MyCS user N», with no proof the user came from WHMCS client N. A
     * WHMCS client the accounts import skipped (no owner in tblusers_clients) or created after it (WHMCS still live
     * until the cut-over; MyCS signups take the next ids), and whose id a MyCS login has: its tickets land in that
     * login's account.
     */
    public function test_a_whmcs_client_never_imported_does_not_land_on_the_mycs_login_with_its_id(): void
    {
        $stranger = User::factory()->create(['email' => 'signed-up-in-mycs@example.com']);
        $this->insert('tblclients', ['id' => $stranger->id, 'firstname' => 'Other', 'lastname' => 'Person', 'email' => 'whmcs-only@example.com']);
        $this->ticket(1, ['userid' => $stranger->id, 'title' => 'My root password', 'message' => 'root / hunter2', 'email' => 'whmcs-only@example.com']);
        $this->insert('tblticketreplies', ['id' => 1, 'tid' => 1, 'userid' => $stranger->id, 'name' => '', 'email' => '', 'date' => '2025-03-01 11:00:00',
            'message' => 'and my card', 'admin' => '', 'attachment' => '']);

        $this->import();

        $ticket = Ticket::query()->where('whmcs_id', 1)->sole();
        $this->assertNotSame($stranger->client()->id, $ticket->client_id, 'another person\'s ticket in the account of the MyCS login with the same id');
        $this->assertSame(0, TicketMessage::query()->where('user_id', $stranger->id)->count(), 'nor written by that login');
    }

    /**
     * F2. The account is the one the login with the same id owns *now*, not the WHMCS client's account (the account
     * keeps the WHMCS client id). After an ownership transfer, and with the old owner having opened an account of
     * their own in MyCS, the WHMCS client's tickets go into that new account, away from the one they belong to.
     */
    public function test_after_an_ownership_transfer_the_ticket_stays_with_the_whmcs_clients_account(): void
    {
        $original = $this->customer->client();
        $newOwner = User::factory()->create();
        $original->transferOwnership($newOwner);
        $newAccount = Client::openFor($this->customer->fresh(), 777777);
        $this->ticket(2);

        $this->import();

        $ticket = Ticket::query()->where('whmcs_id', 2)->sole();
        $this->assertNotSame($newAccount->id, $ticket->client_id, 'in an account WHMCS never had');
        $this->assertSame($original->id, $ticket->client_id);
    }

    /**
     * F3. A merged ticket's opening joins the kept one as a public message, whoever's it was. WHMCS lets staff merge
     * tickets of different requesters (here: a client's into a stranger's guest ticket): the client's text is then
     * shown on the guest's link and in the other account's portal.
     */
    public function test_a_ticket_merged_from_another_requester_is_not_shown_to_the_kept_tickets_requester(): void
    {
        $this->ticket(10, ['userid' => 0, 'name' => 'Stranger', 'email' => 'stranger@example.com', 'title' => 'Guest ticket']);
        $this->ticket(11, ['title' => 'Invoice question', 'message' => 'My VAT number is EL123456789', 'merged_ticket_id' => 10]);

        $this->import();

        $kept = Ticket::query()->findOrFail(10);
        $this->assertTrue($kept->isGuest());
        $this->assertFalse($kept->publicMessages()->where('message', 'like', '%EL123456789%')->exists(),
            'the client\'s message is now public on a stranger\'s guest ticket');
    }

    /** F4. A merged ticket brings its opening only: its replies and staff's notes (still under its id in WHMCS) are lost. */
    public function test_a_merged_tickets_replies_and_notes_are_not_lost(): void
    {
        $this->ticket(20, ['title' => 'Main']);
        $this->ticket(21, ['title' => 'Dup', 'merged_ticket_id' => 20]);
        $this->insert('tblticketreplies', ['id' => 5, 'tid' => 21, 'userid' => $this->customer->id, 'name' => '', 'email' => '', 'date' => '2025-03-01 12:00:00',
            'message' => 'Reply on the duplicate', 'admin' => '', 'attachment' => '']);
        $this->insert('tblticketnotes', ['id' => 5, 'ticketid' => 21, 'admin' => 'Nikos Admin', 'date' => '2025-03-01 12:30:00', 'message' => 'Note on the duplicate', 'attachments' => '']);

        $this->import();

        $this->assertTrue(TicketMessage::query()->where('message', 'Reply on the duplicate')->exists(), 'reply lost');
        $this->assertTrue(TicketMessage::query()->where('message', 'Note on the duplicate')->where('is_internal', true)->exists(), 'note lost');
    }

    /** F5. A re-run puts back as a guest's a ticket staff linked to an account in MyCS (client_id, guest_* overwritten). */
    public function test_a_rerun_does_not_undo_staffs_link_of_a_guest_ticket_to_an_account(): void
    {
        $this->ticket(30, ['userid' => 0, 'name' => 'Maria', 'email' => 'maria@example.com']);
        $this->import();
        $ticket = Ticket::query()->findOrFail(30);
        $ticket->forceFill(['client_id' => $this->customer->client()->id, 'guest_name' => null, 'guest_email' => null])->save();

        $this->import();

        $this->assertSame($this->customer->client()->id, $ticket->fresh()->client_id, 'the re-run took the ticket away from the account');
        $this->assertFalse($ticket->fresh()->isGuest());
    }

    /** F6. A re-run overwrites what staff changed in MyCS (status, department, subject) even when WHMCS changed nothing. */
    public function test_a_rerun_keeps_a_status_staff_changed_when_whmcs_did_not_change(): void
    {
        $this->ticket(31);
        $this->import();
        Ticket::query()->findOrFail(31)->forceFill(['status' => Ticket::STATUS_CLOSED])->save();

        $this->import();

        $this->assertSame(Ticket::STATUS_CLOSED, Ticket::query()->findOrFail(31)->status, 'a closed ticket reopened by the re-run');
    }

    /** F7. A ticket (or message) staff deleted in MyCS (spam, an erasure request) comes back on a re-run, with its id. */
    public function test_a_ticket_deleted_in_mycs_does_not_come_back_on_a_rerun(): void
    {
        $this->ticket(32, ['title' => 'Erase me']);
        $this->import();
        Ticket::query()->findOrFail(32)->delete();

        $this->import();

        $this->assertNull(Ticket::query()->where('whmcs_id', 32)->first(), 'deleted in MyCS, back after the re-run');
    }

    /**
     * F8. WHMCS guest tickets (its open form, its mail piping) never had their From checked: anyone could open one in
     * anyone's name. Imported with sender_vouched NULL, the guest page (§3 Δ, #252) serves their whole content and
     * subject on our domain to whoever reads that address, as if vouched; the contact form's tickets are false.
     */
    public function test_an_imported_guest_ticket_is_not_taken_as_vouched_for(): void
    {
        $this->ticket(40, ['userid' => 0, 'name' => 'Bank', 'email' => 'victim@example.com', 'title' => 'Verify your account at evil.example']);

        $this->import();

        $this->assertFalse(Ticket::query()->findOrFail(40)->sender_vouched);
    }

    /**
     * F9. The guest's address is only trimmed and lower-cased: not validated, not scrubbed, not cut to the column's 255.
     * On MariaDB (strict) a long or invalid-UTF-8 one stops the import half-way, every run; with no name the raw address
     * also becomes guest_name, bypassing text().
     */
    public function test_a_hostile_guest_address_is_stored_safely(): void
    {
        $this->ticket(41, ['userid' => 0, 'name' => '', 'email' => str_repeat('a', 300) . "@example.com\r\nBcc: x@evil.example"]);
        $this->ticket(42, ['userid' => 0, 'name' => '', 'email' => "bad\xff\xfe\x00@example.com"]);

        $this->import();

        foreach ([41, 42] as $id) {
            $ticket = Ticket::query()->findOrFail($id);
            $this->assertTrue($ticket->guest_email === null || (strlen($ticket->guest_email) <= 255 && filter_var($ticket->guest_email, FILTER_VALIDATE_EMAIL) !== false),
                "guest_email of {$id}: " . bin2hex((string) $ticket->guest_email));
            $this->assertTrue(mb_check_encoding((string) $ticket->guest_name, 'UTF-8') && !preg_match('/[\x00-\x1F]/', (string) $ticket->guest_name),
                "guest_name of {$id} unscrubbed");
            $this->assertLessThanOrEqual(255, mb_strlen((string) $ticket->guest_name));
        }
    }

    /** F10. More than MAX_COUNT files on a WHMCS message: the rest are dropped silently (neither copied nor counted). */
    public function test_files_beyond_the_limit_are_at_least_counted(): void
    {
        $names = [];
        foreach (range(1, 7) as $i) {
            file_put_contents("{$this->folder}/10000{$i}_log{$i}.txt", "line {$i}\n");
            $names[] = "10000{$i}_log{$i}.txt";
        }
        $this->ticket(50, ['attachment' => implode('|', $names)]);

        $counts = $this->import();

        $this->assertSame(7, $counts['files'] + $counts['files_missing'] + $counts['files_refused'], 'two files vanished from the report');
    }

    /** F11. «Once» is «a message with any file is skipped»: a file missing on the first run never comes on a re-run. */
    public function test_a_file_missing_on_the_first_run_comes_on_a_rerun(): void
    {
        file_put_contents("{$this->folder}/111111_a.txt", "a\n");
        $this->ticket(51, ['attachment' => '111111_a.txt|222222_b.txt']);
        $this->import();
        file_put_contents("{$this->folder}/222222_b.txt", "b\n");

        $this->import();

        $this->assertSame(['a.txt', 'b.txt'], Ticket::query()->findOrFail(51)->messages()->sole()->attachments()->orderBy('filename')->pluck('filename')->all());
    }

    /** F12. A symlink in the (copied) attachments folder is followed: a file from outside it is imported as the customer's. */
    public function test_a_symlink_in_the_attachments_folder_is_not_followed_outside_it(): void
    {
        file_put_contents("{$this->outside}/secret.txt", "APP_KEY=base64:not-for-customers\n");
        symlink("{$this->outside}/secret.txt", "{$this->folder}/123456_notes.txt");
        $this->ticket(52, ['attachment' => '123456_notes.txt']);

        $this->import();

        $this->assertSame(0, Ticket::query()->findOrFail(52)->messages()->sole()->attachments()->count(), 'a file outside the folder became the customer\'s download');
    }
}

<?php

namespace Tests\Feature;

use App\Console\Commands\ImportWhmcsTickets;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use MyCS\TicketMail\Tests\Eml;
use PDO;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** WHMCS's tickets, PR Δ (docs/tickets.md §5α): re-runnable, guests stay guests, nothing fires, parity. */
class WhmcsTicketImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private User $customer;

    private string $folder;

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
        $this->whmcs->exec("INSERT INTO tblticketdepartments VALUES (1, 'Support', 'support@myip.test', 0, 0, 1, 'imap.myip.test', 'support@myip.test', 'WHMCS-SECRET')");
        $this->whmcs->exec("INSERT INTO tblticketdepartments VALUES (2, 'Sales', 'sales@myip.test', 1, 0, 2, '', '', '')");
        $this->folder = storage_path('framework/testing/whmcs-attachments-' . uniqid());
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

    /** @return array<string, mixed> */
    private function import(): array
    {
        return (new WhmcsTicketImport($this->whmcs, $this->folder))->run();
    }

    public function test_departments_tickets_messages_and_notes_come_as_whmcs_has_them(): void
    {
        Mail::fake();
        $this->ticket(7, ['status' => 'In Progress', 'urgency' => 'High', 'title' => 'Site &quot;down&quot;', 'message' => 'It &lt;b&gt;broke&lt;/b&gt;']);
        $this->insert('tblticketreplies', ['id' => 1, 'tid' => 7, 'userid' => 0, 'name' => '', 'email' => '', 'date' => '2025-03-01 11:00:00', 'message' => 'Looking', 'admin' => 'Nikos Admin', 'attachment' => '']);
        $this->insert('tblticketreplies', ['id' => 2, 'tid' => 7, 'userid' => $this->customer->id, 'name' => '', 'email' => '', 'date' => '2025-03-01 12:00:00', 'message' => 'Thanks', 'admin' => '', 'attachment' => '']);
        $this->insert('tblticketreplies', ['id' => 3, 'tid' => 7, 'userid' => 0, 'name' => '', 'email' => '', 'date' => '2025-03-01 13:00:00', 'message' => 'Bye', 'admin' => 'Gone Admin', 'attachment' => '']);
        $this->insert('tblticketnotes', ['id' => 1, 'ticketid' => 7, 'admin' => 'Nikos Admin', 'date' => '2025-03-01 11:30:00', 'message' => 'Reseller', 'attachments' => '']);

        $counts = $this->import();

        $support = TicketDepartment::query()->where('whmcs_id', 1)->sole();
        $this->assertSame(['Support', 'support@myip.test', null, null], [$support->name, $support->email, $support->imap_username, $support->imap_password], 'never the mailbox login');
        $this->assertTrue(TicketDepartment::query()->where('whmcs_id', 2)->sole()->clients_only);
        $this->assertSame(1, TicketDepartment::query()->whereRaw('LOWER(name) = ?', ['support'])->count(), 'the migration\'s Support taken over, not doubled');

        $ticket = Ticket::query()->findOrFail(7);
        $this->assertSame([7, '100007', $this->customer->client()->id, 'Site "down"', Ticket::STATUS_ON_HOLD, 'high', $support->id, 'whmcs'],
            [$ticket->whmcs_id, $ticket->reference, $ticket->client_id, $ticket->subject, $ticket->status, $ticket->priority, $ticket->department_id, $ticket->opened_via]);
        $messages = $ticket->messages()->orderBy('created_at')->get();
        $this->assertSame(['It <b>broke</b>', 'Looking', 'Reseller', 'Thanks', 'Bye'], $messages->pluck('message')->all());
        $this->assertSame([false, false, true, false, false], $messages->pluck('is_internal')->all());
        $this->assertSame($this->customer->id, $messages[0]->user_id);
        $this->assertSame('Nikos', $messages[1]->staff?->first_name);
        $this->assertFalse((bool) $messages[4]->staff?->active, 'an admin no longer here: a disabled member of that name');
        $this->assertSame('2025-03-01 10:00:00', $ticket->created_at->format('Y-m-d H:i:s'));

        // Nothing fired: no mail, no thread key, no status moved by the last reply's author.
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertSame(0, TicketMailId::query()->count());
        $this->assertSame(['whmcs' => ["{$support->id}/on_hold" => 1], 'mycs' => ["{$support->id}/on_hold" => 1]], $counts['parity']);
    }

    /** §3 Δ: a WHMCS guest stays a guest; so does a ticket of a client that was not imported. Never by email. */
    public function test_a_guest_stays_a_guest_whatever_the_email(): void
    {
        User::factory()->create(['email' => 'guest@example.com']);
        $this->ticket(1, ['userid' => 0, 'name' => 'Maria', 'email' => 'Guest@Example.com']);
        $this->ticket(2, ['userid' => 999999, 'name' => 'Old client', 'email' => 'customer@example.com']);

        $counts = $this->import();

        $this->assertSame([null, 'Maria', 'guest@example.com'], [Ticket::query()->find(1)->client_id, Ticket::query()->find(1)->guest_name, Ticket::query()->find(1)->guest_email]);
        $this->assertNull(Ticket::query()->find(2)->client_id, 'not the account whose login has that email');
        $this->assertSame([1, 1], [$counts['guests'], $counts['no_account']]);
    }

    public function test_a_merged_ticket_joins_the_one_whmcs_kept(): void
    {
        $this->ticket(10, ['title' => 'Main']);
        $this->ticket(11, ['title' => 'Duplicate', 'message' => 'Same problem', 'merged_ticket_id' => 10, 'status' => 'Closed']);

        $counts = $this->import();

        $this->assertNull(Ticket::query()->find(11));
        $main = Ticket::query()->findOrFail(10);
        $this->assertTrue($main->messages()->where('message', 'Same problem')->exists());
        $this->assertTrue($main->messages()->where('is_internal', true)->where('message', 'like', '%#100011%')->exists());
        $this->assertSame(1, $counts['merged']);
        $this->assertSame($counts['parity']['whmcs'], $counts['parity']['mycs'], 'a merged one is not counted on either side');
    }

    public function test_a_rerun_changes_nothing_but_what_whmcs_changed_and_keeps_what_mycs_made(): void
    {
        $this->ticket(5);
        $this->import();
        $ours = TicketMessage::query()->create(['ticket_id' => 5, 'staff_id' => Staff::query()->first()->id, 'message' => 'Written in MyCS']);
        $this->whmcs->exec("UPDATE tbltickets SET status = 'Closed' WHERE id = 5");

        $this->import();
        $this->import();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(Ticket::STATUS_CLOSED, Ticket::query()->find(5)->status);
        $this->assertSame(2, TicketMessage::query()->where('ticket_id', 5)->count());
        $this->assertTrue($ours->fresh()->exists);
    }

    public function test_a_number_taken_here_is_kept_apart(): void
    {
        Ticket::query()->create(['subject' => 'Made in MyCS', 'status' => 'open', 'priority' => 'low', 'client_id' => $this->customer->client()->id, 'reference' => '100003']);
        $this->ticket(3);

        $counts = $this->import();

        $this->assertSame('100003-W3', Ticket::query()->where('whmcs_id', 3)->value('reference'));
        $this->assertSame(1, $counts['reference_taken']);
    }

    public function test_files_come_under_the_rules_once(): void
    {
        file_put_contents("{$this->folder}/123456_report.pdf", Eml::pdf());
        file_put_contents("{$this->folder}/222222_page.pdf", '<html><script>x</script></html>');
        $this->ticket(4, ['attachment' => '123456_report.pdf|222222_page.pdf|333333_gone.pdf']);

        $counts = $this->import();
        $this->import();

        $file = Ticket::query()->findOrFail(4)->messages()->sole()->attachments()->sole();
        $this->assertSame(['report.pdf', 'application/pdf'], [$file->filename, $file->mime_type]);
        $this->assertSame([1, 1, 1], [$counts['files'], $counts['files_refused'], $counts['files_missing']]);
    }

    public function test_another_status_is_open_and_told_and_the_command_reports_parity(): void
    {
        $this->ticket(8, ['status' => 'Escalated']);

        $counts = $this->import();

        $this->assertSame(Ticket::STATUS_OPEN, Ticket::query()->find(8)->status);
        $this->assertSame(['Escalated' => 1], $counts['other_status']);
        $command = new class extends Command {};
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $this->assertSame(Command::SUCCESS, ImportWhmcsTickets::report($command, $counts));
        $this->assertStringContainsString('Escalated', $output->fetch());
    }
}

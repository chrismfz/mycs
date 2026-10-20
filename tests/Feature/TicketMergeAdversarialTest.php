<?php

namespace Tests\Feature;

use App\Livewire\Tickets\Guest;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use App\Support\Tickets\GuestLinks;
use App\Support\Tickets\MergeTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #266 (merging tickets, docs/tickets.md §6κ). Each test is a finding: it fails on the branch as
 * it is.
 */
class TicketMergeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes, string $status = Ticket::STATUS_OPEN): Ticket
    {
        return Ticket::query()->create($attributes + ['subject' => 'Help', 'status' => $status, 'priority' => 'low', 'department_id' => $this->support->id]);
    }

    /**
     * F1. Show::save (and closeTicket) never look at merged_into_id: a portal page opened before the merge (or any
     * request to the component) still writes into the merged ticket. The customer's message lands on a ticket hidden
     * from their list, the listener reopens it (closed → open/customer_reply) and notifies staff, and staff cannot
     * answer it there (EditTicket::store refuses a merged ticket).
     */
    public function test_a_portal_page_open_before_the_merge_cannot_write_into_the_merged_ticket(): void
    {
        $owner = User::factory()->create();
        $kept = $this->ticket(['client_id' => $owner->client()->id]);
        $duplicate = $this->ticket(['client_id' => $owner->client()->id]);
        $page = $this->livewireAs($owner)->test(TicketShow::class, ['ticket' => $duplicate]);

        app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);
        $page->set('message', 'Written after the merge')->call('save');

        $duplicate->refresh();
        $this->assertSame(0, $duplicate->messages()->where('is_internal', false)->count(), 'a message written into the merged ticket');
        $this->assertSame(Ticket::STATUS_CLOSED, $duplicate->status, 'the merged ticket was reopened');
    }

    /**
     * F2. The guest page hides what came in with a ticket no server vouched for (sender_vouched = false: a forged From, a
     * WHMCS guest import), judged by the ticket the link shows (Guest::shown). Merging moves those messages into a vouched
     * ticket of the same address, and its link then serves them on our domain: the #252 protection is undone.
     */
    public function test_an_unvouched_duplicates_mail_is_not_served_by_the_kept_tickets_guest_link(): void
    {
        $address = ['guest_name' => 'Maria', 'guest_email' => 'maria@example.com'];
        $kept = $this->ticket($address + ['opened_via' => 'email', 'sender_vouched' => true]);
        $forged = $this->ticket($address + ['opened_via' => 'email', 'sender_vouched' => false]);
        TicketMessage::withoutEvents(fn () => TicketMessage::query()->create(['ticket_id' => $forged->id, 'message' => 'Click http://evil.example/login', 'via' => 'email']));
        $token = basename((string) parse_url(GuestLinks::issue($kept), PHP_URL_PATH));
        $forgedToken = basename((string) parse_url(GuestLinks::issue($forged), PHP_URL_PATH));
        Livewire::test(Guest::class, ['token' => $forgedToken])->assertDontSee('evil.example');

        app(MergeTickets::class)->handle($forged, $kept, $this->staff);

        Livewire::test(Guest::class, ['token' => $token])->assertDontSee('evil.example');
        Livewire::test(Guest::class, ['token' => $forgedToken])->assertDontSee('evil.example');
    }

    /**
     * F3. The WHMCS import is re-runnable until the cut-over and keeps writing by whmcs_id. A ticket merged here and then
     * changed in WHMCS (a new reply, a status) is reopened by the re-run (status overwritten, merged_into_id kept) and the
     * new reply lands on the merged ticket: hidden from the customer's list, unanswerable by staff.
     */
    public function test_a_rerun_of_the_whmcs_import_does_not_reopen_or_write_into_a_ticket_merged_here(): void
    {
        $owner = User::factory()->create();
        $clientId = $owner->client()->id;
        $whmcs = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $whmcs->exec('CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, email TEXT, clientsonly INT, hidden INT, "order" INT, host TEXT, login TEXT, password TEXT)');
        $whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, date TEXT, title TEXT, message TEXT,
            status TEXT, urgency TEXT, admin TEXT, attachment TEXT, lastreply TEXT, merged_ticket_id INT DEFAULT 0)');
        $whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, name TEXT, email TEXT, date TEXT, message TEXT, admin TEXT, attachment TEXT)');
        $whmcs->exec('CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY, ticketid INT, admin TEXT, date TEXT, message TEXT, attachments TEXT)');
        $whmcs->exec("INSERT INTO tblticketdepartments VALUES (1, 'Support', 'support@myip.test', 0, 0, 1, '', '', '')");
        foreach ([41, 42] as $id) {
            $whmcs->prepare("INSERT INTO tbltickets (id, tid, did, userid, name, email, date, title, message, status, urgency, admin, attachment, lastreply)
                VALUES (?, ?, 1, ?, '', '', '2025-03-01 10:00:00', ?, ?, 'Answered', 'Medium', '', '', '2025-03-02 10:00:00')")
                ->execute([$id, (string) (100000 + $id), $clientId, "Ticket {$id}", "Opening {$id}"]);
        }
        $folder = storage_path('framework/testing/whmcs-merge-adv-' . uniqid());
        File::ensureDirectoryExists($folder);
        try {
            (new WhmcsTicketImport($whmcs, $folder))->run();
            $kept = Ticket::query()->where('whmcs_id', 41)->sole();
            $duplicate = Ticket::query()->where('whmcs_id', 42)->sole();
            app(MergeTickets::class)->handle($duplicate, $kept, $this->staff);

            // Before the cut-over the customer still answers the duplicate in WHMCS.
            $whmcs->exec("INSERT INTO tblticketreplies (id, tid, userid, name, email, date, message, admin, attachment)
                VALUES (900, 42, {$clientId}, '', '', '2025-03-03 10:00:00', 'Late answer in WHMCS', '', '')");
            $whmcs->exec("UPDATE tbltickets SET status = 'Customer-Reply', lastreply = '2025-03-03 10:00:00' WHERE id = 42");
            (new WhmcsTicketImport($whmcs, $folder))->run();
        } finally {
            File::deleteDirectory($folder);
        }

        $duplicate->refresh();
        $this->assertSame(Ticket::STATUS_CLOSED, $duplicate->status, 'the re-run reopened a ticket merged here');
        $this->assertSame(0, $duplicate->messages()->where('is_internal', false)->count(), 'the late reply landed on the merged ticket');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Tickets\Create as ClientTicketCreate;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\MergeTickets;
use App\Support\Tickets\QuarantineActions;
use App\Support\Tickets\TicketMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use RuntimeException;
use Tests\TestCase;

/**
 * Sweep 2026-11-07, area tickets-mail: the adversarial verifier's failing tests (TICKETS-MAIL-1 … 6).
 * Each fails on main at 00268d7f for the reason in its docblock.
 */
class SweepTicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true]);
    }

    private function pass(string $domain = 'example.com'): string
    {
        return "Authentication-Results: mymail.myip.test; dkim=pass header.d={$domain}; dmarc=pass header.from={$domain}";
    }

    private function route(array $headers, string $text = 'Words')
    {
        $raw = Eml::make($headers + ['Message-ID' => '<s' . uniqid() . '@example.com>'], $text);

        return app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw);
    }

    private function guestTicket(array $attributes = []): Ticket
    {
        return Ticket::query()->create($attributes + ['subject' => 'Help', 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium',
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'department_id' => $this->support->id]);
    }

    /**
     * TICKETS-MAIL-1: a quarantined mail attached to a ticket that was merged meanwhile lands on the merged shell (which
     * no one sees: the client page redirects to the survivor) and reopens it, instead of being refused or moved on.
     */
    public function test_a_quarantined_mail_attached_to_a_merged_ticket_is_not_stranded_on_it(): void
    {
        $staff = Staff::factory()->admin()->create();
        $first = $this->guestTicket();
        $survivor = $this->guestTicket(['subject' => 'Help again']);
        $quarantine = $this->route(['From' => 'maria@example.com', 'Subject' => "Re: [Ticket ID: {$first->reference}]", $this->pass()], 'Late reply')->quarantine;
        $this->assertSame($first->id, $quarantine->suggested_ticket_id);

        app(MergeTickets::class)->handle($first, $survivor, $staff);
        $this->assertTrue($first->fresh()->isMerged());

        try {
            app(QuarantineActions::class)->attach($quarantine->fresh(), $first->fresh(), $staff);
        } catch (InvalidArgumentException) {
            // Refused: fine.
        }

        $late = TicketMessage::query()->where('message', 'Late reply')->first();
        $this->assertTrue($late === null || $late->ticket_id === $survivor->id, 'the mail sits on the merged ticket, seen by no one');
        $this->assertSame(Ticket::STATUS_CLOSED, $first->fresh()->status, 'the merged shell was reopened');
    }

    /** TICKETS-MAIL-2a: the portal takes a message larger than ticket_messages.message (TEXT, 65,535 bytes) holds. */
    public function test_the_portal_refuses_a_message_too_long_for_its_column(): void
    {
        $user = User::factory()->create();

        $this->livewireAs($user)->test(ClientTicketCreate::class)
            ->set('department', $this->support->id)->set('subject', 'Log')->set('message', str_repeat('α', 35_000)) // 70,000 bytes
            ->set('priority', 'low')->call('create')
            ->assertHasErrors('message');
    }

    /** TICKETS-MAIL-2b: a ticket whose first message fails to be written is left behind, empty (no transaction). */
    public function test_the_portal_leaves_no_empty_ticket_when_its_message_fails(): void
    {
        $user = User::factory()->create();
        // What MariaDB's strict mode does to a message over 64 KB (SQLSTATE 22001), here for any message.
        TicketMessage::creating(fn () => throw new RuntimeException('Data too long for column message'));

        try {
            $this->livewireAs($user)->test(ClientTicketCreate::class)
                ->set('department', $this->support->id)->set('subject', 'Log')->set('message', 'x')
                ->set('priority', 'low')->call('create');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Ticket::query()->count(), 'an empty ticket with no message stays open');
    }

    /**
     * TICKETS-MAIL-3: a login with its own open account and a live grant on a closed one: its vouched mail should open in
     * the open account, as every client-area check counts only open accounts; accountOf() counts the closed one too.
     */
    public function test_a_grant_on_a_closed_account_does_not_make_a_logins_mail_a_guests(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $open = $user->client();
        $closed = User::factory()->create()->client();
        $closed->grantTo($user, ['tickets']);
        $closed->update(['closed_at' => now()]);

        $this->route(['From' => 'maria@example.com', 'Subject' => 'New one', $this->pass()]);

        $this->assertSame($open->id, Ticket::query()->where('subject', 'New one')->sole()->client_id, 'a guest ticket, not the open account\'s');
    }

    /** TICKETS-MAIL-3b: with only a grant on a closed account, the ticket opens in an account the login cannot open. */
    public function test_a_logins_only_closed_account_does_not_receive_its_mail(): void
    {
        $owner = User::factory()->create();
        $closed = $owner->client();
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $mine = $user->client();
        // Its own account gone (the WHMCS import leaves such logins): only the grant on the closed one stays live.
        DB::table('client_user')->where('user_id', $user->id)->where('client_id', $mine->id)->update(['revoked_at' => now()]);
        $closed->grantTo($user, ['tickets']);
        $closed->update(['closed_at' => now()]);

        $this->route(['From' => 'maria@example.com', 'Subject' => 'New one', $this->pass()]);

        $ticket = Ticket::query()->where('subject', 'New one')->sole();
        $this->assertNotSame($closed->id, $ticket->client_id, 'opened in a closed account its sender cannot see');
    }

    /** TICKETS-MAIL-4: staff in «Login as client» open a ticket: its first message is stored as the customer's words. */
    public function test_a_ticket_opened_while_logged_in_as_client_is_the_staff_members(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();
        $this->withSession($this->loginUser($customer, [Auth::guard('staff')->getName() => $staff->id, 'impersonating' => $customer->id]));

        Livewire::actingAs($customer)->test(ClientTicketCreate::class)
            ->set('department', $this->support->id)->set('subject', 'Cancel')->set('message', 'Please cancel my service')
            ->set('priority', 'low')->call('create')->assertHasNoErrors();

        $message = TicketMessage::query()->where('message', 'Please cancel my service')->sole();
        $this->assertSame([$staff->id, null], [$message->staff_id, $message->user_id], 'stored as the customer\'s own words');
    }

    /**
     * TICKETS-MAIL-5: the auto-close pages with each() (offset chunks) while closing the rows it filters on: past the
     * first 1000, eligible tickets are skipped in that run.
     */
    public function test_auto_close_closes_every_eligible_ticket_in_one_run(): void
    {
        config(['settings.cronjob_close_ticket' => 5]);
        $staff = Staff::factory()->admin()->create();
        $old = now()->subDays(30)->toDateTimeString();
        $count = 1001;
        $tickets = [];
        for ($i = 0; $i < $count; $i++) {
            $tickets[] = ['subject' => "T{$i}", 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium', 'created_at' => $old, 'updated_at' => $old];
        }
        foreach (array_chunk($tickets, 200) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }
        $messages = DB::table('tickets')->pluck('id')->map(fn ($id) => ['ticket_id' => $id, 'staff_id' => $staff->id, 'message' => 'Answer',
            'is_internal' => false, 'created_at' => $old, 'updated_at' => $old])->all();
        foreach (array_chunk($messages, 200) as $chunk) {
            DB::table('ticket_messages')->insert($chunk);
        }

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, Ticket::query()->where('status', Ticket::STATUS_ANSWERED)->count(), 'answered tickets skipped by the offset');
    }

    /**
     * TICKETS-MAIL-6: «We received it» to a typed-in address (Contact form, sender_vouched false) carries the visitor's
     * subject when the template names it; owner()/copies() replace it for such tickets, received() does not.
     */
    public function test_the_received_mail_to_an_unvouched_address_carries_nothing_the_visitor_wrote(): void
    {
        config(['settings.mail_disable' => false]);
        NotificationTemplate::query()->where('key', 'ticket_received')->update([
            'subject' => '[Ticket #{{ ticket.reference }}] {{ ticket.subject }}',
            'body' => 'Dear {{ ticket.guest_name }}, about {{ ticket.subject }}.',
        ]);
        $ticket = $this->guestTicket(['subject' => 'BUY-CHEAP-PILLS', 'guest_name' => 'SPAMNAME', 'guest_email' => 'victim@example.org',
            'opened_via' => 'form', 'sender_vouched' => false, 'status' => Ticket::STATUS_OPEN]);

        app(TicketMailer::class)->received($ticket, 'victim@example.org');

        $log = EmailLog::query()->where('template_key', 'ticket_received')->sole();
        $this->assertStringNotContainsString('BUY-CHEAP-PILLS', $log->subject . $log->body, 'the visitor\'s subject mailed, signed as us');
        $this->assertStringNotContainsString('SPAMNAME', (string) $log->body, 'the visitor\'s name mailed, signed as us');
    }
}

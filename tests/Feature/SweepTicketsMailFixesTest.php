<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketQuarantineResource\Pages\ListTicketQuarantine;
use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Livewire\Tickets\Create as ClientTicketCreate;
use App\Livewire\Tickets\Show as ClientTicketShow;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\MergeTickets;
use App\Support\Tickets\TicketMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\TestCase;

/** The fixes of the tickets-mail sweep beyond the verifier's own tests (SweepTicketsMailTest). */
class SweepTicketsMailFixesTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true]);
    }

    private function asStaff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function guestTicket(array $attributes = []): Ticket
    {
        return Ticket::query()->create($attributes + ['subject' => 'Help', 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium',
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'department_id' => $this->support->id]);
    }

    /** TICKETS-MAIL-2: a reply in the portal is held to the same length as a new ticket. */
    public function test_a_portal_reply_too_long_for_its_column_is_refused(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low']);

        $this->livewireAs($user)->test(ClientTicketShow::class, ['ticket' => $ticket])
            ->set('message', str_repeat('α', TicketMessage::MAX_LENGTH + 1))->call('save')->assertHasErrors('message');
        $this->assertSame(0, $ticket->messages()->count());

        $this->livewireAs($user)->test(ClientTicketShow::class, ['ticket' => $ticket])
            ->set('message', str_repeat('α', TicketMessage::MAX_LENGTH))->call('save')->assertHasNoErrors();
        $this->assertSame(1, $ticket->messages()->count(), 'the longest allowed fits');
    }

    /** TICKETS-MAIL-2: a subject longer than tickets.subject (varchar 255) is refused, not a 500 on MariaDB. */
    public function test_a_portal_subject_too_long_for_its_column_is_refused(): void
    {
        $this->livewireAs(User::factory()->create())->test(ClientTicketCreate::class)
            ->set('department', $this->support->id)->set('subject', str_repeat('s', 256))->set('message', 'Hi')
            ->set('priority', 'low')->call('create')->assertHasErrors('subject');
        $this->assertSame(0, Ticket::query()->count());
    }

    /** TICKETS-MAIL-2: the staff reply editor is held to the same length. */
    public function test_a_staff_reply_too_long_for_its_column_is_refused(): void
    {
        $this->asStaff();
        $ticket = $this->guestTicket(['status' => Ticket::STATUS_OPEN]);

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm(['message' => str_repeat('x', TicketMessage::MAX_LENGTH + 1)])->call('send')->assertHasErrors();
        $this->assertSame(0, $ticket->messages()->count());
    }

    /** TICKETS-MAIL-1: the attach form offers the ticket a suggested one was merged into, and the mail lands there. */
    public function test_the_attach_form_offers_the_survivor_of_a_merged_suggestion(): void
    {
        $staff = $this->asStaff();
        $first = $this->guestTicket();
        $survivor = $this->guestTicket(['subject' => 'Help again']);
        $raw = Eml::make(['From' => 'maria@example.com', 'Subject' => "Re: [Ticket ID: {$first->reference}]",
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com', 'Message-ID' => '<q' . uniqid() . '@example.com>'], 'Late reply');
        $quarantine = app(InboundRouter::class)->route($this->support, Parser::parse($raw), $raw)->quarantine;
        $this->assertSame($first->id, $quarantine->suggested_ticket_id);
        app(MergeTickets::class)->handle($first, $survivor, $staff);

        Livewire::test(ListTicketQuarantine::class)->mountTableAction('attach', $quarantine)
            ->assertTableActionDataSet(['ticket_id' => $survivor->id])
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertSame($survivor->id, TicketMessage::query()->where('message', 'Late reply')->sole()->ticket_id);
        $this->assertSame($survivor->id, $quarantine->fresh()->ticket_id);
        $this->assertSame(Ticket::STATUS_CLOSED, $first->fresh()->status);
    }

    /** TICKETS-MAIL-6: a new link to an unvouched guest ticket carries nothing the visitor wrote either. */
    public function test_the_link_mail_to_an_unvouched_address_carries_nothing_the_visitor_wrote(): void
    {
        config(['settings.mail_disable' => false]);
        NotificationTemplate::query()->where('key', 'ticket_link')->update([
            'subject' => '[Ticket #{{ ticket.reference }}] {{ ticket.subject }}',
            'body' => 'Dear {{ ticket.guest_name }}, about {{ ticket.subject }}: {{ ticket_url }}',
        ]);
        $ticket = $this->guestTicket(['subject' => 'BUY-CHEAP-PILLS', 'guest_name' => 'SPAMNAME', 'guest_email' => 'victim@example.org',
            'opened_via' => 'form', 'sender_vouched' => false, 'status' => Ticket::STATUS_OPEN]);

        app(TicketMailer::class)->link($ticket);

        $log = EmailLog::query()->where('template_key', 'ticket_link')->sole();
        $this->assertStringContainsString($ticket->reference, $log->subject);
        $this->assertStringNotContainsString('BUY-CHEAP-PILLS', $log->subject . $log->body);
        $this->assertStringNotContainsString('SPAMNAME', (string) $log->body);
    }

    /** TICKETS-MAIL-6: a vouched ticket's mails still name what it is about. */
    public function test_the_received_mail_to_a_vouched_address_keeps_the_subject(): void
    {
        config(['settings.mail_disable' => false]);
        NotificationTemplate::query()->where('key', 'ticket_received')->update(['body' => 'About {{ ticket.subject }}.']);
        $ticket = $this->guestTicket(['subject' => 'Router down', 'sender_vouched' => true, 'status' => Ticket::STATUS_OPEN]);

        app(TicketMailer::class)->received($ticket, 'maria@example.com');

        $this->assertStringContainsString('Router down', (string) EmailLog::query()->where('template_key', 'ticket_received')->sole()->body);
    }
}

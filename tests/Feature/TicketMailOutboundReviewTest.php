<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Independent review of PR #249 (A3): failing tests for what was found. */
class TicketMailOutboundReviewTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test']);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function guestTicket(string $inboundId): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => $inboundId]);

        return $ticket;
    }

    /**
     * A customer's mail program (or the customer) chose a Message-ID Symfony's addIdHeader refuses (`a..b@x`, `.a@x`, `a@b@c`):
     * it passes MessageId::normalise and the «@» check, so the listener throws RfcComplianceException on every attempt
     * and no reply on that ticket ever reaches the customer.
     */
    public function test_an_odd_inbound_message_id_never_stops_our_reply(): void
    {
        foreach (['a..b@example.com', '.lead@example.com', 'a@b@example.com'] as $i => $odd) {
            $ticket = $this->guestTicket($odd);
            TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => "Reply {$i}"]);
        }

        $this->assertCount(3, $this->sent()->filter(fn (Email $email) => $email->getTo()[0]->getAddress() === 'maria@example.com'));
    }

    /**
     * A department address on an IDN domain: the id is generated with that (non-ASCII) domain, isOurs() refuses it in
     * the listener, Symfony puts a random Message-ID on the mail, and the customer's answer can never thread.
     */
    public function test_the_message_id_we_keep_is_the_one_we_send_also_for_an_idn_domain(): void
    {
        $this->support->update(['email' => 'support@δοκιμή.test']);
        $ticket = $this->guestTicket('orig@example.com');
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Reply']);

        $kept = TicketMailId::query()->sole()->message_id;
        $header = $this->sent()->sole()->getHeaders()->get('Message-ID');
        $this->assertNotNull($header, 'the listener skipped the mail: Symfony will give it a random id');
        $this->assertSame($kept, trim($header->getBodyAsString(), '<>'));
    }

    /** Assigned to a staff member who may not see tickets (or has no email): nobody is told, not even the department's. */
    public function test_a_ticket_assigned_to_staff_who_cannot_be_told_falls_back_to_the_department(): void
    {
        $role = Role::create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']]);
        $agent = Staff::factory()->create(['email' => 'agent@myip.test', 'role_id' => $role->id]);
        $this->support->notifyStaff()->sync([$agent->id]);
        $billing = Staff::factory()->create(['email' => 'billing@myip.test', 'role_id' => Role::create(['name' => 'Billing', 'permissions' => []])->id]);
        $ticket = $this->guestTicket('orig@example.com');
        $ticket->update(['assigned_to' => $billing->id]);
        $before = $this->sent()->count();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Customer again', 'via' => TicketMessage::VIA_EMAIL]);

        $this->assertContains('agent@myip.test', $this->sent()->slice($before)->map(fn (Email $email) => $email->getTo()[0]->getAddress())->all());
    }
}

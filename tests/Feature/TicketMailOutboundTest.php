<?php

namespace Tests\Feature;

use App\Admin\Resources\TicketResource\Pages\EditTicket;
use App\Mail\Mail;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\InboundRouter;
use App\Support\Tickets\TicketMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Mail out of tickets, PR A3 (docs/tickets.md §3 Α, Γ, Ε): a Message-ID of ours kept before it is sent, the answer to it
 * joining the ticket, only the new reply, to the owner only; «we received it» only to a vouched address; staff told.
 */
class TicketMailOutboundTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']); // a truncating test may have taken the migration's (#242)
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function staffReply(Ticket $ticket, string $text = 'We fixed it.'): TicketMessage
    {
        return TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create(['first_name' => 'Nikos', 'last_name' => 'Staff'])->id,
            'message' => $text]);
    }

    private function guestTicket(): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'orig@example.com']);

        return $ticket;
    }

    public function test_a_staff_reply_to_a_guest_goes_with_a_message_id_of_ours(): void
    {
        $ticket = $this->guestTicket();
        $reply = $this->staffReply($ticket);

        $key = TicketMailId::query()->sole();
        $this->assertTrue(MessageId::isOurs($key->message_id));
        $this->assertStringEndsWith('@myip.test', $key->message_id);
        $this->assertSame([$reply->id, TicketMailId::REPLY, 'maria@example.com'], [$key->ticket_message_id, $key->kind, $key->recipient]);

        $mail = $this->sent()->sole();
        $headers = $mail->getHeaders();
        $this->assertSame(['maria@example.com'], array_map(fn ($address) => $address->getAddress(), $mail->getTo()));
        $this->assertSame("<{$key->message_id}>", $headers->get('Message-ID')->getBodyAsString());
        $this->assertSame('<orig@example.com>', $headers->get('In-Reply-To')->getBodyAsString(), 'it answers the customer\'s mail');
        $this->assertSame(['support@myip.test'], array_map(fn ($address) => $address->getAddress(), $mail->getReplyTo()));
        $this->assertNotSame('support@myip.test', $mail->getFrom()[0]->getAddress(), 'ours: a submission server refuses another sender (review of #249)');
        $this->assertSame($ticket->reference, $headers->get('X-MyCS-Ticket')->getBodyAsString());
        $this->assertNull($headers->get('Auto-Submitted'), 'a person wrote it');
        $this->assertStringContainsString('Nikos Staff', (string) $mail->getHtmlBody(), 'the author by name');
        $this->assertStringContainsString('We fixed it.', (string) $mail->getHtmlBody());
        $this->assertStringContainsString($ticket->reference, (string) $mail->getSubject());
    }

    /** The answer to our mail joins the ticket by its id (the key A2 waited for). */
    public function test_the_answer_to_our_mail_joins_the_ticket(): void
    {
        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        $key = TicketMailId::query()->sole()->message_id;

        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<ans@example.com>', 'In-Reply-To' => "<{$key}>",
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com'], 'Thanks!');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame('Thanks!', $ticket->messages()->latest('id')->first()->message);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
    }

    public function test_a_retry_sends_the_same_message_id(): void
    {
        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        $mail = new Mail(NotificationTemplate::query()->where('key', 'guest_ticket_message')->firstOrFail(),
            ['thread_message_id' => TicketMailId::query()->sole()->message_id, 'ticket' => $ticket]);

        \Illuminate\Support\Facades\Mail::to('maria@example.com')->send($mail);
        \Illuminate\Support\Facades\Mail::to('maria@example.com')->send($mail);

        $ids = $this->sent()->map(fn (Email $email) => $email->getHeaders()->get('Message-ID')->getBodyAsString())->unique();
        $this->assertCount(1, $ids);
        $this->assertSame(1, TicketMailId::query()->count());
    }

    public function test_an_accounts_reply_goes_to_its_owner_only_with_the_new_words_only(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $ticket = Ticket::query()->create(['client_id' => $owner->client()->id, 'subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'message' => 'OLD-SECRET-HISTORY']);
        $this->staffReply($ticket, 'The new answer');

        $mail = $this->sent()->filter(fn (Email $email) => str_contains((string) $email->getSubject(), $ticket->reference))->sole();
        $this->assertSame(['owner@example.com'], array_map(fn ($address) => $address->getAddress(), $mail->getTo()));
        $this->assertSame([], $mail->getCc());
        $this->assertStringNotContainsString('OLD-SECRET-HISTORY', (string) $mail->getHtmlBody());
        $this->assertTrue(MessageId::isOurs(trim($mail->getHeaders()->get('Message-ID')->getBodyAsString(), '<>')));
        $this->assertSame('owner@example.com', TicketMailId::query()->sole()->recipient);
    }

    public function test_a_note_and_a_customer_message_send_the_customer_nothing(): void
    {
        $ticket = $this->guestTicket();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Note', 'is_internal' => true]);

        $this->assertSame(0, TicketMailId::query()->count());
        $this->assertCount(0, $this->sent()->filter(fn (Email $email) => $email->getTo()[0]->getAddress() === 'maria@example.com'));
    }

    /** §3 Ε: «we received it» once, automatic, and only to an address our server vouched for. */
    public function test_we_received_it_only_to_a_vouched_address(): void
    {
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<n1@example.com>', 'Subject' => 'New',
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com']);
        $this->mailbox->unseen[] = Eml::make(['From' => 'victim@example.org', 'Message-ID' => '<n2@example.com>', 'Subject' => 'Forged',
            'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.org']);
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $received = $this->sent()->filter(fn (Email $email) => $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-replied');
        $this->assertSame(['maria@example.com'], $received->map(fn (Email $email) => $email->getTo()[0]->getAddress())->values()->all());
        $this->assertSame('<n1@example.com>', $received->first()->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(1, TicketMailId::query()->where('kind', TicketMailId::RECEIVED)->count());

        // The answer to «we received it» joins its ticket too.
        $key = TicketMailId::query()->where('kind', TicketMailId::RECEIVED)->sole();
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<n3@example.com>', 'In-Reply-To' => "<{$key->message_id}>",
            'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com'], 'One more thing');
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->assertSame('One more thing', $key->ticket->messages()->latest('id')->first()->message);
    }

    public function test_staff_are_told_of_new_tickets_and_replies_never_of_their_own(): void
    {
        $role = Role::create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']]);
        $agent = Staff::factory()->create(['email' => 'agent@myip.test', 'role_id' => $role->id]);
        $noRights = Staff::factory()->create(['email' => 'billing@myip.test', 'role_id' => Role::create(['name' => 'Billing', 'permissions' => []])->id]);
        $this->support->notifyStaff()->sync([$agent->id, $noRights->id]);
        config(['settings.mail_disable' => false]);

        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Customer again <script>x</script>', 'via' => TicketMessage::VIA_EMAIL]);

        $toStaff = fn () => $this->sent()->filter(fn (Email $email) => str_ends_with($email->getTo()[0]->getAddress(), '@myip.test'))->values();
        $this->assertSame(['agent@myip.test', 'agent@myip.test'], $toStaff()->map(fn (Email $email) => $email->getTo()[0]->getAddress())->all(),
            'the new ticket and the reply; not the staff reply; not to staff without the tickets permission');
        $this->assertStringStartsWith('New ticket', (string) $toStaff()->first()->getSubject());
        $this->assertSame('auto-generated', $toStaff()->first()->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
        $this->assertStringNotContainsString('<script>', (string) $toStaff()->last()->getHtmlBody());

        // Another reply within 10 minutes is the same notice; after them, a new one.
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Again', 'via' => TicketMessage::VIA_EMAIL]);
        $this->assertCount(2, $toStaff());
        $this->travel(TicketMailer::NOTICE_MINUTES + 1)->minutes();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Later', 'via' => TicketMessage::VIA_EMAIL]);
        $this->assertCount(3, $toStaff());

        // Assigned: only to whom it is assigned.
        $other = Staff::factory()->create(['email' => 'other@myip.test', 'role_id' => $role->id]);
        $ticket->update(['assigned_to' => $other->id]);
        $this->travel(TicketMailer::NOTICE_MINUTES + 1)->minutes();
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Third', 'via' => TicketMessage::VIA_EMAIL]);
        $this->assertSame('other@myip.test', $this->sent()->last()->getTo()[0]->getAddress());
    }

    public function test_the_migration_goes_down_and_up_and_names_the_author(): void
    {
        $migration = require database_path('migrations/2026_12_07_000001_tickets_outbound_mail.php');
        $migration->down();
        $this->assertStringContainsString('ticketMessage.user.name', (string) \DB::table('notification_templates')->where('key', 'new_ticket_message')->value('body'));

        $migration->up();

        $this->assertStringContainsString('{{ author_name }}', (string) \DB::table('notification_templates')->where('key', 'new_ticket_message')->value('body'));
        $this->assertStringContainsString('{{ author_name }}', (string) \DB::table('notification_templates')->where('key', 'new_ticket_message')->value('translations'));
        $this->assertTrue(\DB::table('notification_templates')->whereIn('key', ['guest_ticket_message', 'ticket_received'])->count() === 2);
    }

    /** Reviews of #249: the template's Cc and Bcc never get a ticket's mail (its Message-ID is a key). */
    public function test_a_ticket_mail_is_copied_to_no_one(): void
    {
        NotificationTemplate::query()->where('key', 'guest_ticket_message')->update(['cc' => ['boss@myip.test'], 'bcc' => ['audit@myip.test']]);
        $this->staffReply($this->guestTicket());

        $mail = $this->sent()->sole();
        $this->assertSame([[], []], [$mail->getCc(), $mail->getBcc()]);
    }

    /** One sender, one key: at most REPLIES_PER_HOUR replies an hour join; the rest wait for staff. */
    public function test_replies_by_mail_are_limited_per_sender(): void
    {
        RateLimiter::clear('ticket-mail-reply:maria@example.com');
        $ticket = $this->guestTicket();
        $this->staffReply($ticket);
        $key = TicketMailId::query()->sole()->message_id;
        for ($i = 0; $i <= InboundRouter::REPLIES_PER_HOUR; $i++) {
            $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => "<r{$i}@example.com>", 'In-Reply-To' => "<{$key}>",
                'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com'], "Reply {$i}");
        }
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(InboundRouter::REPLIES_PER_HOUR, $ticket->messages()->where('via', TicketMessage::VIA_EMAIL)->count() - 1);
        $this->assertSame('rate_limited', TicketQuarantine::query()->sole()->reason);
    }

    /** A guest ticket opened by a mail our server did not vouch for says so to staff. */
    public function test_an_unvouched_sender_is_told_to_staff(): void
    {
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<u1@example.com>',
            'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.com']);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $ticket = Ticket::query()->sole();
        $this->assertFalse($ticket->sender_vouched);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('it may be forged');
    }

    /** The migration also finds the author's old name as staff spaced it. */
    public function test_the_author_is_named_also_in_a_template_spaced_otherwise(): void
    {
        $migration = require database_path('migrations/2026_12_07_000001_tickets_outbound_mail.php');
        \DB::table('notification_templates')->where('key', 'new_ticket_message')->update(['body' => 'From {{ticketMessage.user.name}}:']);

        $migration->down();
        $migration->up();

        $this->assertSame('From {{ author_name }}:', \DB::table('notification_templates')->where('key', 'new_ticket_message')->value('body'));
    }
}

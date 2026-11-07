<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Adversarial verification of the f-support findings (sweep 2). Each test fails on main at 55e34ff9 for the reason
 * reported. F-SUPPORT-1 (auto-close racing a customer reply) needs two MariaDB connections and is not here.
 */
class Sweep2FSupportTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD',
            'settings.mail_disable' => false]);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
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

    /**
     * F-SUPPORT-2. The first poll takes every unseen mail in the mailbox, however old: a year-old mail left unread (a copy
     * WHMCS's piping never marked read) becomes a new ticket today, and its sender gets «we received it» for it.
     */
    public function test_a_year_old_unseen_mail_is_not_opened_as_a_new_ticket_on_the_first_poll(): void
    {
        $this->freezeTime();
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<old1@example.com>',
            'Subject' => 'Old question', 'Date' => now()->subYear()->toRfc2822String(),
            'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com']);

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(0, TicketMailId::query()->where('kind', TicketMailId::RECEIVED)->count(),
            'no «we received it» for a mail sent a year ago');
        $this->assertSame(0, Ticket::query()->count(), 'a year-old mail is not a new ticket of today');
    }

    /**
     * F-SUPPORT-3. A department with polling on but no email: the staff reply carries no Reply-To, so the customer's
     * answer goes to the From (mail_from_address), which no department reads.
     */
    public function test_a_staff_reply_from_a_polled_department_without_email_still_points_replies_to_its_mailbox(): void
    {
        $this->support->update(['email' => null]);
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);

        $mail = $this->sent()->filter(fn (Email $email) => ($email->getTo()[0] ?? null)?->getAddress() === 'maria@example.com')->sole();
        $this->assertNotSame('support@myip.test', $mail->getFrom()[0]->getAddress(), 'the From is not the polled mailbox');
        $this->assertSame(['support@myip.test'], array_map(fn ($address) => $address->getAddress(), $mail->getReplyTo()),
            'the answer must reach the mailbox we read, not the From');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Contact;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Services\Captcha\Altcha;
use App\Services\Mail\MailAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Sweep 7, tickets and mail: adversarial verification (S7-TICKETS-MAIL-2, 3) and their fixes' other sides.
 * S7-TICKETS-MAIL-1 is in Sweep7AuthenticationResultsTest.
 */
class Sweep7TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    private string $ip = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(string $payload): bool
            {
                return $payload === 'solved';
            }
        });
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'disabled']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        TicketDepartment::query()->where('id', '!=', $this->support->id)->update(['hidden' => true]);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    /** S7-TICKETS-MAIL-2: the batch after an outage sends a ticket reply again without its thread headers. */
    public function test_s7_2_a_ticket_reply_resent_after_an_outage_keeps_its_message_id_and_reply_to(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'orig@example.com']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);

        $key = TicketMailId::query()->sole();
        $first = $this->sent()->sole();
        $this->assertSame("<{$key->message_id}>", $first->getHeaders()->get('Message-ID')->getBodyAsString());
        $log = EmailLog::query()->sole();

        // The outage: it never went out (the queue's tries are over).
        DB::table('email_logs')->where('id', $log->id)->update(['status' => EmailLog::STATUS_FAILED, 'sending_at' => null]);
        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        $this->assertCount(2, $this->sent(), 'the batch sent it again');
        $again = $this->sent()->last();
        $this->assertSame(['maria@example.com'], array_map(fn ($address) => $address->getAddress(), $again->getTo()));
        $this->assertSame(['support@myip.test'], array_map(fn ($address) => $address->getAddress(), $again->getReplyTo()),
            'the answer to the resent mail goes to the department, not to the From no one reads');
        $this->assertSame("<{$key->message_id}>", $again->getHeaders()->get('Message-ID')?->getBodyAsString(),
            'the resent mail carries the Message-ID kept for it, so the answer joins the ticket');
        $this->assertSame((string) $ticket->reference, $again->getHeaders()->get('X-MyCS-Ticket')?->getBodyAsString());
        $this->assertSame('<orig@example.com>', $again->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        // The guest's way in: masked in the log, a new link in the mail.
        $this->assertStringContainsString('href="********"', $log->body);
        $this->assertStringNotContainsString('********', (string) $again->getHtmlBody());
        $this->assertMatchesRegularExpression('#href="[^"]+/t/[^"]+"#', (string) $again->getHtmlBody());
        $resent = EmailLog::query()->where('resent_from_id', $log->id)->sole();
        $this->assertSame([$key->message_id, EmailLog::TRIGGER_BATCH], [$resent->message_id, $resent->trigger]);
        $this->assertStringContainsString('href="********"', $resent->body, 'the log of the resend keeps no link either');
    }

    /** Staff's «Resend» of one that went out: a new Message-ID of ours (never the same twice), still the ticket's mail. */
    public function test_s7_2_staffs_resend_of_a_delivered_reply_is_a_ticket_mail_with_a_new_message_id(): void
    {
        [$ticket, $log, $key] = $this->guestReply();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);

        MailAudit::resend($log, 'MARIA@example.com', false, Staff::query()->first());

        $again = $this->sent()->last();
        $id = trim((string) $again->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
        $this->assertNotSame($key->message_id, $id);
        $this->assertSame($ticket->id, TicketMailId::query()->where('message_id', $id)->sole()->ticket_id, 'an answer to it finds the ticket');
        $this->assertSame(['support@myip.test'], array_map(fn ($address) => $address->getAddress(), $again->getReplyTo()));
        $this->assertStringNotContainsString('********', (string) $again->getHtmlBody());
    }

    /** To another address it is a copy of the log: no key of the ticket (its Message-ID, the guest's link) goes there. */
    public function test_s7_2_a_resend_to_another_address_carries_no_key_of_the_ticket(): void
    {
        [, $log] = $this->guestReply();

        MailAudit::resend($log, 'someone-else@example.com', false, Staff::query()->first());

        $again = $this->sent()->last();
        $this->assertSame([], $again->getReplyTo());
        $this->assertNull($again->getHeaders()->get('X-MyCS-Ticket'));
        $this->assertStringContainsString('href="********"', (string) $again->getHtmlBody());
        $this->assertSame(1, TicketMailId::query()->count());
    }

    /** The other side: the limit of everyone still holds, counted over the submissions the other limits let through. */
    public function test_s7_3_the_limit_of_everyone_counts_accepted_submissions(): void
    {
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $this->ip));
        RateLimiter::clear('contact-all');
        $submit = function (string $ip, string $email) {
            $this->ip = $ip;
            RateLimiter::clear('contact-ip:' . $ip);
            RateLimiter::clear('contact-email:' . $email);
            $test = Livewire::test(Contact::class);
            foreach (['captcha' => 'solved', 'name' => 'Maria', 'email' => $email, 'subject' => 'Hello', 'message' => 'Hello'] as $field => $value) {
                $test->set($field, $value);
            }

            return $test->call('submit');
        };
        for ($i = 1; $i <= Contact::PER_HOUR; $i++) {
            $submit("198.51.100.{$i}", "visitor{$i}@example.com")->assertHasNoErrors();
        }
        $this->assertSame(Contact::PER_HOUR, Ticket::query()->count());

        $submit('198.51.100.200', 'late@example.com')->assertHasErrors('email');
        $this->assertSame(Contact::PER_HOUR, Ticket::query()->count());
    }

    /** @return array{Ticket, EmailLog, TicketMailId} a guest's ticket staff answered, the mail's log and its kept id */
    private function guestReply(): array
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'orig@example.com']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);

        return [$ticket, EmailLog::query()->sole(), TicketMailId::query()->sole()];
    }

    /** S7-TICKETS-MAIL-3: attempts refused by their own address's limit still fill the limit of everyone. */
    public function test_s7_3_one_address_cannot_close_the_form_for_everyone(): void
    {
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $this->ip));
        RateLimiter::clear('contact-all');
        $submit = function (string $ip, string $email) {
            $this->ip = $ip;
            RateLimiter::clear('contact-email:' . $email);
            $test = Livewire::test(Contact::class);
            foreach (['captcha' => 'solved', 'name' => 'Maria', 'email' => $email, 'subject' => 'Hello', 'message' => 'Hello'] as $field => $value) {
                $test->set($field, $value);
            }

            return $test->call('submit');
        };
        RateLimiter::clear('contact-ip:198.51.100.7');
        RateLimiter::clear('contact-ip:198.51.100.99');

        for ($i = 1; $i <= Contact::PER_HOUR; $i++) {
            $submit('198.51.100.7', "bot{$i}@example.com");
        }
        $this->assertSame(Contact::PER_IP, Ticket::query()->count(), 'the one address opened its five, the rest were refused');

        $submit('198.51.100.99', 'maria@example.com')->assertHasNoErrors()->assertSet('sent', true);
        $this->assertSame(Contact::PER_IP + 1, Ticket::query()->count(), 'a visitor from elsewhere still reaches us');
    }
}

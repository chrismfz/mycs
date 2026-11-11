<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MyCS\TicketMail\HtmlToText;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Sweep 8, adversarial verifier for tickets-mail: one small mail from anyone costs seconds to minutes of CPU, in the
 * poller (S8-TICKETS-MAIL-1) or on every view of its ticket (S8-TICKETS-MAIL-2). The sizes here are a fraction of
 * what the mailbox accepts, so the tests fail in seconds, not in minutes; the cost grows with the square of the size.
 */
class Sweep8TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    private function receive(string $text, ?string $html = null): float
    {
        $this->mailbox->unseen[] = Eml::make(['Message-ID' => '<s8.' . bin2hex(random_bytes(6)) . '@example.com>',
            'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com'], $text, [], $html);
        $started = microtime(true);
        $this->artisan('app:fetch-emails')->assertSuccessful();

        return microtime(true) - $started;
    }

    /**
     * S8-TICKETS-MAIL-1: every `<head>` without its `</head>` makes the lazy `.*?` read to the end of the document, so
     * the cost is the square of the size. 60 KB take seconds here; the ~990 KB that stay under pcre.backtrack_limit
     * take many minutes, in which the poller reads no other mail.
     */
    public function test_an_html_only_mail_of_unclosed_tags_is_turned_to_text_in_bounded_time(): void
    {
        $html = str_repeat('<head>', 10_000) . 'hello'; // 60 KB

        $started = microtime(true);
        HtmlToText::convert($html);
        $this->assertLessThan(1.5, microtime(true) - $started, 'HtmlToText::convert() of 60 KB of unclosed <head> tags');
    }

    /** The same through the poller: the mail is parsed before any sender rule is asked. */
    public function test_the_poller_is_not_held_by_one_small_html_only_mail(): void
    {
        $seconds = $this->receive('', str_repeat('<head>', 10_000) . 'hello');

        $this->assertLessThan(2.5, $seconds, 'app:fetch-emails with one 60 KB HTML-only mail');
    }

    /**
     * S8-TICKETS-MAIL-2: a mailed message of «>» alone is one blockquote inside the other, as many as its characters
     * (no max_nesting_level), rendered again on every view and poll of the ticket's page.
     */
    public function test_a_mailed_message_of_quote_marks_renders_in_bounded_time_and_depth(): void
    {
        $this->receive(str_repeat('>', 30_000)); // half of what a mailed message may hold

        $ticket = Ticket::query()->sole();
        $message = (string) $ticket->messages()->sole()->message;
        $this->assertSame(30_000, strlen($message), 'the message is stored as mailed');

        $started = microtime(true);
        $html = TicketText::html($message);
        $seconds = microtime(true) - $started;

        $this->assertLessThanOrEqual(TicketText::MAX_NESTING, substr_count($html, '<blockquote'), 'blockquotes nested in one message');
        $this->assertLessThan(1.5, $seconds, 'TicketText::html() of a 30,000-character message');
    }
}

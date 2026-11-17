<?php

namespace Tests\Feature;

use App\Console\Commands\FetchTicketMail;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketQuarantine;
use App\Support\Retention;
use App\Support\Tickets\DkimDns;
use App\Support\Tickets\InboundRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MyCS\TicketMail\Dkim;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** The audit of tickets and chat (2026-11-17): what anyone on the internet could do to the ticket mailboxes by mail alone. */
class TicketMailAbuseAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    protected function tearDown(): void
    {
        DkimDns::$resolver = null;
        parent::tearDown();
    }

    /** @param  array<int|string, string>  $headers */
    private function mail(array $headers = [], string $text = 'Hello there'): string
    {
        static $n = 0;

        return Eml::make($headers + ['Message-ID' => '<abuse' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'], $text);
    }

    /** Each sender had its own hour, so a From that changes with every mail had none, and each new ticket mails staff. */
    public function test_mail_no_one_vouched_for_opens_only_so_many_tickets_an_hour_in_a_department(): void
    {
        for ($i = 0; $i <= InboundRouter::UNVOUCHED_NEW_PER_HOUR; $i++) {
            $this->mailbox->unseen[] = $this->mail(['From' => "stranger{$i}@flood{$i}.example"]);
        }
        // Mail our server vouched for is not held back by the strangers' flood.
        $this->mailbox->unseen[] = $this->mail(['Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com',
            'From' => 'maria@example.com', 'Subject' => 'A real one']);
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(InboundRouter::UNVOUCHED_NEW_PER_HOUR + 1, Ticket::query()->count());
        $this->assertSame(1, Ticket::query()->where('subject', 'A real one')->count());
        $held = TicketQuarantine::query()->sole();
        $this->assertSame(['rate_limited', 'stranger' . InboundRouter::UNVOUCHED_NEW_PER_HOUR . '@flood' . InboundRouter::UNVOUCHED_NEW_PER_HOUR . '.example'],
            [$held->reason, $held->from_email]);
        $this->assertSame([], $this->mailbox->unseen);

        // Another department has its own hour.
        $sales = TicketDepartment::query()->create(['name' => 'Sales', 'email' => 'sales@myip.test', 'imap_host' => 'imap.myip.test',
            'imap_username' => 'sales@myip.test', 'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->support->update(['polling' => false]);
        $this->mailbox->unseen[] = $this->mail(['From' => 'buyer@shop.example', 'Subject' => 'To sales']);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->assertSame($sales->id, Ticket::query()->where('subject', 'To sales')->sole()->department_id);
    }

    /**
     * Out of memory is no Throwable: the mail never reached the three-attempts count, stayed unread and was the first
     * read on every run, so nothing after it was ever read. Its starts are counted before it is read.
     */
    public function test_a_mail_that_stopped_the_reading_three_times_is_kept_by_its_headers_and_the_next_mail_is_read(): void
    {
        $killer = $this->mail(['From' => 'eve@evil.example', 'Subject' => 'Kills the run'], str_repeat('x', 2000));
        $this->mailbox->unseen[] = $killer;
        $this->mailbox->unseen[] = $this->mail(['From' => 'maria@example.com', 'Subject' => 'After it']);
        // Three runs began to read it and died there.
        Cache::put('ticket-mail-started:' . $this->support->id . ':' . sha1($killer), FetchTicketMail::ATTEMPTS, now()->addDay());

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $held = TicketQuarantine::query()->sole();
        $this->assertSame(['too_large', 'eve@evil.example', 'Kills the run'], [$held->reason, $held->from_email, $held->subject]);
        $this->assertSame('', (string) $held->preview, 'its body was not read');
        $this->assertSame('After it', Ticket::query()->sole()->subject);
        $this->assertSame([], $this->mailbox->unseen);
    }

    public function test_a_mail_is_read_whole_on_its_first_three_starts(): void
    {
        $raw = $this->mail(['From' => 'maria@example.com', 'Subject' => 'Second try']);
        $this->mailbox->unseen[] = $raw;
        Cache::put('ticket-mail-started:' . $this->support->id . ':' . sha1($raw), FetchTicketMail::ATTEMPTS - 1, now()->addDay());

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame('Second try', Ticket::query()->sole()->subject);
        $this->assertSame(0, TicketQuarantine::query()->count());
    }

    /** Automated mail is not even counted in the menu, so no one handles it: it kept its whole raw message for ever. */
    public function test_automated_mail_waiting_in_the_quarantine_goes_by_when_it_came(): void
    {
        Storage::fake(TicketQuarantine::DISK);
        $row = function (string $reason, string $status, int $daysAgo): TicketQuarantine {
            $path = 'tickets/quarantine/' . $reason . $daysAgo . '.eml';
            Storage::disk(TicketQuarantine::DISK)->put($path, 'raw');
            $row = TicketQuarantine::query()->create(['department_id' => $this->support->id, 'reason' => $reason, 'status' => $status, 'raw_path' => $path, 'raw_size' => 3]);
            DB::table('ticket_quarantine')->where('id', $row->id)->update(['created_at' => now()->subDays($daysAgo)]);

            return $row;
        };
        $old = $row('automated', TicketQuarantine::PENDING, 100);
        $recent = $row('automated', TicketQuarantine::PENDING, 10);
        $waiting = $row('not_owner', TicketQuarantine::PENDING, 100);

        $this->assertSame(1, Retention::pruneBefore('ticket_quarantine', now()->subDays(90)));

        $this->assertSame([$recent->id, $waiting->id], TicketQuarantine::query()->orderBy('id')->pluck('id')->all());
        Storage::disk(TicketQuarantine::DISK)->assertMissing((string) $old->raw_path);
        Storage::disk(TicketQuarantine::DISK)->assertExists((string) $waiting->raw_path);
    }

    /** The name looked up is the sender's choice, and the resolver waits its whole timeout for servers that never answer. */
    public function test_a_domain_whose_dkim_lookup_failed_is_not_asked_again_and_many_failures_stop_the_lookups(): void
    {
        $asked = [];
        DkimDns::$resolver = function (string $name) use (&$asked) {
            $asked[] = $name;

            return str_ends_with($name, '.good.example') ? ['v=DKIM1; p=abc'] : null;
        };

        $this->assertSame(['v=DKIM1; p=abc'], DkimDns::txt('s1._domainkey.good.example'));
        $this->assertNull(DkimDns::txt('s1._domainkey.dead0.example'));
        $this->assertNull(DkimDns::txt('other._domainkey.DEAD0.example'), 'the domain, whatever the selector');
        $this->assertSame(['s1._domainkey.good.example', 's1._domainkey.dead0.example'], $asked);

        for ($i = 1; $i < DkimDns::FAILURES; $i++) {
            $this->assertNull(DkimDns::txt("s._domainkey.dead{$i}.example"));
        }
        $this->assertCount(1 + DkimDns::FAILURES, $asked);
        $this->assertNull(DkimDns::txt('s._domainkey.good.example'), 'no lookup at all for a while: not vouched for by our check, never refused');
        $this->assertCount(1 + DkimDns::FAILURES, $asked);

        $this->travel(DkimDns::WINDOW_SECONDS + 1)->seconds();
        $this->assertSame(['v=DKIM1; p=abc'], DkimDns::txt('s._domainkey.good.example'));
        $this->assertNull(DkimDns::txt('s._domainkey.dead0.example'));
        $this->assertCount(2 + DkimDns::FAILURES, $asked, 'dead0 is still remembered');
    }

    public function test_the_tickets_dkim_check_asks_through_the_bounded_lookup(): void
    {
        $asked = 0;
        DkimDns::$resolver = function () use (&$asked) {
            $asked++;

            return null;
        };
        $raw = "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.org; s=sel; h=from:subject; bh=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=; b=AAAA\r\n"
            . "From: eve@example.org\r\nSubject: x\r\n\r\n";

        $this->assertSame('dkim_dns_failure', app(Dkim::class)->verdict($raw, 'eve@example.org')->reason);
        app(Dkim::class)->verdict($raw, 'eve@example.org');
        $this->assertSame(1, $asked);
    }
}

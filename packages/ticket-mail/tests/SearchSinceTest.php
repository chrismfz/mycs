<?php

namespace MyCS\TicketMail\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MyCS\TicketMail\Imap\ImapEngineMailbox;
use MyCS\TicketMail\Imap\MailboxSettings;
use PHPUnit\Framework\TestCase;

/** S6-TICKETS-MAIL-2: the poll asks the server only for unseen mail SINCE two days before the department's date. */
final class SearchSinceTest extends TestCase
{
    private function settings(?DateTimeImmutable $since): MailboxSettings
    {
        return new MailboxSettings('imap.example.com', 993, 'ssl', 'support@example.com', 'secret', since: $since);
    }

    public function test_no_date_asks_for_every_unseen_mail(): void
    {
        $this->assertNull(ImapEngineMailbox::searchSince($this->settings(null)));
    }

    public function test_it_asks_from_two_days_before_whatever_the_hour(): void
    {
        foreach (['2026-11-10 00:00:00', '2026-11-10 12:30:00', '2026-11-10 23:59:59'] as $since) {
            $asked = ImapEngineMailbox::searchSince($this->settings(new DateTimeImmutable($since, new DateTimeZone('Europe/Athens'))));
            $this->assertSame('2026-11-08 00:00:00', $asked?->format('Y-m-d H:i:s'), $since);
        }
    }

    /** SINCE is whole days in the server's timezone: a mail sent right after `since` is asked for wherever the server is. */
    public function test_a_mail_sent_after_since_is_inside_the_day_asked_in_any_server_timezone(): void
    {
        $since = new DateTimeImmutable('2026-11-10 00:05:00', new DateTimeZone('Pacific/Kiritimati')); // UTC+14
        $asked = ImapEngineMailbox::searchSince($this->settings($since))->format('Y-m-d');
        foreach (['Pacific/Kiritimati', 'UTC', 'Etc/GMT+12'] as $zone) {
            $arrival = $since->modify('+1 second')->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
            $this->assertGreaterThanOrEqual($asked, $arrival, $zone);
        }
    }
}

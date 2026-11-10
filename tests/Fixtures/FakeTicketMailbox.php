<?php

namespace Tests\Fixtures;

use MyCS\TicketMail\Imap\ImapEngineMailbox;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Imap\MailboxSettings;
use MyCS\TicketMail\Imap\PollSummary;
use MyCS\TicketMail\Imap\TestResult;
use MyCS\TicketMail\Parser;
use Throwable;

/** A mailbox for the tests: raw mails in, which were marked seen out. The parsing is the real one. */
final class FakeTicketMailbox implements Mailbox
{
    /** @var list<string> */
    public array $unseen = [];

    /** @var array<string, \DateTimeInterface> when a raw mail arrived at the server (its sha1): none is «now» */
    public array $arrived = [];

    /** @var list<string> */
    public array $seen = [];

    public function test(MailboxSettings $settings): TestResult
    {
        return new TestResult(true, 'Fake', count($this->unseen) + count($this->seen), count($this->unseen));
    }

    public function poll(MailboxSettings $settings, callable $handle, int $limit = 50): PollSummary
    {
        $summary = new PollSummary;
        $summary->connected = true;
        $left = [];
        // As the server's SEARCH SINCE (ImapEngineMailbox::searchSince): mail that arrived before it is never returned.
        $since = ImapEngineMailbox::searchSince($settings);
        $old = array_values(array_filter($this->unseen, fn (string $raw) => $since !== null && isset($this->arrived[sha1($raw)]) && $this->arrived[sha1($raw)] < $since));
        $this->unseen = array_values(array_filter($this->unseen, fn (string $raw) => !in_array($raw, $old, true)));
        foreach (array_slice($this->unseen, 0, $limit) as $raw) {
            $summary->fetched++;
            try {
                $email = Parser::parse($raw);
            } catch (Throwable) {
                $email = null;
            }
            if ($handle($email, $raw) === true) {
                $this->seen[] = $raw;
                $summary->handled++;
            } else {
                $left[] = $raw;
            }
        }
        $this->unseen = array_merge($old, $left, array_slice($this->unseen, $limit));

        return $summary;
    }
}

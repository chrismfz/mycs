<?php

namespace Tests\Fixtures;

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
        $this->unseen = array_merge($left, array_slice($this->unseen, $limit));

        return $summary;
    }
}

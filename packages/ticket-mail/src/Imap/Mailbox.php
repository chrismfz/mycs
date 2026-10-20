<?php

namespace MyCS\TicketMail\Imap;

use MyCS\TicketMail\InboundEmail;

/**
 * The seam over IMAP: the poller and its tests talk to this, the real one is ImapEngineMailbox. Neither method throws;
 * errors are in the result.
 */
interface Mailbox
{
    public function test(MailboxSettings $settings): TestResult;

    /**
     * Each unseen message, oldest first, at most $limit, one at a time: $handle gets it parsed (null when it could not
     * be parsed) and its raw RFC 822 (only the headers of one over the size cap). Marked \Seen only when $handle returns
     * true; otherwise it is read again next time. One message failing never stops the others.
     *
     * @param  callable(?InboundEmail, string): bool  $handle
     */
    public function poll(MailboxSettings $settings, callable $handle, int $limit = 50): PollSummary;
}

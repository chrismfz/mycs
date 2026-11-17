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
     * Each unseen message (with the settings' `since`: only those that arrived since about then, two days' margin, so an
     * old unread backlog never uses up $limit), oldest first, at most $limit, one at a time: $handle gets it parsed (null when it could not
     * be parsed) and its raw RFC 822 (only the headers of one over the size cap). Marked \Seen only when $handle returns
     * true; otherwise it is read again next time. One message failing never stops the others.
     *
     * $mayRead, when given, is asked before a message's body is fetched and parsed, with a key of the message in this
     * mailbox: false and only its headers are read, as for one over the size cap. It is how the caller stops a message
     * that kills the process each time (out of memory cannot be caught) from being read for ever, hiding all mail after it.
     *
     * @param  callable(?InboundEmail, string): bool  $handle
     * @param  (callable(string): bool)|null  $mayRead
     */
    public function poll(MailboxSettings $settings, callable $handle, int $limit = 50, ?callable $mayRead = null): PollSummary;
}

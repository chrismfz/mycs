<?php

namespace MyCS\TicketMail\Imap;

/** What one poll of a mailbox did: connected, how many it read and handled, the errors (never a password). */
final class PollSummary
{
    public bool $connected = false;

    public int $fetched = 0;

    public int $handled = 0;

    /** @var list<string> */
    public array $errors = [];

    public function error(string $error): void
    {
        $this->errors[] = mb_substr($error, 0, 500);
    }
}

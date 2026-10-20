<?php

namespace MyCS\TicketMail\Imap;

/** Where a department's mailbox is and how to log in (the password decrypted only for the connection). */
final class MailboxSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption, // ssl, starttls (never plain text: a password is sent)
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly string $folder = 'INBOX',
        public readonly int $timeout = 30,
    ) {}
}

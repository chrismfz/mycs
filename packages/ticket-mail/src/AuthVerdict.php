<?php

namespace MyCS\TicketMail;

/** Whether our server vouched for the From of a mail, and why (a reason code, shown to staff in the quarantine). */
final class AuthVerdict
{
    public function __construct(
        public readonly bool $authenticated,
        public readonly string $reason,
    ) {}
}

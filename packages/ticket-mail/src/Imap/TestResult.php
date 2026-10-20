<?php

namespace MyCS\TicketMail\Imap;

/** A mailbox's connection test: reached and logged in, and how many messages its folder has (unseen too). */
final class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $detail,
        public readonly int $messages = 0,
        public readonly int $unseen = 0,
    ) {}
}

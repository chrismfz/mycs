<?php

namespace MyCS\TicketMail;

use EmailReplyParser\EmailReplyParser;

/** The new words of a reply, without the quoted history and the signature; the whole text when that leaves nothing. */
final class ReplyText
{
    public static function clean(string $body): string
    {
        $clean = trim(EmailReplyParser::parseReply($body));

        return $clean !== '' ? $clean : trim($body);
    }
}

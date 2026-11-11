<?php

namespace MyCS\TicketMail;

use EmailReplyParser\EmailReplyParser;

/**
 * The new words of a reply, without the quoted history and the signature; the whole text when that leaves nothing.
 *
 * The reply parser's time grows with the square of the number of lines (60,000 characters of line breaks: 12 s or
 * more, sweep 8 review REV-795-2), and anyone may send the body: over MAX_LINES it is not asked, and the mail keeps its
 * quoted history.
 */
final class ReplyText
{
    /** Line breaks in a body the reply parser is still asked about: under half a second of it at the worst. */
    public const MAX_LINES = 2_000;

    public static function clean(string $body): string
    {
        if (substr_count($body, "\n") > self::MAX_LINES) {
            return trim($body);
        }
        $clean = trim(EmailReplyParser::parseReply($body));

        return $clean !== '' ? $clean : trim($body);
    }
}

<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\ReplyText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sweep 8, review REV-795-2: the reply parser is not asked about a body of more than ReplyText::MAX_LINES lines (its
 * time grows with the square of them); an ordinary reply loses its quoted history and signature as before.
 */
class ReplyTextTest extends TestCase
{
    private const REPLY = "Hello,\n\nit works now, thank you.\n\nOn Mon, 9 Nov 2026 at 10:12, Support <support@example.test> wrote:\n"
        . "> Please try again.\n>\n> > it does not open\n\n-- \nMaria\nExample Ltd";

    public function test_an_ordinary_reply_is_cleaned_as_before(): void
    {
        $this->assertSame("Hello,\n\nit works now, thank you.", ReplyText::clean(self::REPLY));
    }

    public function test_a_reply_of_quotes_alone_stays_whole(): void
    {
        $this->assertSame('> only a quote', ReplyText::clean("> only a quote\n"));
    }

    /** @return array<string, array{0: string}> 60,000 characters or near: the most a mailed message holds */
    public static function manyLines(): array
    {
        return [
            'line breaks alone' => [str_repeat("\n", 60_000)],
            'a From line every third line' => [str_repeat("a\n\nFrom: a\n", 5_454)],
        ];
    }

    #[DataProvider('manyLines')]
    public function test_a_body_of_many_lines_is_not_given_to_the_reply_parser(string $body): void
    {
        $started = microtime(true);
        $clean = ReplyText::clean($body);

        $this->assertLessThan(3.0, microtime(true) - $started, 'ReplyText::clean() of ' . strlen($body) . ' characters');
        $this->assertSame(trim($body), $clean);
    }

    public function test_the_last_body_the_parser_is_asked_about_and_the_first_it_is_not(): void
    {
        $asked = self::REPLY . str_repeat("\n> more", ReplyText::MAX_LINES - substr_count(self::REPLY, "\n"));
        $this->assertSame(ReplyText::MAX_LINES, substr_count($asked, "\n"));

        $started = microtime(true);
        $this->assertSame("Hello,\n\nit works now, thank you.", ReplyText::clean($asked));
        $this->assertLessThan(3.0, microtime(true) - $started, 'the reply parser at MAX_LINES');

        $this->assertSame($asked . "\n> more", ReplyText::clean($asked . "\n> more"), 'one line more: the history stays');
    }
}

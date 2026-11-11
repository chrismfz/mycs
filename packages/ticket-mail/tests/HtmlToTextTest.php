<?php

namespace MyCS\TicketMail\Tests;

use MyCS\TicketMail\HtmlToText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The HTML of a mail as text, read once whatever it holds (sweep 8, S8-TICKETS-MAIL-1): the sizes below took the
 * patterns before tens of seconds to minutes each (their cost grew with the square of the size) and take a few
 * hundredths of a second now. The text of ordinary mails is, to the byte, what it was.
 */
class HtmlToTextTest extends TestCase
{
    /** Well above a slow runner's time for 240 KB read once, far below the square's. */
    private const SECONDS = 3.0;

    /** @return array<string, array{0: string, 1: string}> the HTML and its text, as the patterns before gave it */
    public static function mails(): array
    {
        return [
            'a newsletter' => [
                "<!DOCTYPE html>\r\n<html><head><meta charset=\"utf-8\"><title>Offer</title><style type=\"text/css\">p { color: red; }</style></head>\r\n"
                . "<body><!-- preheader -->\r\n<h1>Hello Maria</h1>\r\n<p>Your invoice is <b>ready</b>.&nbsp;Pay <a href=\"https://example.test/pay\">here</a>.</p>   \r\n"
                . "<p>Thanks,<br>The team</p><script>track('x');</script></body></html>",
                "Hello Maria\n\nYour invoice is ready. Pay here.\n\nThanks,\nThe team",
            ],
            'a reply from Outlook' => [
                '<html><HEAD><STYLE>.a{}</STYLE></HEAD><body><div class=WordSection1><p class=MsoNormal>Καλημέρα,<o:p></o:p></p>'
                . '<p class=MsoNormal>το site δεν ανοίγει &amp; το mail επίσης.<o:p></o:p></p><div><br/></div>'
                . '<blockquote type="cite"><div>On Monday you wrote:</div><div>Try again</div></blockquote></div></body></html>',
                "Καλημέρα,\n\nτο site δεν ανοίγει & το mail επίσης.\n\nOn Monday you wrote:\nTry again",
            ],
            'a table and lists' => [
                '<table><tr><th>Item</th><th>Price</th></tr><tr><td>Hosting</td><td>10 &euro;</td></tr></table>'
                . '<ul><li>one</li><li class="x">two</LI></ul><ol><li>three</ol>',
                "Item Price\nHosting 10 €\n• one\n• two\n• three",
            ],
            'no markup' => ["just text, 2 < 3 and 5 > 4\n\n\n\nbye   \n", "just text, 2 < 3 and 5 > 4\n\nbye"],
            'a style and a comment that never end' => [
                '<p>before<style>p{} <p>inside</p> after <!-- never closed <b>bold</b>',
                "beforep{} inside\n\n after",
            ],
            'one kind inside the tag of another' => [
                'a<head <script>one</script>b<title>t</title>c<SCRIPT type="x">two</Script >d<style>s</style>e</head>f<head>g',
                'afg',
            ],
            'comments' => ['x<!---->y<!-- a --> z <!--> still comment -->w<!-- <style> -->v</style>u', 'xy z w'],
            'blanks before a line break' => [
                "<div>line one \t </div><div>line two</div>\n \n \n<div>   </div><p>end</p>  x  ",
                "line one\nline two\n\nend\n\n  x",
            ],
            'a list item with no end' => ['<ul><li>a<li>b</ul> tail <li unfinished', '• a• b tail'],
        ];
    }

    #[DataProvider('mails')]
    public function test_the_text_of_a_mail_is_what_it_was(string $html, string $text): void
    {
        $this->assertSame($text, HtmlToText::convert($html));
        $this->assertSame($text, self::before($html), 'the expected text is the one the patterns before gave');
    }

    /** Pieces of tags in any order, short enough for the patterns before: the same text from both. */
    public function test_any_mix_of_tag_pieces_gives_the_text_the_patterns_before_gave(): void
    {
        $pieces = ['<head>', '</head>', '<head ', '<script>', '</script >', '<style x="y">', '</style>', '<title>', '</TITLE>', '<!--', '-->', '--',
            '>', '<', '<li>', '<li ', '</li>', '<p>', '</p>', '</p ', '<br>', '<br />', '<div>', '</div>', '</td>', ' ', "\t", "\n", "\r\n", 'a', 'b',
            '&amp;', '&nbsp;', '</blockquote>', '<HEAD>', '<Script', '"', "'", '/',
            '<style title="a>b">', '<li data-x=\'>\'>'];
        $random = new Randomizer(new Mt19937(8));

        for ($i = 0; $i < 5_000; $i++) {
            $html = '';
            for ($n = $random->getInt(1, 14); $n > 0; $n--) {
                $html .= $pieces[$random->getInt(0, count($pieces) - 1)];
            }
            $this->assertSame(self::before($html), HtmlToText::convert($html), 'for ' . json_encode($html));
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: string}> what comes first, what is repeated, how often, what ends it */
    public static function hostile(): array
    {
        return [
            'head opened and never closed' => ['', '<head>', 40_000, 'hello'],
            'script, style, title never closed, one </head> at the end' => ['', '<script><style><title>', 12_000, 'hello</head>'],
            'a closed head, then head never closed' => ['<head>x</head>', '<HEAD>', 40_000, 'hello'],
            'head tags that never end, one > at the end' => ['', '<head ', 40_000, '>hello'],
            'comments never closed' => ['', '<!--', 60_000, 'x>hello'],
            'comments almost closed' => ['', '<!--', 60_000, '-- >->hello'],
            'list items that never end' => ['<p>hello</p>', '<li ', 60_000, ''],
            'list items that never end, one > at the end' => ['', '<li ', 60_000, '>hello'],
            'closing tags that never end' => ['', '</p ', 60_000, '>hello'],
            'a run of blanks with no line break after it' => ['a', ' ', 240_000, "b\nc"],
            'blanks and tabs before a character that is no blank' => ['a', " \t", 120_000, "&zwj;\nc"],
            'line breaks' => ['a', "<br>\n ", 40_000, 'b'],
        ];
    }

    /** The same shape, short, gives the text the patterns before gave; at 240 KB or more it is read in no time. */
    #[DataProvider('hostile')]
    public function test_html_made_to_be_read_again_and_again_is_read_once(string $first, string $repeated, int $times, string $last): void
    {
        $short = $first . str_repeat($repeated, 40) . $last;
        $this->assertSame(self::before($short), HtmlToText::convert($short));

        $html = $first . str_repeat($repeated, $times) . $last;
        $this->assertGreaterThanOrEqual(240_000, strlen($html));

        $started = microtime(true);
        HtmlToText::convert($html);

        $this->assertLessThan(self::SECONDS, microtime(true) - $started, 'HtmlToText::convert() of ' . strlen($html) . ' bytes');
    }

    /** Four times the size, about four times the time: not sixteen. */
    public function test_the_time_grows_with_the_size_not_with_its_square(): void
    {
        $time = function (int $times): float {
            $html = str_repeat('<head><!--<li </p <style>  ', $times) . ">x\n";
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $started = microtime(true);
                HtmlToText::convert($html);
                $best = min($best, microtime(true) - $started);
            }

            return $best;
        };

        $small = $time(10_000);
        $large = $time(40_000);

        $this->assertLessThan(max(0.5, 10 * $small), $large, '1 MB against 250 KB');
    }

    /** HtmlToText::convert() as it was before sweep 8, for short inputs only. */
    private static function before(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $html = (string) preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);
        $html = (string) preg_replace('#</(p|blockquote)\s*>#i', "\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(div|tr|h[1-6]|li)\s*>#i', "\n", $html);
        $html = (string) preg_replace('#</(td|th)\s*>#i', ' ', $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '• ', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}

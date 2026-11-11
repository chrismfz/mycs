<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sweep 8, S8-TICKETS-MAIL-2: a ticket message nests only TicketText::MAX_NESTING blocks deep, on the ticket's page and
 * in the mail that carries it. Messages people write (quoted three, eight deep, lists in lists) render as they did.
 */
class TicketTextNestingTest extends TestCase
{
    use RefreshDatabase;

    /** Well above a slow runner's time for 60,000 characters, far below the tens of seconds they took. */
    private const SECONDS = 3.0;

    /** @return array<string, array{0: string, 1: string}> the message and its HTML, as rendered before the limit */
    public static function messages(): array
    {
        return [
            'a reply with its history' => [
                "Hello,\n\nthe site is **down** again, see https://example.test/status\nsecond line\n\n"
                . "> On Monday you wrote:\n> > did you restart it?\n> > > yes\n> yes, twice\n\nThanks",
                "<p>Hello,</p>\n<p>the site is <strong>down</strong> again, see <a href=\"https://example.test/status\">https://example.test/status</a><br>second line</p>\n"
                . "<blockquote>\n<p>On Monday you wrote:</p>\n<blockquote>\n<p>did you restart it?</p>\n<blockquote>\n<p>yes<br>yes, twice</p>\n"
                . "</blockquote>\n</blockquote>\n</blockquote>\n<p>Thanks</p>\n",
            ],
            'lists in lists' => [
                "Steps:\n\n1. open the panel\n   - choose *DNS*\n     - add a record\n       1. type `A`\n2. save\n\n- [x] done\n- [ ] not done",
                "<p>Steps:</p>\n<ol>\n<li>open the panel\n<ul>\n<li>choose <em>DNS</em>\n<ul>\n<li>add a record\n<ol>\n<li>type <code>A</code></li>\n</ol>\n"
                . "</li>\n</ul>\n</li>\n</ul>\n</li>\n<li>save</li>\n</ol>\n<ul>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> done</li>\n"
                . "<li><input disabled=\"\" type=\"checkbox\"> not done</li>\n</ul>\n",
            ],
            'code, a table, HTML, an image, an unsafe link' => [
                "```\n> not a quote\n<b>not html</b>\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n<script>alert(1)</script>\n\n"
                . '![logo](https://example.test/a.png) [x](javascript:alert(1))',
                "<pre><code>&gt; not a quote\n&lt;b&gt;not html&lt;/b&gt;\n</code></pre>\n<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n"
                . "<tbody>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>\n&lt;script&gt;alert(1)&lt;/script&gt;\n"
                . "<p><a href=\"https://example.test/a.png\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">🖼 logo</a> <a>x</a></p>\n",
            ],
            'quoted eight deep, a list in a quote' => [
                ">>>>>>>> eight deep\n>>>> four\n\n> - a list\n>   > quoted in it\n>   > - and again",
                str_repeat("<blockquote>\n", 8) . "<p>eight deep<br>four</p>\n" . str_repeat("</blockquote>\n", 8)
                . "<blockquote>\n<ul>\n<li>a list\n<blockquote>\n<p>quoted in it</p>\n<ul>\n<li>and again</li>\n</ul>\n</blockquote>\n</li>\n</ul>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('messages')]
    public function test_a_message_people_write_renders_as_before(string $message, string $html): void
    {
        $this->assertSame($html, TicketText::html($message));
    }

    /** @return array<string, array{0: string}> 60,000 characters each: the most a mailed message holds */
    public static function nested(): array
    {
        return [
            'quote marks' => [str_repeat('>', 60_000)],
            'quote marks apart' => [str_repeat('> ', 30_000)],
            'a list in a quote in a list' => [str_repeat('>- ', 20_000)],
            'numbered lists' => [str_repeat('1. ', 20_000)],
        ];
    }

    #[DataProvider('nested')]
    public function test_a_message_made_to_nest_is_rendered_in_bounded_time_and_depth(string $message): void
    {
        $started = microtime(true);
        $html = TicketText::html($message);
        $seconds = microtime(true) - $started;

        $this->assertLessThanOrEqual(TicketText::MAX_NESTING, substr_count($html, '<blockquote') + substr_count($html, '<li'), 'blocks one inside the other');
        $this->assertLessThan(self::SECONDS, $seconds, 'TicketText::html() of 60,000 characters');
    }

    /** The mail that carries the message to the customer or to staff renders it too. */
    public function test_the_mail_of_a_message_made_to_nest_is_rendered_in_bounded_time_and_depth(): void
    {
        $ticket = Ticket::query()->create(['client_id' => User::factory()->create()->client()->id, 'subject' => 'Help', 'priority' => 'low', 'status' => 'open']);
        // No author, as a mail from an address we do not know.
        $message = $ticket->messages()->create(['message' => str_repeat('>', 60_000)]);

        $started = microtime(true);
        $html = (string) TemplateRenderer::context(['ticketMessage' => $message])['message_html'];
        $seconds = microtime(true) - $started;

        $this->assertLessThanOrEqual(TicketText::MAX_NESTING, substr_count($html, '<blockquote'), 'blockquotes nested in the mail');
        $this->assertLessThan(self::SECONDS, $seconds, 'the message of a mail, 60,000 characters');
    }
}

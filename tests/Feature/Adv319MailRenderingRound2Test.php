<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 2 (536837c7): a template renders as it did when its values went in before its
 * Markdown, for what staff write in templates: table rows of one value, HTML blocks around a block tag, lines in a loop.
 */
class Adv319MailRenderingRound2Test extends TestCase
{
    use RefreshDatabase;

    private function both(string $body, array $data): array
    {
        return [
            Str::markdown(TemplateRenderer::render($body, $data)),
            TemplateRenderer::render(TemplateRenderer::markdown($body), $data),
        ];
    }

    /** A row whose one cell is a value is the staff's row: not taken for a {% for %} line and dropped. */
    public function test_a_raw_html_table_row_of_one_value_is_kept(): void
    {
        [, $new] = $this->both("<table>\n{% for d in domains %}\n<tr><td>{{ d }}</td></tr>\n{% endfor %}\n</table>", ['domains' => ['x.gr', 'y.gr']]);

        $this->assertSame(2, substr_count($new, '<td>'), $new);
        $this->assertStringContainsString('<td>x.gr</td>', $new);
    }

    /** The same in a one-column Markdown table: its rows of one value are kept. */
    public function test_a_one_column_markdown_table_keeps_its_rows(): void
    {
        [, $new] = $this->both("| Domain |\n| --- |\n| {{ a }} |\n| {{ b }} |\n", ['a' => 'x.gr', 'b' => 'y.gr']);

        $this->assertStringContainsString('<td>x.gr</td>', $new, $new);
        $this->assertStringContainsString('<td>y.gr</td>', $new);
    }

    /**
     * A block tag inside an HTML block (the seeded templates' button, indented by a tab): the blank lines put around the
     * tag end the HTML block, and the tab-indented button becomes a code block that shows its HTML as text.
     */
    public function test_a_button_inside_an_if_in_its_div_stays_a_button(): void
    {
        $body = "Hi\n\n<div class=\"action\">\n{% if pay %}\n\t<a class=\"button button-blue\" href=\"{{ u }}\">Pay</a>\n{% endif %}\n</div>\n\nBye";
        [, $new] = $this->both($body, ['pay' => true, 'u' => 'https://example.test/i/1']);

        $this->assertStringNotContainsString('<pre>', $new, $new);
        $this->assertStringNotContainsString('&lt;a', $new);
        $this->assertStringContainsString('<a class="button button-blue" href="https://example.test/i/1">Pay</a>', $new);
    }

    /** Inside <pre> the blank lines added around a block tag are printed. */
    public function test_a_pre_block_keeps_its_lines(): void
    {
        [$old, $new] = $this->both("<pre>\nA\n{% if x %}\nB\n{% endif %}\nC\n</pre>", ['x' => true]);

        $this->assertSame($old, $new);
    }

    /** The invoice mail's bank transfer lines: one paragraph with a <br> per line (as before), not a paragraph per line. */
    public function test_the_bank_transfer_lines_stay_one_paragraph(): void
    {
        $template = NotificationTemplate::query()->where('key', 'new_invoice_created')->sole();
        $html = (new Mail($template, [
            'user' => ['first_name' => 'Maria'], 'invoice_number' => '7', 'invoice' => ['due_at' => '2026-10-10'], 'total' => '1', 'items' => [],
            'bank_transfer' => ['lines' => ['Bank: Example Bank', 'IBAN: GR00 0000'], 'reference' => 'RF00'],
        ]))->renderedHtml;

        $this->assertMatchesRegularExpression('~<p>\s*Bank: Example Bank<br>\s*IBAN: GR00 0000<br>\s*</p>~', $html, $html);
    }

    /** Only the first value of a link's address goes through `url`: an empty first one lets the next be the scheme. */
    public function test_every_value_that_can_start_a_links_address_is_checked(): void
    {
        [, $new] = $this->both('[x]({{ a }}{{ b }})', ['a' => '', 'b' => 'javascript:alert(1)']);

        $this->assertStringNotContainsString('href="javascript:', $new);
    }
}

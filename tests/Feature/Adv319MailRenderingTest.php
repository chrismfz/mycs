<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\MailTemplate\Html;
use Tests\TestCase;

/**
 * Adversarial review of #319: a template's Markdown is made HTML before its values go in, so Markdown that spans
 * template tags ({% for %} rows, {% else %} branches) must still read as it did when values went in first.
 */
class Adv319MailRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function html(string $key, array $data): string
    {
        $template = NotificationTemplate::query()->where('key', $key)->sole();

        return (new Mail($template, $data))->renderedHtml;
    }

    /** @return array<string, mixed> */
    private function invoiceData(): array
    {
        return [
            'user' => ['first_name' => 'Maria'], 'app' => ['name' => 'Acme'], 'invoice_number' => '2026-12',
            'invoice' => ['due_at' => '2026-10-10'], 'total' => '10.00 EUR', 'tax' => '', 'partly_paid' => false,
            'has_subscription' => false, 'bank_transfer' => null, 'urls' => ['invoice' => 'https://example.test/i/1'],
            'items' => [
                ['description' => 'Hosting', 'quantity' => 1, 'formattedPrice' => '8.00 EUR'],
                ['description' => 'Domain', 'quantity' => 1, 'formattedPrice' => '2.00 EUR'],
            ],
        ];
    }

    /** The items table: a {% for %} line between the table's rows became a row of its own, repeated per item. */
    public function test_an_invoices_items_table_has_one_row_per_item_and_no_empty_rows(): void
    {
        foreach (['new_invoice_created', 'invoice_paid', 'invoice_payment_reminder', 'invoice_overdue', 'invoice_modified', 'card_payment_due', 'new_order_created'] as $key) {
            $html = $this->html($key, $this->invoiceData());

            // A header row and one per item: 3. With the PR: 6 (an empty row before, between and after the items).
            $this->assertSame(3, substr_count($html, '<tr>'), "{$key}: rows of its items table");
            $this->assertDoesNotMatchRegularExpression('~<tr>\s*<td[^>]*>\s*</td>\s*<td[^>]*>\s*</td>~', $html, "{$key}: an empty row");
        }
    }

    /**
     * server_suspended, not for an overdue renewal: its {% else %} branch follows the other branch's <div> with no blank
     * line, so Markdown read it as part of that HTML block and its **bold** is printed as asterisks.
     */
    public function test_a_suspension_without_dunning_shows_bold_not_asterisks(): void
    {
        $html = $this->html('server_suspended', [
            'user' => ['first_name' => 'Maria'],
            'service' => ['product' => ['name' => 'Web Hosting'], 'dunning_suspended_at' => null, 'suspend_reason' => 'Abuse report'],
            'urls' => ['invoices' => 'https://example.test/invoices'],
        ]);

        $this->assertStringNotContainsString('**', $html);
        $this->assertStringContainsString('<strong>Web Hosting</strong>', $html);
    }

    /** The same in Greek. */
    public function test_a_suspension_without_dunning_shows_bold_not_asterisks_in_greek(): void
    {
        $template = NotificationTemplate::query()->where('key', 'server_suspended')->sole()->inLocale('el');
        $html = (new Mail($template, [
            'user' => ['first_name' => 'Maria'],
            'service' => ['product' => ['name' => 'Web Hosting'], 'dunning_suspended_at' => null, 'suspend_reason' => null],
            'urls' => ['invoices' => 'https://example.test/invoices'],
        ]))->renderedHtml;

        $this->assertStringNotContainsString('**', $html);
    }

    /** The welcome mail's product snippet is HTML of its own: not wrapped in a paragraph (<p><p>…</p></p>). */
    public function test_a_welcome_mails_product_snippet_is_not_nested_in_a_paragraph(): void
    {
        $html = $this->html('new_server_created', [
            'user' => ['first_name' => 'Maria'], 'app' => ['name' => 'Acme'],
            'service' => ['product' => ['name' => 'Web Hosting'], 'expires_at' => null, 'username' => null],
            'product_info' => new Html('<p>Your control panel: <strong>cp.example.test</strong></p>'),
            'urls' => ['service' => 'https://example.test/s/1'],
        ]);

        $this->assertDoesNotMatchRegularExpression('~<p>\s*<p>~', $html);
    }

    /** A value in a link's address is checked as an address, as Markdown checks one it reads (allow_unsafe_links). */
    public function test_a_value_in_a_markdown_link_cannot_be_a_javascript_link(): void
    {
        $html = TemplateRenderer::render(TemplateRenderer::markdown('[Your site]({{ website }})'), ['website' => 'javascript:alert(document.cookie)']);

        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    /** Text that looks like the tag placeholder (MYCSTAG<n>X) is text: it neither breaks the mail nor becomes a tag. */
    public function test_text_like_the_tag_placeholder_is_kept_as_text(): void
    {
        $this->assertStringContainsString('MYCSTAG7X', TemplateRenderer::render(TemplateRenderer::markdown('Ref MYCSTAG7X for {{ name }}'), ['name' => 'Maria']));
        $this->assertStringContainsString('MYCSTAG0X', TemplateRenderer::render(TemplateRenderer::markdown('Ref MYCSTAG0X for {{ name }}'), ['name' => 'Maria']));
    }
}

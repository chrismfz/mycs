<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\MailTemplate\Html;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 4 (80914347): the address check reads attributes in tags, never in a mail's text.
 */
class Adv319MailRenderingRound4Test extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer's text that reads «action=foo:bar» is text: kept as typed, and it does not eat the tag after it (its
     * unquoted «value» ran to the next space or «>», taking «</p» or «</a» with it).
     */
    public function test_a_customers_text_that_looks_like_an_attribute_is_kept_with_the_tag_after_it(): void
    {
        $html = TemplateRenderer::renderMarkdown('Subject: {{ v }}', ['v' => 'I set action=foo:bar']);

        $this->assertStringContainsString('I set action=foo:bar</p>', $html, $html);
    }

    /** The same inside a link: its closing </a> stays, so the rest of the mail is not part of the link. */
    public function test_a_links_closing_tag_survives_a_value_that_looks_like_an_attribute(): void
    {
        $html = TemplateRenderer::renderMarkdown("[{{ v }}](https://example.test/t/1)\n\nRest of the mail", ['v' => 'Help href=x:y']);

        $this->assertStringContainsString('</a>', $html, $html);
        $this->assertStringContainsString('Help href=x:y', $html);
    }

    /** A ticket message (an HTML value) is not changed either. */
    public function test_a_ticket_messages_text_is_not_rewritten(): void
    {
        $html = TemplateRenderer::renderMarkdown('{{ m }}', ['m' => new Html('<p>Upload went to src=ftp://files.example.test today</p>')]);

        $this->assertStringContainsString('src=ftp://files.example.test today</p>', $html, $html);
    }

    /** <object data> opens an address too. */
    public function test_an_objects_data_address_is_checked(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><object data="javascript:alert(1)"></object></div>', []);

        $this->assertStringNotContainsString('javascript:', $html);
    }
}

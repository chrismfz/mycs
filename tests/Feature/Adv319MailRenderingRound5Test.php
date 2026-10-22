<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 5 (d02d267b): the address check reads a tag's attributes one after another, as a
 * browser does, never «href=» inside another attribute's quoted value.
 */
class Adv319MailRenderingRound5Test extends TestCase
{
    use RefreshDatabase;

    /** A customer's name in an image's alt text: «href=x:y» inside it was rewritten to href="#", closing alt early. */
    public function test_a_value_in_an_alt_text_cannot_add_an_attribute(): void
    {
        $html = TemplateRenderer::renderMarkdown('![{{ v }}](https://example.test/a.png)', ['v' => 'Maria href=x:y onerror=alert(1) b']);

        $this->assertStringContainsString('alt="Maria href=x:y onerror=alert(1) b"', $html, $html);
    }

    /** The same in a link's title. */
    public function test_a_value_in_a_links_title_cannot_add_an_attribute(): void
    {
        $html = TemplateRenderer::renderMarkdown('[Open](https://example.test/t "{{ v }}")', ['v' => 'Maria href=x:y onmouseover=alert(1) b']);

        $this->assertStringContainsString('title="Maria href=x:y onmouseover=alert(1) b"', $html, $html);
    }

    /** And in a staff-written attribute with a value in it. */
    public function test_a_value_in_a_raw_html_attribute_cannot_add_an_attribute(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><span title="{{ v }}">x</span></div>', ['v' => 'a src=x:y onmouseover=alert(1) b']);

        $this->assertStringContainsString('title="a src=x:y onmouseover=alert(1) b"', $html, $html);
    }

    /** Staff's own attribute text that mentions «href=» is kept as written. */
    public function test_staff_attribute_text_mentioning_href_is_kept(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><a href="https://example.test" title="see href=javascript:x">t</a></div>', []);

        $this->assertStringContainsString('title="see href=javascript:x"', $html, $html);
    }

    /** A stray quote in an unquoted value (title=x') hides the tag from the check: a browser still reads its href. */
    public function test_a_tag_with_a_stray_quote_is_still_checked(): void
    {
        $html = TemplateRenderer::renderMarkdown("<div><a title=x' href=javascript:alert(1)>x</a></div>", []);

        $this->assertStringNotContainsString('javascript:', $html, $html);
    }
}

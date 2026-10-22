<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\MailTemplate\Html;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 3 (94ca4b3a): every address in a rendered mail is checked once its values are in
 * (safeAddresses), also in raw HTML and in HTML values. What a browser reads as an address must be what is checked.
 */
class Adv319MailRenderingRound3Test extends TestCase
{
    use RefreshDatabase;

    /** A numeric character reference without its «;» is still decoded by a browser in an attribute (&#106 is «j»). */
    public function test_a_character_reference_without_its_semicolon_is_decoded_as_a_browser_does(): void
    {
        foreach (['&#106avascript:alert(1)', '&#x6Aavascript:alert(1)', 'javascript&#58alert(1)'] as $address) {
            $html = TemplateRenderer::renderMarkdown('{{ h }}', ['h' => new Html('<a href="' . $address . '">x</a>')]);

            $this->assertStringContainsString('href="#"', $html, $address);
        }
    }

    /** An attribute that follows «/» or a quote with no space is an attribute to a browser (<a/href=…>, title="t"href=…). */
    public function test_an_address_attribute_without_a_space_before_it_is_checked(): void
    {
        foreach (['<div><a/href="javascript:alert(1)">x</a></div>', '<div><a title="t"href="javascript:alert(1)">x</a></div>'] as $body) {
            $this->assertStringNotContainsString('javascript:', TemplateRenderer::renderMarkdown($body, []), $body);
        }
    }

    /** srcset holds addresses too. */
    public function test_srcset_addresses_are_checked(): void
    {
        $html = TemplateRenderer::renderMarkdown('<img srcset="{{ v|raw }} 2x">', ['v' => 'javascript:alert(1)']);

        $this->assertStringNotContainsString('javascript:', $html);
    }
}

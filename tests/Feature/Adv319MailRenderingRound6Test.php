<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 6 (683ba7ab): the address check reads tags where a browser does. Inside a comment
 * there are no tags: an unclosed quote there must not hide the real tags after it.
 */
class Adv319MailRenderingRound6Test extends TestCase
{
    use RefreshDatabase;

    public function test_an_unclosed_quote_in_a_comment_does_not_hide_the_next_tags(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><!-- <b title=" --><a href="javascript:alert(1)">x</a><i title="y">z</i></div>', []);

        $this->assertStringNotContainsString('javascript:', $html, $html);
    }

    /** A bogus comment (<![CDATA[ … ]]>, which a browser ends at its first ">") is no tag either. */
    public function test_an_unclosed_quote_in_a_cdata_section_does_not_hide_the_next_tags(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><![CDATA[ <b title=" ]]><a href="javascript:alert(1)">x</a><i title="y">z</i></div>', []);

        $this->assertStringNotContainsString('javascript:', $html, $html);
    }
}

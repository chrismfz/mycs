<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #319, round 7 (127587a9): comments end where a browser ends them, and nothing read inside a
 * comment or an end tag hides a real tag after it.
 */
class Adv319MailRenderingRound7Test extends TestCase
{
    use RefreshDatabase;

    private function assertChecked(string $body): void
    {
        $html = TemplateRenderer::renderMarkdown($body, []);

        $this->assertStringNotContainsString('href="javascript:', $html, $html);
    }

    /** «<!-->» and «<!--->» are whole (empty) comments to a browser: what follows is markup. */
    public function test_an_abruptly_closed_comment_ends_at_once(): void
    {
        $this->assertChecked('<div><!--><a href="javascript:alert(1)">x</a></div>');
        $this->assertChecked('<div><!---><a href="javascript:alert(1)">x</a></div>');
    }

    /** «--!>» ends a comment too. */
    public function test_a_comment_ends_at_its_bang_ending(): void
    {
        $this->assertChecked('<div><!-- note --!><a href="javascript:alert(1)">x</a></div>');
    }

    /** An end tag's attributes are read by a browser (and ignored): a quote in them does not reach the next tag. */
    public function test_an_end_tags_quoted_attribute_does_not_hide_the_next_tag(): void
    {
        $this->assertChecked('<div></x a="<b c="><a href="javascript:alert(1)">x</a><i title="y">z</i></div>');
    }

    /**
     * Outside Outlook a conditional comment is a comment to its «-->»: an unclosed quote read inside it must not hide the
     * link after it.
     */
    public function test_an_unclosed_quote_inside_a_conditional_comment_does_not_hide_the_next_tag(): void
    {
        $this->assertChecked('<div><!--[if mso]><b title=" <![endif]--><a href="javascript:alert(1)">x</a><i title="y">z</i></div>');
    }
}

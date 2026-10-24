<?php

namespace Tests\Feature;

use App\Mail\ResentMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The mail's CSS written onto each element as it goes (owner 2026-10-24): Gmail gave a button's link its own blue on
 * the button's dark background, and left the class's white.
 */
class InlineMailCssTest extends TestCase
{
    private function sent(string $html): string
    {
        config(['mail.default' => 'array']);
        Mail::to('client@example.test')->send(new ResentMail('Password reset', $html));

        return (string) app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
    }

    public function test_a_button_of_the_mail_css_carries_its_white_text_on_itself(): void
    {
        $header = str_replace('{{ mail_css|raw }}', (string) file_get_contents(database_path('seeders/data/mail_css')),
            (string) file_get_contents(database_path('seeders/data/mail_header')));
        $html = $this->sent($header . '<div class="action"><a class="button button-blue" href="https://example.test/reset">Επαναφορά κωδικού</a></div></td></tr></table></body></html>');

        $this->assertMatchesRegularExpression('#<a class="button button-blue"[^>]*style="[^"]*(?<![-\w])color: \#fff;[^"]*background-color: \#2d3748#', $html);
        $this->assertStringContainsString('Επαναφορά κωδικού', $html);
        $this->assertStringContainsString('@media only screen', $html, 'the <style> stays for the clients that read it');
    }

    public function test_a_mail_without_its_css_goes_as_it_was(): void
    {
        $this->assertSame('<p>Hello</p>', $this->sent('<p>Hello</p>'));
    }
}

<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Mail\SystemMail;
use App\Models\ClientInvite;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * A template's copies never get a mail that is a key to an account or carries a secret, and what a customer typed
 * stays text in every mail (never a link or an image of their own).
 */
class MailCopiesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false, 'settings.mail_from_address' => 'billing@example.test', 'settings.system_email_address' => 'staff@example.test']);
        $this->user = User::factory()->create(['email' => 'owner@example.test']);
        MailFacade::fake();
    }

    private function copied(string $key): void
    {
        NotificationTemplate::query()->where('key', $key)->update(['enabled' => true, 'cc' => ['boss@example.test'], 'bcc' => ['audit@example.test']]);
    }

    private function assertNoCopies(string $key): void
    {
        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === $key && $mail->hasTo($mail->to[0]['address']));
        MailFacade::assertNotQueued(Mail::class, fn (Mail $mail) => $mail->hasCc('boss@example.test') || $mail->hasBcc('audit@example.test'));
        $log = EmailLog::query()->where('template_key', $key)->sole();
        $this->assertSame([null, null], [$log->cc, $log->bcc]);
    }

    public function test_a_password_reset_is_copied_to_no_one(): void
    {
        $this->copied('password_reset');

        NotificationHelper::passwordResetNotification($this->user, ['url' => 'https://mycs.example.test/password/reset/livetoken?email=owner%40example.test']);

        $this->assertNoCopies('password_reset');
    }

    public function test_an_invitation_is_copied_to_no_one(): void
    {
        $this->copied('client_invite');
        [$invite, $token] = ClientInvite::issue($this->user->client(), 'new@example.test', null, $this->user);

        NotificationHelper::clientInviteNotification($invite, $token);

        $this->assertNoCopies('client_invite');
    }

    public function test_a_welcome_mail_with_its_password_is_copied_to_no_one(): void
    {
        $this->copied('new_server_created');
        $template = NotificationTemplate::query()->where('key', 'new_server_created')->sole();

        NotificationHelper::sendEmailNotification($template, ['service_password' => 'S3cret-pass'], $this->user);

        $this->assertNoCopies('new_server_created');
    }

    public function test_any_mail_carrying_a_secret_is_copied_to_no_one(): void
    {
        $template = NotificationTemplate::query()->create(['key' => 'probe_secret', 'subject' => 'S', 'body' => 'Key: {{ api_token }}',
            'enabled' => true, 'cc' => ['boss@example.test'], 'bcc' => ['audit@example.test']]);

        NotificationHelper::sendEmailNotification($template, ['api_token' => 'tok-123'], $this->user);

        $this->assertNoCopies('probe_secret');
    }

    public function test_an_ordinary_mail_keeps_its_copies(): void
    {
        $template = NotificationTemplate::query()->create(['key' => 'probe_plain', 'subject' => 'S', 'body' => 'Hello',
            'enabled' => true, 'cc' => ['boss@example.test'], 'bcc' => ['audit@example.test']]);

        NotificationHelper::sendEmailNotification($template, [], $this->user);

        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->hasCc('boss@example.test') && $mail->hasBcc('audit@example.test'));
    }

    public function test_a_customers_name_is_text_in_a_mail_never_a_link(): void
    {
        $this->user->update(['first_name' => '[Accept the invitation](https://evil.example/login) ![](https://evil.example/px.gif)']);
        $template = new NotificationTemplate(['key' => 'probe', 'subject' => 'S', 'body' => "Hello **{{ user.first_name }}**\n\n[Your account](https://mycs.example.test/account)"]);

        $html = (new Mail($template, TemplateRenderer::context([], $this->user)))->render();

        $this->assertStringNotContainsString('evil.example/login"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('[Accept the invitation](https://evil.example/login)', $html);
        // The template's own Markdown still is Markdown.
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('<a href="https://mycs.example.test/account">', $html);
    }

    public function test_a_template_tag_inside_its_markdown_still_works(): void
    {
        $template = new NotificationTemplate(['key' => 'probe', 'subject' => 'S', 'body' => "[Pay now]({{ pay_url }})\n\n{% if paid %}\nThanks\n{% endif %}"]);

        $html = (new Mail($template, ['pay_url' => 'https://mycs.example.test/invoices/7', 'paid' => true]))->render();

        $this->assertStringContainsString('<a href="https://mycs.example.test/invoices/7">Pay now</a>', $html);
        $this->assertStringContainsString('Thanks', $html);
    }

    public function test_a_staff_notice_keeps_what_a_customer_wrote_as_text(): void
    {
        // A ticket's text in a <pre>, with a blank line: Markdown ended the HTML block there and read the rest.
        $body = '<p><strong>New ticket</strong></p><pre>' . e("Hello\n\n[Open the ticket](https://evil.example/sysadmin) ![](https://evil.example/px)") . '</pre>';

        $html = (new SystemMail(['subject' => 'S', 'body' => $body]))->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_a_plain_text_staff_notice_keeps_its_lines(): void
    {
        $html = (new SystemMail(['subject' => 'S', 'body' => e("First line\n[x](https://evil.example)\nThird & last")]))->render();

        $this->assertStringContainsString('First line<br', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Third &amp; last', $html);
    }

    public function test_inline_images_and_messenger_links_are_kept(): void
    {
        $html = TemplateRenderer::renderMarkdown('<img src="cid:logo"> <img src="data:image/png;base64,iVBOR"> <a href="viber://chat?number=30">V</a> <a href="sms:+3021">S</a>', []);

        $this->assertStringContainsString('src="cid:logo"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,iVBOR"', $html);
        $this->assertStringContainsString('href="viber://chat?number=30"', $html);
        $this->assertStringContainsString('href="sms:+3021"', $html);
        $this->assertStringContainsString('href="#"', TemplateRenderer::renderMarkdown('<a href="data:image/png;base64,x">x</a>', []));
    }

    public function test_outlooks_conditional_comment_keeps_its_links_checked(): void
    {
        $html = TemplateRenderer::renderMarkdown('<div><!--[if mso]><a href="javascript:alert(1)">x</a><a href="https://ok.test">y</a><![endif]--><!-- <b title=" --></div>', []);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('href="https://ok.test"', $html);
        $this->assertStringContainsString('<!-- <b title=" -->', $html);
    }
}

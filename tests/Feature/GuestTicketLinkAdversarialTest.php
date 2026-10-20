<?php

namespace Tests\Feature;

use App\Livewire\Contact;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\Captcha\Altcha;
use App\Support\Tickets\GuestLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of PR Γ (a guest's ticket by a mailed link, docs/tickets.md §3 Δ): each test fails on the branch. */
class GuestTicketLinkAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test']);
    }

    private function lastMailedToken(): string
    {
        $mail = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        preg_match('#/t/([A-Za-z0-9_-]{43})#', (string) $mail->getHtmlBody(), $m);
        $this->assertNotEmpty($m, 'the mail has a link');

        return $m[1];
    }

    private function guestTicket(): Ticket
    {
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email', 'sender_vouched' => true]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help me', 'via' => TicketMessage::VIA_EMAIL]);

        return $ticket;
    }

    private function token(Ticket $ticket): string
    {
        return basename((string) parse_url(GuestLinks::issue($ticket), PHP_URL_PATH));
    }

    /**
     * F1. The contact form takes any address; its «we received it» carries «nothing the visitor wrote» (review of #249).
     * The link in it now opens a page on our domain with everything the visitor wrote, as live Markdown: a phishing
     * link and a remote image (a read receipt), under a name of their choice. A relay of spam, signed as ours.
     */
    public function test_a_typed_in_address_is_not_shown_the_visitors_links_and_images_on_our_domain(): void
    {
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(string $payload): bool
            {
                return $payload === 'solved';
            }
        });
        $form = Livewire::test(Contact::class);
        foreach (['captcha' => 'solved', 'name' => 'MyIP Security Team', 'email' => 'victim@example.com', 'subject' => 'Your account is suspended',
            'message' => "Verify within 24h: [Sign in to MyIP](https://evil.example/login)\n\n![](https://evil.example/open.png)"] as $field => $value) {
            $form->set($field, $value);
        }
        $form->set('department', $this->support->id)->call('submit')->assertHasNoErrors();
        $this->assertSame(false, Ticket::query()->sole()->sender_vouched);

        $page = $this->get(route('tickets.guest', $this->lastMailedToken()))->assertOk();

        $page->assertDontSee('href="https://evil.example', false);
        $page->assertDontSee('src="https://evil.example', false);
    }

    /**
     * F2. Referrer-Policy keeps the URL out of a Referer, but the layout prints the staff's «custom head» (analytics,
     * chat widgets) on this page too: such a script reads location.href, the live token, and sends it to a third party.
     */
    public function test_the_site_wide_head_scripts_are_not_on_the_private_link_page(): void
    {
        Setting::query()->updateOrCreate(['key' => 'theme_custom_head', 'settingable_type' => null],
            ['value' => '<script>/*THIRD-PARTY-ANALYTICS*/ gtag("config", "G-X", {page_location: location.href});</script>']);
        $token = $this->token($this->guestTicket());

        $this->get('/contact')->assertSee('THIRD-PARTY-ANALYTICS', false); // it is on the site's pages

        $this->get(route('tickets.guest', $token))->assertOk()->assertSee('Site down')->assertDontSee('THIRD-PARTY-ANALYTICS', false);
    }

    /**
     * F3. Tokens are kept hashed so that a read of the database gives no working link; the session (database or files)
     * keeps the page's full URL as «_previous.url» in plain text for the session's lifetime.
     */
    public function test_the_live_token_is_not_kept_in_the_session(): void
    {
        $token = $this->token($this->guestTicket());

        $this->get(route('tickets.guest', $token))->assertOk()->assertSee('Site down');

        $this->assertStringNotContainsString($token, serialize(session()->all()));
    }

    /** F4. «tickets_disabled» closes the page (DisabledIf) but not its files: a link still downloads them. */
    public function test_tickets_disabled_also_closes_the_files_by_the_link(): void
    {
        $ticket = $this->guestTicket();
        $reply = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'See the file']);
        File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
        file_put_contents(storage_path('app/tickets/uploads/adv-disabled.txt'), 'secret');
        $file = $reply->attachments()->create(['path' => 'tickets/uploads/adv-disabled.txt', 'filename' => 'a.txt', 'mime_type' => 'text/plain', 'filesize' => 6]);
        $token = $this->token($ticket);
        Setting::query()->updateOrCreate(['key' => 'tickets_disabled', 'settingable_type' => null], ['value' => '1']);

        try {
            $this->get(route('tickets.guest', $token))->assertNotFound();
            $this->get(route('tickets.guest.attachment', ['token' => $token, 'attachment' => $file->uuid]))->assertNotFound();
        } finally {
            @unlink($file->localPath);
        }
    }
}

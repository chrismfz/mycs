<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Jobs\Server\UnsuspendJob;
use App\Mail\Mail;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\MailTemplate\Html;
use Tests\TestCase;

/**
 * The templates' texts (audit sweep Σ4, from the WHMCS templates both companies send): English and Greek, each mail
 * in the company's language, the edited ones kept when the new texts come in.
 */
class NotificationTemplateTextsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false]);
    }

    /** @return array<string, mixed> data as each notification gets it */
    private function data(string $key, User $user): array
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-15']);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice = $invoice->fresh();
        $order = Order::query()->create(['client_id' => $user->client()->id, 'currency_code' => 'USD']);
        $ticket = Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Help', 'priority' => 'low', 'status' => 'open']);

        return match ($key) {
            'new_login_detected' => ['ip' => '192.0.2.1', 'device' => 'Firefox', 'time' => '2026-10-14 10:00'],
            'card_expiring' => ['method_name' => 'Visa 4242', 'expiry' => '11/2026'],
            'new_invoice_created', 'invoice_paid', 'invoice_payment_failed', 'invoice_payment_reminder', 'card_payment_due', 'invoice_modified' => ['invoice' => $invoice, 'items' => $invoice->items, 'total' => $invoice->formattedTotal, 'has_subscription' => false],
            'invoice_overdue' => ['invoice' => $invoice, 'items' => $invoice->items, 'total' => $invoice->formattedTotal, 'overdue' => ['days' => 3, 'last' => true, 'suspend_on' => '27/10/2026']],
            'credit_applied' => ['invoice' => $invoice, 'credit' => ['applied' => '$3.00', 'remaining' => '$7.00']],
            'invoice_refunded' => ['invoice' => $invoice, 'refund' => ['amount' => '$5.00', 'to_credit' => false, 'by_gateway' => true]],
            'new_order_created' => ['order' => $order, 'items' => collect([$service]), 'total' => '$10.00'],
            'new_server_created', 'server_suspended', 'server_unsuspended', 'server_terminated', 'service_cancelled' => ['service' => $service],
            'service_hold_ending' => ['service' => $service, 'hold_until' => '14 November 2026', 'suspend_on' => '15 November 2026'],
            'ssl_certificate_issued' => ['service' => $service, 'common_name' => 'shop.example.test', 'expires' => '2027-05-01'],
            'new_ticket_message' => ['ticketMessage' => $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Hello **there**'])],
            'guest_ticket_message' => ['ticket' => $ticket, 'ticketMessage' => $ticket->messages()->create(['message' => 'Reply']), 'ticket_url' => 'https://mycs.test/t/' . str_repeat('a', 43)],
            'ticket_received' => ['ticket' => $ticket, 'ticket_url' => 'https://mycs.test/t/' . str_repeat('a', 43)],
            'ticket_link' => ['ticket' => $ticket, 'ticket_url' => 'https://mycs.test/t/' . str_repeat('a', 43)],
            'ticket_cc_message' => ['ticket' => $ticket, 'ticketMessage' => $ticket->messages()->create(['message' => 'Reply'])],
            'email_verification', 'password_reset' => ['url' => 'https://example.test/link'],
            'client_invite' => ['account' => ['name' => 'Acme'], 'invite' => ['url' => 'https://example.test/invite/x', 'by' => 'Maria', 'expires_at' => now()->addWeek()]],
            // D7-1: the domain expiry notices, as DomainReminders sends them.
            'domain_renewal_notice', 'domain_free_renewal_notice', 'domain_expired_notice' => [
                'domain' => Domain::query()->where('fqdn', 'example.com')->first() ?? Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com', 'expires_at' => '2026-11-15']),
                'fqdn' => 'example.com', 'expires' => '15/11/2026', 'days_left' => 7, 'days_past' => 0, 'last_day' => '25/12/2026',
                'stage_end' => '25/11/2026', 'free' => $key === 'domain_free_renewal_notice', 'price' => '€23.33', 'late_fee' => '€100.30',
                'next_fee' => '€80.00', 'years' => 1, 'invoice' => $invoice, 'auto_charged' => false,
            ],
            // D8b-2: a domain ordered, registered or its transfer started.
            'domain_registered', 'domain_transfer_started', 'domain_transferred', 'domain_renewed', 'domain_transfer_failed' => [
                'domain' => Domain::query()->where('fqdn', 'example.com')->first() ?? Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com', 'expires_at' => '2026-11-15']),
                'fqdn' => 'example.com', 'expires' => '15/11/2027', 'years' => 1, 'nameservers' => 'ns1.example.net, ns2.example.net',
                'reason' => 'auth',
            ],
            // ROADMAP 6δ: a mass mail's layout, its message the staff's.
            'announcement', 'announcement_news' => ['subject' => 'Rigel moves', 'content' => new Html('<p>On Sunday.</p>'),
                'unsubscribe_url' => $key === 'announcement_news' ? 'https://example.test/stop' : null],
            'service_cancellation_received' => ['service' => $service, 'cancellation' => ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Moving']))],
        };
    }

    public function test_every_template_renders_in_english_and_greek(): void
    {
        $user = User::factory()->create(['first_name' => 'Maria']);

        foreach (EmailTemplateSeeder::mapping as $key => $template) {
            $data = TemplateRenderer::context($this->data($key, $user), $user);
            foreach (['en' => $template, 'el' => EmailTemplateSeeder::translations[$key]['el']] as $locale => $texts) {
                foreach (['subject', 'body', 'in_app_title', 'in_app_body'] as $field) {
                    if (!isset($texts[$field])) {
                        continue;
                    }
                    $out = TemplateRenderer::render($texts[$field], $data);
                    $this->assertStringNotContainsString('{{', $out, "{$key}.{$field} ({$locale})");
                    $this->assertStringNotContainsString('{%', $out, "{$key}.{$field} ({$locale})");
                }
            }
        }
        $this->assertSame(array_keys(EmailTemplateSeeder::mapping), array_keys(EmailTemplateSeeder::translations), 'every template has its Greek text');
    }

    public function test_a_mail_goes_out_in_the_companys_language(): void
    {
        MailFacade::fake();
        $user = User::factory()->create(['first_name' => 'Maria']);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        MailFacade::fake(); // forget the observer's own mail

        config(['settings.app_language' => 'el']);
        NotificationHelper::invoiceCreatedNotification($user->client(), $invoice->fresh());
        config(['settings.app_language' => 'en']);
        NotificationHelper::invoiceCreatedNotification($user->client(), $invoice->fresh());

        $subjects = MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->renderedSubject)->all();
        $this->assertStringStartsWith("Νέο προτιμολόγιο #{$invoice->number}", $subjects[0]);
        $this->assertStringContainsString('Αγαπητέ/ή Maria', MailFacade::queued(Mail::class)->first()->renderedHtml);
        $this->assertStringStartsWith("New invoice #{$invoice->number}", $subjects[1]);
        $this->assertSame('en', app()->getLocale(), 'the locale is put back');
    }

    public function test_a_field_without_a_translation_keeps_the_default_text(): void
    {
        $template = NotificationTemplate::query()->where('key', 'server_terminated')->first();
        $template->update(['translations' => ['el' => ['subject' => 'Μόνο θέμα']]]);

        $greek = $template->fresh()->inLocale('el');

        $this->assertSame('Μόνο θέμα', $greek->subject);
        $this->assertSame($template->body, $greek->body);
        $this->assertSame($template->subject, $template->fresh()->inLocale('fr')->subject);
    }

    public function test_staff_edit_the_greek_text(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->admin()->create();
        $template = NotificationTemplate::query()->where('key', 'server_suspended')->first();

        $this->livewireAs($owner)->test(EditNotificationTemplate::class, ['record' => $template->getRouteKey()])
            ->assertFormSet(['translations.el.subject' => EmailTemplateSeeder::translations['server_suspended']['el']['subject']])
            ->fillForm(['translations.el.subject' => 'Αναστολή: {{ service.product.name }}'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Αναστολή: {{ service.product.name }}', $template->fresh()->translations['el']['subject']);
    }

    public function test_a_reactivated_service_is_mailed(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);

        (new UnsuspendJob($service))->handle();

        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'server_unsuspended');
    }

    public function test_a_proforma_without_a_number_is_named_by_its_id(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'draft', 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->number = null;

        $this->assertSame((string) $invoice->id, TemplateRenderer::context(['invoice' => $invoice], $user)['invoice_number']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contact;
use App\Models\License;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Service;
use App\Services\Mail\TemplatePreview;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Independent review of #457 (template preview / «Send test to me»): what the preview shows, against what the real
 * notification (NotificationHelper) would carry.
 */
class TemplatePreviewReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EmailTemplateSeeder::class);
    }

    private function template(string $key, ?string $body = null): NotificationTemplate
    {
        $template = NotificationTemplate::query()->where('key', $key)->sole();
        if ($body !== null) {
            $template->body = $body;
            $template->translations = [];
        }

        return $template;
    }

    /**
     * R1. The made-up models carry real-looking ids (service 4242, client 1001, order 1234, domain 777) but only some of
     * their relations are set: a relation the template follows and the sample left unset is lazy-loaded from the live
     * database by that id. On nixpal the welcome mail reads `service.license.license_key` (License docblock): the preview,
     * and the «Send test» mail, show the licence key of whichever real service has id 4242.
     */
    public function test_the_preview_never_shows_a_real_services_license_key(): void
    {
        $service = Service::factory()->create(['id' => 4242, 'client_id' => Client::factory()->create()->id]);
        License::query()->create(['service_id' => $service->id, 'license_key' => 'REAL-CUSTOMER-LICENSE-KEY', 'status' => 'active']);

        $mail = TemplatePreview::render($this->template('new_server_created', 'Key: {{ service.license.license_key }}'), 'en');

        $this->assertStringNotContainsString('REAL-CUSTOMER-LICENSE-KEY', $mail['html'], 'a real customer\'s licence key in a preview');
    }

    /** R1, the same through an order: its client is not set on the sample, so client #1001 (and its address) is read from the database. */
    public function test_the_preview_never_shows_a_real_customers_address_through_the_order(): void
    {
        $client = Client::factory()->create(['id' => 1001]);
        Contact::query()->forceCreate(['client_id' => $client->id, 'primary_of_client_id' => $client->id, 'first_name' => 'Real', 'last_name' => 'Customer',
            'email' => 'real.customer@example.net', 'country' => 'GR']);
        $real = 'real.customer@example.net';
        $this->assertSame($real, Client::query()->find(1001)->email);
        Order::query()->forceCreate(['id' => 1234, 'client_id' => $client->id, 'currency_code' => 'USD']);

        $mail = TemplatePreview::render($this->template('new_order_created', 'For: {{ order.client.email }}'), 'en');

        $this->assertStringNotContainsString($real, $mail['html'], 'a real customer\'s address in a preview');
    }

    /**
     * R2. The welcome mail's main part is its login details (`{% if service.username %}` … `service_password`, which
     * NotificationHelper::serverCreatedNotification passes): the sample has neither, so the preview shows the welcome mail
     * without them and staff cannot check that part of their edit.
     */
    public function test_the_welcome_mail_preview_shows_its_login_details(): void
    {
        $mail = TemplatePreview::render($this->template('new_server_created'), 'en');

        $this->assertStringContainsString('Username', $mail['html']);
        $this->assertStringContainsString('Password', $mail['html']);
    }

    /** R3. Every ticket mail's subject is «[Ticket #{{ ticket.reference }}] …»: the sample ticket has no reference. */
    public function test_a_ticket_mail_preview_shows_the_tickets_reference(): void
    {
        foreach (['new_ticket_message', 'ticket_received', 'guest_ticket_message'] as $key) {
            $mail = TemplatePreview::render($this->template($key), 'en');

            $this->assertStringNotContainsString('#]', $mail['subject'], "{$key}: «{$mail['subject']}»");
        }
    }

    /**
     * R4. The sample invoice line has a price but no line_total (set by the observer when a real line is saved), so
     * the invoice's own total is 0: `{{ invoice.formattedTotal }}` (the domain notice's «renewal is on invoice #… ($0.00)»)
     * shows 0 next to a line of 15.00.
     */
    public function test_the_sample_invoices_own_total_is_its_lines_total(): void
    {
        $mail = TemplatePreview::render($this->template('new_invoice_created', 'T=[{{ invoice.formattedTotal }}] R=[{{ invoice.formattedRemaining }}]'), 'en');

        $this->assertDoesNotMatchRegularExpression('/T=\[\D*0[.,]00\D*\]/', $mail['html'], 'the invoice total is 0 in the preview');
        $this->assertDoesNotMatchRegularExpression('/R=\[\D*0[.,]00\D*\]/', $mail['html'], 'the amount left to pay is 0 in the preview');
    }

    /**
     * R4b. `total` is formatted by hand («USD 15,00», «€15,00») and not by the currency's own format as the real mail
     * (Price::format: its prefix, suffix and separators), so a mail shows the line as «$15.00» and the total as «USD 15,00».
     */
    public function test_the_total_is_in_the_currencys_own_format_like_its_lines(): void
    {
        [$data] = TemplatePreview::sample('new_invoice_created');
        $line = $data['items']->first()->formattedPrice->format(15);

        $this->assertSame($line, $data['total']);
    }

    /** R5. The overdue sample is due in a week, yet «3 days overdue», and «suspended on» a date before it is even due. */
    public function test_the_overdue_sample_invoice_is_past_its_due_date(): void
    {
        [$data] = TemplatePreview::sample('invoice_overdue');

        $this->assertTrue($data['invoice']->due_at->isPast(), 'an overdue invoice due on ' . $data['invoice']->due_at->format('d/m/Y'));
    }
}

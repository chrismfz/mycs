<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Jobs\Server\UnsuspendJob;
use App\Mail\Mail;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
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
            'new_invoice_created', 'invoice_paid', 'invoice_payment_failed', 'invoice_payment_reminder', 'card_payment_due', 'invoice_modified' => ['invoice' => $invoice, 'items' => $invoice->items, 'total' => $invoice->formattedTotal, 'has_subscription' => false],
            'invoice_overdue' => ['invoice' => $invoice, 'items' => $invoice->items, 'total' => $invoice->formattedTotal, 'overdue' => ['days' => 3, 'last' => true, 'suspend_on' => '27/10/2026']],
            'credit_applied' => ['invoice' => $invoice, 'credit' => ['applied' => '$3.00', 'remaining' => '$7.00']],
            'invoice_refunded' => ['invoice' => $invoice, 'refund' => ['amount' => '$5.00', 'to_credit' => false, 'by_gateway' => true]],
            'new_order_created' => ['order' => $order, 'items' => collect([$service]), 'total' => '$10.00'],
            'new_server_created', 'server_suspended', 'server_unsuspended', 'server_terminated' => ['service' => $service],
            'new_ticket_message' => ['ticketMessage' => $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Hello **there**'])],
            'email_verification', 'password_reset' => ['url' => 'https://example.test/link'],
            'client_invite' => ['account' => ['name' => 'Acme'], 'invite' => ['url' => 'https://example.test/invite/x', 'by' => 'Maria', 'expires_at' => now()->addWeek()]],
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

    public function test_the_new_texts_replace_only_templates_staff_did_not_rewrite(): void
    {
        DB::table('notification_templates')->where('key', 'invoice_paid')->update(['body' => 'Our own text', 'updated_at' => now(), 'translations' => null]);
        // The previous default, as seeded before (0be6618), saved once unchanged from the form (updated_at stamped).
        DB::table('notification_templates')->where('key', 'server_suspended')->update(['subject' => 'Service suspended',
            'body' => "# Service suspended\n\nYour service has been suspended due to a payment failure.\n\n**Service details**\n- Name: {{ service.product.name }}\n\nPlease pay the invoice to reactivate the service.",
            'in_app_title' => 'Service suspended',
            'in_app_body' => 'Your service {{ service.product.name }} has been suspended due to a payment failure. Please pay the invoice to reactivate the service.',
            'translations' => null, 'updated_at' => now()]);
        DB::table('notification_templates')->where('key', 'server_unsuspended')->delete();

        (require database_path('migrations/2026_10_26_000001_new_notification_template_texts.php'))->up();

        $rows = DB::table('notification_templates')->get()->keyBy('key');
        $this->assertSame('Our own text', $rows['invoice_paid']->body);
        $this->assertNull($rows['invoice_paid']->translations);
        $this->assertSame(EmailTemplateSeeder::mapping['server_suspended']['body'], $rows['server_suspended']->body);
        $this->assertNotNull($rows['server_suspended']->translations);
        $this->assertTrue($rows->has('server_unsuspended'));
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

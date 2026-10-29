<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\License;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Staff-edited templates are rendered from data, never compiled as Blade (audit-security H1). */
class NotificationTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'settings.mail_disable' => false,
            'settings.mail_header' => file_get_contents(database_path('seeders/data/mail_header')),
            'settings.mail_footer' => file_get_contents(database_path('seeders/data/mail_footer')),
        ]);
    }

    private function lastEmail(): EmailLog
    {
        return EmailLog::query()->latest('id')->firstOrFail();
    }

    public function test_invoice_email_renders_items_urls_and_escapes_values(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        $invoice->items()->create(['description' => 'Hosting <b>Pro</b>', 'quantity' => 2, 'price' => 10]);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => 5]);

        NotificationHelper::sendNotification('new_invoice_created', [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'total' => $invoice->formattedTotal,
            'has_subscription' => false,
        ], $user);

        $body = $this->lastEmail()->body;
        $this->assertStringContainsString('Hosting &lt;b&gt;Pro&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>Pro</b>', $body);
        $this->assertStringContainsString('Domain', $body);
        // Its button opens the invoice's payment (urls.pay: the page with ?pay=1, its preferred method chosen there).
        $this->assertStringContainsString('href="' . route('invoices.show', ['invoice' => $invoice, 'pay' => 1]) . '"', $body);
        $this->assertStringNotContainsString('active subscription', $body);
        $this->assertStringNotContainsString('{{', $body);
        $this->assertStringNotContainsString('{%', $body);
        $this->assertStringContainsString(config('app.name') . '. All rights reserved.', $body);
        MailFacade::assertQueued(Mail::class);
    }

    public function test_blade_and_php_in_a_template_are_never_executed(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        NotificationTemplate::where('key', 'new_login_detected')->update([
            'subject' => 'Login @php echo "PWNED"; @endphp',
            'body' => "<?php echo 'PWNED'; ?> {!! 'PWNED' !!} @php echo 'PWNED'; @endphp [{{ user.password }}] [{{ user.tfa_secret }}] {{ user.email }}",
        ]);

        NotificationHelper::loginDetectedNotification($user, ['ip' => '192.0.2.1', 'device' => 'x', 'time' => 'now']);

        $email = $this->lastEmail();
        $this->assertSame('Login @php echo "PWNED"; @endphp', $email->subject);
        $this->assertStringContainsString("@php echo 'PWNED'; @endphp", $email->body);
        $this->assertStringContainsString('[] []', $email->body, 'hidden model fields are not readable');
        $this->assertStringContainsString($user->email, $email->body);
        $this->assertStringNotContainsString($user->password, $email->body);
    }

    public function test_in_app_notification_is_plain_text_and_product_snippet_is_rendered(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct(['email_template' => 'Panel: **{{ service.product.name }}** & more']);
        $service = Service::factory()->create([
            'client_id' => $user->client()->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => 'active',
        ]);
        NotificationTemplate::where('key', 'new_server_created')->update(['in_app_body' => 'Ready: {{ service.product.name }} & co']);

        NotificationHelper::serverCreatedNotification($user->client(), $service);

        $this->assertStringContainsString('Panel: <strong>Test Product</strong> &amp; more', $this->lastEmail()->body);
        $notification = $user->notifications()->latest('id')->firstOrFail();
        $this->assertSame('Ready: Test Product & co', $notification->body);
        $this->assertSame(route('services.show', $service), $notification->url);
    }

    public function test_the_welcome_mail_reads_the_licenses_key_never_what_staff_see_of_it(): void
    {
        // nixpal's welcome mail (parity 2026-10-21): the key in the product's snippet; the rest of it stays staff's.
        MailFacade::fake();
        $user = User::factory()->create();
        $probes = implode(' ', array_map(fn (string $field) => "{$field}[{{ service.license.{$field} }}]", License::TEMPLATE_HIDDEN));
        $product = $this->createProduct(['email_template' => 'Key: {{ service.license.license_key }} ({{ service.license.status }}) ' . $probes
            . ' logs[{% for log in service.license.accessLogs %}{{ log.ip }}{% endfor %}] svc[{{ service.license.service.id }}]']);
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active']);
        $service->license; // read before it is issued (the admin's create action): the mail reads it again
        $license = License::query()->create(['service_id' => $service->id, 'license_key' => 'Zone-test0000000000', 'status' => License::STATUS_ACTIVE,
            'valid_domain' => 'example.test', 'valid_ip' => '192.0.2.20', 'valid_directory' => '/srv/app', 'reissues' => 3, 'whmcs_id' => 77,
            'last_access' => now(), 'last_ok_at' => now(), 'last_seen_ip' => '192.0.2.10', 'last_seen_client' => 'agent/1', 'last_failure_at' => now(), 'last_failure' => 'Invalid IP']);
        $license->accessLogs()->create(['ip' => '192.0.2.30', 'message' => 'ok', 'created_at' => now()]);

        NotificationHelper::serverCreatedNotification($user->client(), $service);

        $body = $this->lastEmail()->body;
        $this->assertStringContainsString('Key: Zone-test0000000000 (Active)', $body);
        foreach (License::TEMPLATE_HIDDEN as $field) {
            $this->assertStringContainsString("{$field}[]", $body, $field);
        }
        $this->assertStringContainsString('logs[] svc[]', $body);
        $this->assertStringNotContainsString('192.0.2.', $body);
    }

    public function test_a_service_without_a_license_mails_its_snippet_without_one(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct(['email_template' => 'Key: [{{ service.license.license_key }}] done']);
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active']);

        NotificationHelper::serverCreatedNotification($user->client(), $service);

        $this->assertStringContainsString('Key: [] done', $this->lastEmail()->body);
    }

    public function test_a_broken_template_is_reported_and_skipped(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        NotificationTemplate::where('key', 'new_login_detected')->update(['body' => 'Hi {{ $user->name }}']);
        $before = EmailLog::count();

        NotificationHelper::loginDetectedNotification($user, ['ip' => '192.0.2.1', 'device' => 'x', 'time' => 'now']);

        $this->assertSame($before, EmailLog::count());
        MailFacade::assertNotQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_login_detected');
    }

    public function test_admin_rule_rejects_blade_syntax(): void
    {
        $check = fn (string $value) => Validator::make(['t' => $value], ['t' => [TemplateRenderer::rule()]])->passes();

        $this->assertTrue($check('Hi {{ user.first_name }} {% if invoice %}x{% endif %}'));
        $this->assertFalse($check('{{ route("invoices.show", $invoice) }}'));
        $this->assertFalse($check('{{ $invoice->id }}'));
        $this->assertFalse($check('{% if x %}never closed'));
    }
}

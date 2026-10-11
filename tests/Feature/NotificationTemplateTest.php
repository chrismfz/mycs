<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
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
        $this->assertStringContainsString('href="' . route('invoices.show', $invoice) . '"', $body);
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
            'user_id' => $user->id,
            'product_id' => $product->product->id,
            'plan_id' => $product->plan->id,
            'status' => 'active',
        ]);
        NotificationTemplate::where('key', 'new_server_created')->update(['in_app_body' => 'Ready: {{ service.product.name }} & co']);

        NotificationHelper::serverCreatedNotification($user, $service);

        $this->assertStringContainsString('Panel: <strong>Test Product</strong> &amp; more', $this->lastEmail()->body);
        $notification = $user->notifications()->latest('id')->firstOrFail();
        $this->assertSame('Ready: Test Product & co', $notification->body);
        $this->assertSame(route('services.show', $service), $notification->url);
    }

    public function test_a_broken_template_is_reported_and_skipped(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        NotificationTemplate::where('key', 'new_login_detected')->update(['body' => 'Hi {{ $user->name }}']);
        $before = EmailLog::count();

        NotificationHelper::loginDetectedNotification($user, ['ip' => '192.0.2.1', 'device' => 'x', 'time' => 'now']);

        $this->assertSame($before, EmailLog::count());
        MailFacade::assertNotQueued(Mail::class, fn (Mail $mail) => $mail->emailTemplate->key === 'new_login_detected');
    }

    public function test_admin_rule_rejects_blade_syntax(): void
    {
        $check = fn (string $value) => Validator::make(['t' => $value], ['t' => [TemplateRenderer::rule()]])->passes();

        $this->assertTrue($check('Hi {{ user.first_name }} {% if invoice %}x{% endif %}'));
        $this->assertFalse($check('{{ route("invoices.show", $invoice) }}'));
        $this->assertFalse($check('{{ $invoice->id }}'));
        $this->assertFalse($check('{% if x %}never closed'));
    }

    public function test_migration_replaces_blade_defaults_and_keeps_custom_templates(): void
    {
        NotificationTemplate::where('key', 'invoice_paid')->update([
            'body' => 'Paid {{ $invoice->id }} @if($x) y @endif',
            'in_app_url' => '{{ route("invoices.show", $invoice) }}',
        ]);
        NotificationTemplate::where('key', 'password_reset')->update(['body' => 'Custom: {{ url }}']);
        DB::table('settings')->where('key', 'mail_footer')->update(['value' => "© {{ date('Y') }}"]);

        (require database_path('migrations/2026_10_11_000000_templates_without_blade.php'))->up();

        $paid = NotificationTemplate::where('key', 'invoice_paid')->first();
        $this->assertStringContainsString('{{ invoice.formattedTotal }}', $paid->body);
        $this->assertSame('{{ urls.invoice }}', $paid->in_app_url);
        $this->assertSame('Custom: {{ url }}', NotificationTemplate::where('key', 'password_reset')->value('body'));
        $this->assertStringContainsString('{{ app.year }}', DB::table('settings')->where('key', 'mail_footer')->value('value'));
    }
}

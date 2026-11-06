<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Enums\NotificationEnabledStatus;
use App\Helpers\NotificationHelper;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Owner 2026-11-06: an addon's invoice and its receipt went nowhere and left no trace, because the account's contact took
 * no kind of email. A mail held back by a choice made for the customer is now logged «Not sent» with why; the account
 * has its own «Emails» tab; and a contact that takes none is shown as the fault it is.
 */
class EmailNotSentLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Dedicated IP', 'quantity' => 1, 'price' => 1]);
        EmailLog::query()->delete();
        Mail::fake();

        return $invoice->fresh();
    }

    private function takes(?array $kinds): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => $kinds])->save();
        $this->client = $this->client->fresh();
    }

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_a_contact_that_takes_no_invoices_leaves_a_not_sent_row_with_why(): void
    {
        $this->takes([]);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        NotificationHelper::invoicePaidNotification($this->client, $invoice);

        Mail::assertNothingQueued();
        $logs = EmailLog::query()->orderBy('id')->get();
        $this->assertSame([['new_invoice_created', EmailLog::STATUS_SKIPPED], ['invoice_paid', EmailLog::STATUS_SKIPPED]],
            $logs->map(fn (EmailLog $log) => [$log->template_key, $log->status])->all());
        $log = $logs->first();
        $this->assertSame([$this->client->id, 'owner@example.test', Invoice::class, $invoice->id],
            [$log->client_id, $log->to, EmailLog::getActualClassNameForMorph((string) $log->related_type), $log->related_id]);
        $this->assertStringContainsString('does not take «Invoices» emails', (string) $log->error);
        $this->assertNotSame('', (string) $log->body, 'the mail it would have been, for staff to read or resend');
        $this->assertNull($log->sent_at);
    }

    public function test_a_contact_that_takes_invoices_is_mailed_as_before(): void
    {
        $this->takes(['invoice']);
        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());

        $this->assertSame([EmailLog::STATUS_PENDING], EmailLog::query()->pluck('status')->all());
        $this->assertNull(EmailLog::query()->sole()->error);
    }

    public function test_only_a_customers_choice_is_logged(): void
    {
        $this->takes([]);
        $invoice = $this->invoice();

        // Mail off for everyone: nothing goes and nothing is logged, as before.
        config(['settings.mail_disable' => true]);
        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        $this->assertSame(0, EmailLog::query()->count());
        config(['settings.mail_disable' => false]);

        // A template that mails no one (in-app only): no customer's missing mail.
        NotificationTemplate::query()->where('key', 'new_invoice_created')->update(['mail_enabled' => NotificationEnabledStatus::Never->value]);
        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_a_mail_the_customer_turned_off_says_so(): void
    {
        $this->takes(['invoice']);
        $template = NotificationTemplate::query()->where('key', 'new_invoice_created')->sole();
        $template->forceFill(['mail_enabled' => NotificationEnabledStatus::ChoiceOn])->save();
        $this->owner->notificationsPreferences()->create(['notification_template_id' => $template->id, 'mail_enabled' => false, 'in_app_enabled' => true]);

        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());

        Mail::assertNothingQueued();
        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::STATUS_SKIPPED, $log->status);
        $this->assertStringContainsString('turned this email off', (string) $log->error);
    }

    public function test_not_sent_is_never_swept_nor_retried(): void
    {
        $this->takes([]);
        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());
        $log = EmailLog::query()->sole();
        $this->travel(3)->days();

        Artisan::call('app:mail-sweep');
        Artisan::call('app:mail-resend-failed');

        $this->assertSame(EmailLog::STATUS_SKIPPED, $log->fresh()->status);
        $this->assertSame(1, EmailLog::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_the_account_has_its_emails_tab_and_the_summary_links_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->takes([]);
        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());
        $url = ClientResource::getUrl('emails', ['record' => $this->client]);

        $this->actingAs($this->staff(['admin.clients.view', 'admin.clients.viewAny', 'admin.email_logs.viewAny', 'admin.email_logs.view']), 'staff')
            ->get($url)->assertOk()->assertSee('Not sent')->assertSee('does not take', false);
        $summary = $this->actingAs($this->staff(['*']), 'staff')->get(ClientResource::getUrl('view', ['record' => $this->client]))->assertOk();
        $summary->assertSee($url, false)->assertSee(__('account.email_preferences_none'));

        // Without the email log's permission: no tab.
        $this->actingAs($this->staff(['admin.clients.view', 'admin.clients.viewAny']), 'staff')->get($url)->assertForbidden();
    }

    /** Review of #687: a later mail held back never hides an earlier one that failed («Last mail failed»). */
    public function test_a_later_mail_not_sent_never_hides_a_failure(): void
    {
        $invoice = $this->invoice();
        $failed = EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'Invoice', 'to' => 'owner@example.test', 'body' => 'x',
            'related_type' => $invoice->getMorphClass(), 'related_id' => $invoice->id]);
        $failed->forceFill(['status' => EmailLog::STATUS_FAILED])->save();
        EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'Paid', 'to' => 'owner@example.test', 'body' => 'x',
            'related_type' => $invoice->getMorphClass(), 'related_id' => $invoice->id, 'status' => EmailLog::STATUS_SKIPPED]);

        $this->assertSame($failed->id, $invoice->fresh()->latestSentEmailLog?->id);
        $this->assertTrue(Invoice::query()->whereKey($invoice->id)->whereHas('latestSentEmailLog', fn ($q) => $q->where('status', EmailLog::STATUS_FAILED))->exists());
    }

    /** Review of #687: a «suspended» mail held back is no telling: its end is not told either. */
    public function test_a_suspension_mail_not_sent_is_not_told(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => Service::STATUS_SUSPENDED, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => 'Suspended', 'to' => 'owner@example.test', 'body' => 'x',
            'template_key' => 'server_suspended', 'related_type' => $service->getMorphClass(), 'related_id' => $service->id, 'status' => EmailLog::STATUS_SKIPPED]);

        $this->assertFalse($service->fresh()->toldOfSuspension());
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Models\BulkMail;
use App\Models\BulkMailRecipient;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use App\Services\Mail\BulkMailer;
use App\Support\CurrentClient;
use App\Support\EmailKinds;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The primary contact's email preferences gate the owner's mails (owner 2026-10-30, App\Support\EmailKinds): each
 * template is a kind; null is every kind, [] none. Suspensions, terminations, overdue and expiry notices always go,
 * as do the login's own mails and what staff send by hand. The in-app notification goes anyway.
 */
class EmailPreferencesTest extends TestCase
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
        $this->clean();
    }

    /** What making the account, an invoice or a service mailed itself (email verification, «invoice created»): gone. */
    private function clean(): void
    {
        EmailLog::query()->delete();
        Notification::query()->delete();
        Mail::fake();
    }

    /** @param  array<int, string>|null  $kinds */
    private function takes(?array $kinds): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => $kinds])->save();
        $this->client = $this->client->fresh();
    }

    /** @return list<string> the templates mailed to the owner */
    private function mailed(): array
    {
        return EmailLog::query()->where('to', 'owner@example.test')->orderBy('id')->pluck('template_key')->all();
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        return $invoice->fresh();
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        $this->clean();

        return $service;
    }

    private function domain(): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'status' => 'active']);
        $this->clean();

        return $domain;
    }

    public function test_none_chosen_takes_every_kind(): void
    {
        $this->takes(null);

        [$invoice, $service, $domain] = [$this->invoice(), $this->service(), $this->domain()];

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        NotificationHelper::serviceCancelledNotification($this->client, $service);
        NotificationHelper::domainNotification($this->client, $domain, 'domain_renewal_notice');

        $this->assertSame(['new_invoice_created', 'service_cancelled', 'domain_renewal_notice'], $this->mailed());
    }

    public function test_a_kind_not_taken_is_not_mailed_but_still_shown_in_the_app(): void
    {
        $this->takes(['general', 'support']);

        [$invoice, $service, $domain] = [$this->invoice(), $this->service(), $this->domain()];

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        NotificationHelper::serviceCancelledNotification($this->client, $service);
        NotificationHelper::domainNotification($this->client, $domain, 'domain_renewal_notice');

        $this->assertSame([], $this->mailed());
        Mail::assertNothingQueued();
        $this->assertSame(3, Notification::query()->where('user_id', $this->owner->id)->count(), 'the in-app notifications go anyway');
    }

    public function test_only_the_kinds_taken_are_mailed(): void
    {
        $this->takes(['invoice']);

        [$invoice, $service] = [$this->invoice(), $this->service()];

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);
        NotificationHelper::serviceCancelledNotification($this->client, $service);

        $this->assertSame(['new_invoice_created'], $this->mailed());
    }

    public function test_suspensions_terminations_overdue_and_expiry_notices_always_go(): void
    {
        $this->takes([]);
        [$invoice, $service, $domain] = [$this->invoice(), $this->service(), $this->domain()];

        NotificationHelper::serverSuspendedNotification($this->client, $service);
        NotificationHelper::serverTerminatedNotification($this->client, $service);
        NotificationHelper::invoiceNotification($this->client, $invoice, 'invoice_overdue', ['overdue' => ['days' => 3, 'last' => false, 'suspend_on' => null]]);
        NotificationHelper::domainNotification($this->client, $domain, 'domain_expired_notice');
        NotificationHelper::serverUnsuspendedNotification($this->client, $service);

        $this->assertSame(['server_suspended', 'server_terminated', 'invoice_overdue', 'domain_expired_notice'], $this->mailed());
    }

    public function test_the_logins_own_mails_are_never_gated(): void
    {
        $this->takes([]);

        NotificationHelper::passwordResetNotification($this->owner, ['url' => 'https://example.test/reset']);
        NotificationHelper::loginDetectedNotification($this->owner, ['ip' => '192.0.2.1', 'device' => 'test', 'time' => now()]);

        $this->assertSame(['password_reset', 'new_login_detected'], $this->mailed());
    }

    public function test_staffs_send_email_on_an_invoice_goes_whatever_is_taken(): void
    {
        $this->takes([]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs(Staff::factory()->admin()->create())->test(EditInvoice::class, ['record' => $this->invoice()->getRouteKey()])
            ->callAction('sendEmail', ['template' => 'new_invoice_created'])->assertHasNoActionErrors();

        $this->assertSame(['new_invoice_created'], $this->mailed());
    }

    public function test_the_welcome_mail_resent_by_staff_goes_and_the_automatic_one_does_not(): void
    {
        $this->takes(['invoice']);
        $service = $this->service();

        NotificationHelper::serverCreatedNotification($this->client, $service);
        $this->assertSame([], $this->mailed());

        NotificationHelper::serverCreatedNotification($this->client, $service, resend: true);
        NotificationHelper::serverCreatedNotification($this->client, $service, byStaff: true);
        $this->assertSame(['new_server_created', 'new_server_created'], $this->mailed());
    }

    public function test_a_resend_not_taken_adds_no_in_app_notification(): void
    {
        $this->takes([]);

        NotificationHelper::serverCreatedNotification($this->client, $this->service(), resend: true);

        $this->assertSame(['new_server_created'], $this->mailed(), 'a resend is staff\'s: it goes');
        $this->assertSame(0, Notification::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_every_account_template_has_a_kind(): void
    {
        // A new account template must be given its kind here, or it is gated as «general».
        $notAccount = ['new_login_detected', 'email_verification', 'password_reset', 'client_invite', 'guest_ticket_message', 'ticket_received',
            'ticket_link', 'ticket_cc_message', 'announcement', 'announcement_news'];
        $keys = NotificationTemplate::query()->pluck('key')->diff($notAccount)->sort()->values()->all();

        $this->assertSame($keys, collect(array_keys(EmailKinds::KINDS))->sort()->values()->all());
        $this->assertSame([], array_diff(array_values(EmailKinds::KINDS), Contact::EMAIL_PREFERENCES));
        $this->assertSame([], array_diff(EmailKinds::ALWAYS, array_keys(EmailKinds::KINDS)));
    }

    // ---- the mass mail -------------------------------------------------------------------------------------------

    private function massMail(string $kind, string $target): BulkMail
    {
        $mail = BulkMail::query()->create(['name' => 'Notice', 'kind' => $kind, 'subject' => 'Notice', 'body' => 'Hello', 'filters' => ['target' => $target]]);
        app(BulkMailer::class)->start($mail, null);
        while (app(BulkMailer::class)->sendBatch($mail->refresh(), 100) > 0);

        return $mail;
    }

    public function test_an_announcement_is_the_kind_it_targets(): void
    {
        $this->service();
        $this->takes(['general']);

        $services = $this->massMail(BulkMail::KIND_IMPORTANT, 'services');
        $all = $this->massMail(BulkMail::KIND_IMPORTANT, 'all');

        $this->assertSame([BulkMailRecipient::STATUS_SKIPPED, 'the customer does not take product emails'],
            [$services->recipients()->sole()->status, $services->recipients()->sole()->error]);
        $this->assertSame(BulkMailRecipient::STATUS_SENT, $all->recipients()->sole()->status);
        $this->assertSame(['announcement'], $this->mailed());
    }

    public function test_news_keeps_its_own_opt_out_only(): void
    {
        $this->takes([]);

        $news = $this->massMail(BulkMail::KIND_NEWS, 'all');

        $this->assertSame(BulkMailRecipient::STATUS_SENT, $news->recipients()->sole()->status);
        $this->assertSame(['announcement_news'], $this->mailed());
    }

    // ---- the forms -----------------------------------------------------------------------------------------------

    public function test_the_client_area_shows_none_chosen_as_all_ticked_and_saving_keeps_them(): void
    {
        $this->takes(null);
        $this->client->primaryContact->update(['country' => 'GR']);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Account::class)->assertSet('contact.email_preferences', Contact::EMAIL_PREFERENCES)->call('submit')->assertHasNoErrors();

        $this->assertTrue($this->client->primaryContact->fresh()->takesEmail('invoice'));
    }

    public function test_the_admin_shows_none_chosen_as_all_ticked_and_saving_keeps_them(): void
    {
        $this->takes(null);
        $this->client->primaryContact->update(['country' => 'GR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs(Staff::factory()->admin()->create())->test(EditClient::class, ['record' => $this->client->getRouteKey()])
            ->assertSchemaStateSet(['primaryContact.email_preferences' => Contact::EMAIL_PREFERENCES])
            ->call('save')->assertHasNoErrors();

        $this->assertTrue($this->client->primaryContact->fresh()->takesEmail('domain'));
    }

    public function test_a_new_account_from_the_admin_takes_every_kind_unless_staff_untick_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();
        $create = fn (string $email, ?array $kinds) => $this->livewireAs($staff)->test(CreateClient::class)
            ->assertSchemaStateSet(['contact.email_preferences' => Contact::EMAIL_PREFERENCES])
            ->fillForm(['first_name' => 'A', 'last_name' => 'B', 'email' => $email, 'password' => 'secret-pass-1',
                'contact' => ['type' => 'individual', 'country' => 'GR'] + ($kinds === null ? [] : ['email_preferences' => $kinds])])
            ->call('create')->assertHasNoFormErrors();

        $create('all@example.test', null);
        $create('none@example.test', []);

        $contact = fn (string $email) => User::query()->where('email', $email)->sole()->client()->primaryContact;
        $this->assertTrue($contact('all@example.test')->takesEmail('invoice'));
        $this->assertSame([], $contact('none@example.test')->email_preferences);
    }

    public function test_the_summary_tells_none_from_every_kind(): void
    {
        $this->takes([]);
        $this->assertSame([], ClientSummary::for($this->client, Staff::factory()->admin()->create())['details']['email_preferences']);

        $this->takes(null);
        $this->assertNull(ClientSummary::for($this->client, Staff::factory()->admin()->create())['details']['email_preferences']);
    }
}

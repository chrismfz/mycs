<?php

namespace Tests\Feature;

use App\Admin\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Admin\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/** Independent review of feat/email-templates (5820764). Each test asserts the expected behaviour. */
class EmailTemplatesReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> template keys of the mails queued so far */
    private function queuedKeys(): array
    {
        return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
    }

    /**
     * R1: a service whose product has no server is suspended by the cron (and mailed «suspended… reactivated
     * automatically»), but when the customer pays, RenewServiceService::activate() only dispatches UnsuspendJob when
     * the product has a server: no «reactivated» mail.
     */
    public function test_r1_a_serverless_service_suspended_and_paid_gets_the_reactivation_mail(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]);
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $this->assertNull($product->product->server_id, 'fixture: a product without a server');
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-08', 'currency_code' => 'USD', 'price' => 10.00]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-08']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-08', 'period_end' => '2026-11-08']);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertContains('server_suspended', $this->queuedKeys());

        $invoice->fresh()->transactions()->create(['amount' => $invoice->fresh()->remaining]);
        $this->assertSame('active', $service->fresh()->status);

        $this->assertContains('server_unsuspended', $this->queuedKeys(), 'told it was suspended, never told it is active again');
    }

    /**
     * R2: an end-of-period cancellation of a service without a due date yet (a pending order, cancellable) renders
     * «active until ****,» (empty bold) in both languages.
     */
    public function test_r2_cancellation_of_a_service_without_a_due_date_has_no_empty_date(): void
    {
        MailFacade::fake();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'expires_at' => null, 'currency_code' => 'USD', 'price' => 10.00]);
        $this->assertTrue($service->cancellable, 'fixture: a pending service can be cancelled by the customer');
        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period']));

        foreach (['en', 'el'] as $locale) {
            config(['settings.app_language' => $locale]);
            NotificationHelper::serviceCancellationReceivedNotification($user->client(), $cancellation);
        }

        foreach (MailFacade::queued(Mail::class) as $mail) {
            $this->assertStringNotContainsString('****', $mail->renderedHtml);
        }
    }

    /** R4: staff create a template with only the default text: its Greek text is empty, the mail is the English one. */
    public function test_r4_a_new_template_without_greek_falls_back_to_the_default_text(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->admin()->create();

        $this->livewireAs($owner)->test(CreateNotificationTemplate::class)
            ->fillForm(['key' => 'custom_one', 'enabled' => true, 'subject' => 'Hello {{ user.first_name }}', 'body' => 'Body',
                'mail_enabled' => 'choice_on', 'in_app_enabled' => 'never'])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = NotificationTemplate::query()->where('key', 'custom_one')->firstOrFail();
        $this->assertSame('Hello {{ user.first_name }}', $template->inLocale('el')->subject);
    }

    /** R5: a translated field that does not parse is refused by the form, as the default text is. */
    public function test_r5_a_broken_greek_text_is_refused(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->admin()->create();
        $template = NotificationTemplate::query()->where('key', 'server_suspended')->first();

        $this->livewireAs($owner)->test(EditNotificationTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['translations.el.body' => 'Γεια {% if x %}'])
            ->call('save')
            ->assertHasFormErrors(['translations.el.body']);
    }

    /** R6: fresh install: the migrations insert every template once, with its Greek text; the seeder adds nothing. */
    public function test_r6_fresh_install_has_every_template_once_with_greek(): void
    {
        $rows = DB::table('notification_templates')->get();
        $this->assertSame(count(EmailTemplateSeeder::mapping), $rows->count());
        foreach ($rows as $row) {
            $this->assertNotNull($row->translations, $row->key);
            $this->assertNull($row->updated_at, $row->key);
        }
        (new EmailTemplateSeeder)->run();
        $this->assertSame(count(EmailTemplateSeeder::mapping), DB::table('notification_templates')->count());
    }

    /** R7: a Greek mail has no English left in it: the footer setting says «All rights reserved.» in every language. */
    public function test_r7_a_greek_mail_has_no_english_footer(): void
    {
        MailFacade::fake();
        config(['settings.app_language' => 'el', 'settings.mail_footer' => file_get_contents(database_path('seeders/data/mail_footer'))]);
        $user = User::factory()->create();
        NotificationHelper::passwordResetNotification($user, ['url' => 'https://example.test/r']);

        $html = MailFacade::queued(Mail::class)->last()->render();
        $this->assertStringContainsString('Επαναφορά κωδικού', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    /** R8: the locale is restored when rendering throws (a non-template error in the PDF or the mail). */
    public function test_r8_the_locale_is_put_back_when_sending_throws(): void
    {
        app()->setLocale('en');
        config(['settings.app_language' => 'el']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        EmailLog::creating(fn () => throw new \RuntimeException('database down'));

        $thrown = null;
        try {
            NotificationHelper::invoiceCreatedNotification($user->client(), $invoice->fresh());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'expected the exception');

        $this->assertSame('en', app()->getLocale());
    }
}

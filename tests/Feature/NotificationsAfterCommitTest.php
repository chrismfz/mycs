<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Events\Invoice\Paid;
use App\Events\Order\Finalized as OrderFinalized;
use App\Events\Setting\Saved;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Listeners\FlushCacheListener;
use App\Mail\Mail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail as MailFacade;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit sweep Σ4: notifications go out once committed, one failing never stops what follows, the mail jobs carry
 * rendered mails (no models) and are retried, «payment failed» is mailed once, and the numbering counters do not
 * flush the settings cache.
 */
class NotificationsAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test']);
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7),
        ], $attributes));
    }

    /** EVT-1: rolled back, the customer gets no mail (nor a PDF) for an invoice that does not exist. */
    public function test_an_invoice_rolled_back_is_never_announced(): void
    {
        $fired = [];
        Event::listen(InvoiceFinalized::class, function (InvoiceFinalized $event) use (&$fired) {
            $fired[] = $event->invoice->id;
        });
        MailFacade::fake();

        try {
            DB::transaction(function () {
                $this->invoice();
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $fired);
        MailFacade::assertNothingQueued();
        $this->assertSame(0, EmailLog::query()->count());
    }

    /** EVT-1: inside a transaction it waits for the commit (not for the end of the response, which a worker never has). */
    public function test_invoice_and_order_are_announced_once_committed(): void
    {
        $fired = [];
        Event::listen(InvoiceFinalized::class, function () use (&$fired) {
            $fired[] = 'invoice';
        });
        Event::listen(OrderFinalized::class, function () use (&$fired) {
            $fired[] = 'order';
        });

        DB::transaction(function () use (&$fired) {
            $user = User::factory()->create();
            $this->invoice(['user_id' => $user->id]);
            Order::query()->create(['user_id' => $user->id, 'currency_code' => 'USD']);
            $this->assertSame([], $fired, 'announced before the commit');
        });

        $this->assertSame(['invoice', 'order'], $fired);
    }

    /** The new-invoice mail goes out (queued listener, PDF made in the job) with the invoice attached. */
    public function test_the_new_invoice_mail_is_sent_with_its_pdf(): void
    {
        MailFacade::fake();

        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        // Created by the observer, mailed by the queued listener (sync in tests).
        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created' && count($mail->diskAttachments) === 1);
    }

    /** EVT-2: a Paid listener that throws is reported; the callbacks after it in the same commit still run. */
    public function test_a_failing_paid_listener_does_not_stop_what_follows_the_payment(): void
    {
        Event::listen(Paid::class, fn () => throw new RuntimeException('PDF failed'));
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $after = false;

        DB::transaction(function () use ($invoice, &$after) {
            (new RecordPaymentService)->handle($invoice->id, null, 10, transactionId: 'evt-2');
            DB::afterCommit(function () use (&$after) {
                $after = true;
            });
        });

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue($after, 'an after-commit callback behind the failing listener was dropped');
    }

    /** EVT-10: the queued mail is rendered; its payload has no models, so no password hash. */
    public function test_the_mail_job_carries_the_rendered_mail_not_the_models(): void
    {
        MailFacade::fake();
        $user = User::factory()->create(['password' => 'a-password']);
        $invoice = $this->invoice(['user_id' => $user->id]);

        NotificationHelper::invoiceCreatedNotification($user, $invoice);

        MailFacade::assertQueued(Mail::class, function (Mail $mail) use ($user) {
            $payload = serialize($mail);
            $this->assertStringNotContainsString($user->getAuthPassword(), $payload);
            $this->assertStringNotContainsString('App\\Models\\User', $payload);
            $this->assertNotSame('', $mail->renderedSubject);
            $this->assertSame(5, $mail->tries);
            $this->assertNotEmpty($mail->backoff);

            return true;
        });
    }

    /** EVT-10: a system mail's log is marked sent too (only customer mails were). */
    public function test_a_system_mails_log_is_marked_sent(): void
    {
        config(['mail.default' => 'array']);

        NotificationHelper::sendSystemEmailNotification('Something for staff', 'Body');

        $this->assertSame('sent', EmailLog::query()->where('subject', 'Something for staff')->value('status'));
    }

    /** EVT-14: a failed transaction is mailed once: not again when it is saved later, nor for the cron's own notice. */
    public function test_payment_failed_is_mailed_once(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        $transaction = ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10, null, 'pi_failed');
        $transaction->update(['fee' => 0.30]); // a later save of the same failed transaction
        NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice); // the cron's catch, same attempt

        $this->assertSame(InvoiceTransactionStatus::Failed, $transaction->fresh()->status);
        $this->assertCount(1, MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed'));
    }

    /** EVT-13: moving a numbering counter does not flush the settings cache (nor restart the queue workers). */
    public function test_a_counter_does_not_flush_the_settings_cache(): void
    {
        $callbacks = new ReflectionProperty(app(), 'terminatingCallbacks');
        $before = count($callbacks->getValue(app()));

        $counter = Setting::query()->updateOrCreate(['key' => 'invoice_number', 'settingable_type' => null], ['value' => 41]);
        (new FlushCacheListener)->handle(new Saved($counter));

        $this->assertCount($before, $callbacks->getValue(app()));
    }

    /** EVT-13: the settings page shows the counter as stored, not as cached. */
    public function test_the_settings_page_reads_the_counter_from_the_database(): void
    {
        Setting::query()->updateOrCreate(['key' => 'invoice_number', 'settingable_type' => null], ['value' => 1234]);
        config(['settings.invoice_number' => 7]); // the cache, behind
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        Livewire::actingAs($owner)->test(Settings::class)
            ->assertSet('data.invoice_number', 1234);
    }
}

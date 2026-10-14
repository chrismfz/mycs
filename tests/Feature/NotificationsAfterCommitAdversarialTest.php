<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Events\Invoice\GeneratePdf;
use App\Events\Invoice\Paid;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Livewire\Livewire;
use Paymenter\Extensions\Others\DiscordNotifications\DiscordNotifications;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of fix/notifications-after-commit (audit sweep Σ4), money paths only. Findings fail; test_holds_*
 * are attacks that did not break anything.
 */
class NotificationsAfterCommitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test',
            'settings.credits_auto_use' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7),
        ], $attributes));
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        }

        return $invoice;
    }

    /** @return array<int, int> ids of invoices Finalized fired for */
    private function finalizedSpy(): \ArrayObject
    {
        $fired = new \ArrayObject;
        Event::listen(InvoiceFinalized::class, function (InvoiceFinalized $event) use ($fired) {
            $fired[] = $event->invoice->id;
        });

        return $fired;
    }

    private function failedMails(): int
    {
        return MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'invoice_payment_failed')->count();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * F1 (medium). invoicePaymentFailedNotification takes the 12h dedupe key (Cache::add) BEFORE the PDF and the mail
     * are made. When that work throws (dompdf, a full disk, a template error not caught, the DB blip), the queued
     * SendMailListener is retried (tries=5) — and every retry finds the key and returns silently. The customer whose
     * renewal charge failed is never told; the service is then suspended for non-payment with no warning. Same on the
     * cron path: its catch mails directly, the key is taken, and the gateway's later failed webhook is suppressed too.
     */
    public function test_f1_payment_failed_mail_is_sent_by_the_retry_after_a_failed_attempt(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();
        $failOnce = true;
        Event::listen(GeneratePdf::class, function () use (&$failOnce) {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('dompdf: out of memory');
            }
        });

        // First attempt of the queued job: fails after the key was taken.
        try {
            NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice);
            $this->fail('the first attempt was expected to throw');
        } catch (RuntimeException) {
        }
        // The queue retries the job (backoff 60s): same call.
        NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice->fresh());

        $this->assertSame(1, $this->failedMails(), 'the retry was swallowed by the dedupe key: «payment failed» never mailed');
    }

    /**
     * F2 (low, design). The dedupe is per invoice for 12h, not per attempt: the cron's renewal charge fails at 03:00
     * (mailed), the customer tries again by hand with another card at 09:00 and that new transaction fails too — no
     * mail for the second, real, separate failure. The comment says it only wants to merge the cron notice with the
     * gateway webhook of the SAME attempt; a transaction id (or attempt) in the key would do that.
     */
    public function test_f2_a_new_failed_attempt_hours_later_is_mailed(): void
    {
        MailFacade::fake();
        Carbon::setTestNow('2026-10-20 03:00:00');
        $invoice = $this->invoice();

        ExtensionHelper::addFailedPayment($invoice->id, null, 10, null, 'pi_cron_attempt');
        Carbon::setTestNow('2026-10-20 09:00:00');
        ExtensionHelper::addFailedPayment($invoice->id, null, 10, null, 'pi_customer_retry');

        $this->assertSame(2, $this->failedMails(), 'a second, separate failed attempt 6 hours later was not mailed');
    }

    /**
     * F3 (low). «Rolled back, nothing fires» covers the transaction, not what it undid: an invoice created and deleted
     * in the same committed transaction (checkout that builds the invoice then drops it, a staff edit) still fires
     * Finalized after the commit with the in-memory model. SendMailListener drops it (deleteWhenMissingModels), but
     * synchronous listeners do not: DiscordNotifications announces «Invoice Created» for an invoice that never existed,
     * and any extension on Finalized gets a deleted model.
     */
    public function test_f3_an_invoice_created_and_deleted_in_one_transaction_is_not_announced(): void
    {
        $fired = $this->finalizedSpy();

        DB::transaction(function () {
            $invoice = $this->invoice();
            $invoice->items()->delete();
            $invoice->delete();
        });

        $this->assertSame([], $fired->getArrayCopy(), 'Finalized fired for an invoice deleted before the commit');
    }

    /**
     * F4 (low, secrets). The Discord job is a queued closure capturing the webhook URL (a credential: whoever has it
     * posts into the staff channel) and, through the arrow function's bound $this, the extension's whole config. On
     * the database queue it lands in clear in `jobs` and, when Discord is down, in `failed_jobs` (kept, in backups).
     */
    public function test_f4_the_discord_webhook_secret_is_not_stored_in_the_jobs_table(): void
    {
        config(['queue.default' => 'database']);
        $secret = 'https://discord.com/api/webhooks/123/s3cr3t-token-do-not-leak';
        (new DiscordNotifications(['webhook_url' => $secret, 'events' => ['Invoice Paid'], 'ping_type' => 'none']))->boot();
        Http::fake();

        event(new Paid($this->invoice()->fresh()));

        $payloads = DB::table('jobs')->pluck('payload')->implode("\n");
        $this->assertNotSame('', $payloads, 'no job was queued');
        $this->assertStringNotContainsString('s3cr3t-token-do-not-leak', $payloads, 'the Discord webhook token is stored in clear in the jobs table');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Attacks that hold
    // ---------------------------------------------------------------------------------------------------------------

    /** Nested: an inner savepoint rolled back (caught) inside a committed outer transaction announces nothing. */
    public function test_holds_an_inner_savepoint_rolled_back_announces_nothing(): void
    {
        $fired = $this->finalizedSpy();
        $kept = null;

        DB::transaction(function () use (&$kept) {
            $kept = $this->invoice();
            try {
                DB::transaction(function () {
                    $this->invoice();
                    throw new RuntimeException('inner rolled back');
                });
            } catch (RuntimeException) {
            }
        });

        $this->assertSame([$kept->id], $fired->getArrayCopy());
        $this->assertSame(1, Invoice::query()->count());
    }

    /** Nested: an inner transaction committed, the outer rolled back: nothing announced. */
    public function test_holds_inner_committed_outer_rolled_back_announces_nothing(): void
    {
        $fired = $this->finalizedSpy();

        try {
            DB::transaction(function () {
                DB::transaction(fn () => $this->invoice());
                throw new RuntimeException('outer rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $fired->getArrayCopy());
    }

    /** Proforma: draft announces nothing; draft → unpaid announces once; a later save (or payment) not again. */
    public function test_holds_proforma_announces_once_on_publish(): void
    {
        config(['settings.invoice_proforma' => true]);
        $fired = $this->finalizedSpy();

        $invoice = $this->invoice(['status' => Invoice::STATUS_DRAFT]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->assertSame([], $fired->getArrayCopy());

        DB::transaction(fn () => $invoice->update(['status' => Invoice::STATUS_UNPAID]));
        $invoice->fresh()->update(['notes' => 'edited']);
        (new RecordPaymentService)->handle($invoice->id, null, 10, transactionId: 'pf-1');

        $this->assertSame([$invoice->id], $fired->getArrayCopy());
        $this->assertNotNull($invoice->fresh()->number);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /**
     * A webhook payment whose Paid listener throws: the gateway is answered normally (no 500, no retry that would pay
     * twice), the renewal is applied, and the next invoice paid in the same commit is applied too.
     */
    public function test_holds_a_failing_paid_listener_never_loses_the_renewal(): void
    {
        Carbon::setTestNow('2026-10-20 03:00:00');
        MailFacade::fake();
        $product = $this->createProduct();
        $services = collect([1, 2])->map(fn () => Service::factory()->create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]));
        $invoices = $services->map(function (Service $service) {
            $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

            return $invoice;
        });
        Event::listen(Paid::class, fn () => throw new RuntimeException('snapshot failed'));

        DB::transaction(function () use ($invoices) {
            foreach ($invoices as $i => $invoice) {
                ExtensionHelper::addPayment($invoice->id, null, 10, null, "pi_ok_{$i}");
            }
        });

        foreach ($services as $i => $service) {
            $this->assertSame('2026-11-25', $service->fresh()->expires_at->toDateString(), "service {$i} not renewed");
            $this->assertNull($invoices[$i]->fresh()->apply_pending_since);
        }
    }

    /** The settings cache is no longer flushed by the counter: stale config never numbers an invoice. */
    public function test_holds_numbers_stay_unique_with_a_stale_settings_cache(): void
    {
        Setting::query()->updateOrCreate(['key' => 'invoice_number', 'settingable_type' => null], ['value' => 41]);
        config(['settings.invoice_number' => 3]); // the cache, behind

        $numbers = collect(range(1, 3))->map(fn () => $this->invoice()->number)->all();

        $this->assertSame(['42', '43', '44'], $numbers);
        $this->assertSame('44', Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value'));
    }

    /** Staff save another setting with the page opened before invoices moved the counter: the counter is kept. */
    public function test_holds_the_settings_page_never_rewinds_a_counter_moved_by_invoices(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        Setting::query()->updateOrCreate(['key' => 'invoice_number', 'settingable_type' => null], ['value' => 10]);
        config(['settings.invoice_number' => 2, 'settings.mail_disable' => true]); // mail fields required otherwise

        $page = $this->livewireAs($owner)->test(Settings::class)->assertSet('data.invoice_number', '10');
        $this->invoice();
        $this->invoice(); // counter 12, config still 2

        $page->set('data.app_language', 'el')->call('save')->assertHasNoErrors();
        $page->set('data.app_language', 'en')->call('save')->assertHasNoErrors(); // a second save from the same page
        $this->invoice();

        $this->assertSame('13', Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value'));
        $this->assertSame(1, Invoice::query()->where('number', '13')->count());
    }

    /** Database queue: the mail job is not in `jobs` before the commit; once committed a worker sends it. */
    public function test_holds_the_mail_job_is_queued_after_commit_and_sent_by_the_worker(): void
    {
        config(['queue.default' => 'database']);
        MailFacade::fake();

        DB::transaction(function () {
            $this->invoice();
            $this->assertSame(0, DB::table('jobs')->count(), 'a job was queued before the commit');
        });
        $this->assertGreaterThan(0, DB::table('jobs')->count());

        while (DB::table('jobs')->count() > 0) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
        }

        $this->assertSame(0, DB::table('failed_jobs')->count());
        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created');
    }

    /** The dedupe key is per invoice: two invoices failing at once are both mailed. */
    public function test_holds_payment_failed_dedupe_is_per_invoice(): void
    {
        MailFacade::fake();
        $a = $this->invoice();
        $b = $this->invoice(['user_id' => $a->user_id]);

        ExtensionHelper::addFailedPayment($a->id, null, 10, null, 'pi_a');
        ExtensionHelper::addFailedPayment($b->id, null, 10, null, 'pi_b');

        $this->assertSame(2, $this->failedMails());
    }

    /** A failed payment rolled back with its transaction is never mailed (nor takes the dedupe key). */
    public function test_holds_a_rolled_back_failed_payment_is_not_mailed_nor_dedupes_the_next(): void
    {
        MailFacade::fake();
        $invoice = $this->invoice();

        try {
            DB::transaction(function () use ($invoice) {
                ExtensionHelper::addFailedPayment($invoice->id, null, 10, null, 'pi_rolled');
                throw new RuntimeException('webhook crashed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $this->failedMails());

        ExtensionHelper::addFailedPayment($invoice->id, null, 10, null, 'pi_real');
        $this->assertSame(1, $this->failedMails());
    }

    /** The Discord job, run by a worker from the database queue, posts (no serialisation failure). */
    public function test_holds_the_discord_job_runs_from_the_database_queue(): void
    {
        config(['queue.default' => 'database']);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['Invoice Paid'], 'ping_type' => 'none']))->boot();
        Http::fake(['https://discord.test/*' => Http::response([])]);
        MailFacade::fake();

        $invoice = $this->invoice();
        DB::table('jobs')->delete(); // the new-invoice mail, not under test
        event(new Paid($invoice->fresh()));
        while (DB::table('jobs')->count() > 0) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
        }

        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.test'));
    }
}

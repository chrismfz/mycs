<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Adversarial review of #290 (a log that cannot be written no longer aborts the cron). Tests that expose a defect fail
 * on the PR head; the others probe a worry and pass, and say so.
 */
class CronLogFailureAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]);
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    /**
     * FINDING ADV290-1. The PR says the CronJob guard keeps the run going "even on a channel without that setting (an
     * operator's LOG_CHANNEL=daily)". It does so per step only: the per-item catches inside the services the cron calls
     * (CreditAutoApply::sweep, AutoCharge::run, PaymentReminders, CancelOpenInvoices, RecordPaymentService's
     * after-commit work) call the bare report(), which throws out of the loop. One customer's failed credit payment
     * then leaves every later customer's credit unapplied, and the same run suspends a service the credit had paid.
     */
    public function test_on_a_bare_daily_channel_one_customers_credit_failure_still_leaves_the_others_paid_and_running(): void
    {
        $this->breakTheLog();
        config(['logging.default' => 'daily']);
        [$fine, $service] = $this->twoCustomersInCreditOneBroken();

        $this->runTheCron();

        $this->assertSame(Invoice::STATUS_PAID, $fine->fresh()->status, 'the second customer\'s credit pays their overdue invoice');
        $this->assertSame('active', $service->fresh()->status, 'and their service is not suspended in the same run');
    }

    /** Held (probe): the same with the stack as shipped (ignore_exceptions), the other customers are paid. */
    public function test_with_the_stack_as_shipped_one_customers_credit_failure_leaves_the_others_paid(): void
    {
        $this->breakTheLog();
        [$fine, $service] = $this->twoCustomersInCreditOneBroken();

        $this->runTheCron();

        $this->assertSame(Invoice::STATUS_PAID, $fine->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);
    }

    /**
     * FINDING ADV290-2. The guard's only record of what could not be logged is the console ("cron mails or journals
     * it"). Production runs the cron through `schedule:run` (docs/HANDOFF.md), and the event has no output target: Laravel
     * sends its output to /dev/null. The «Not logged (…)» line, the failure's class and message, reaches nobody.
     *
     * Fixed in #290 another way (the author): the console line is gone; the failure is kept (App\Logging\WriteFailures)
     * and mailed to staff with the cron's notices. This test asserted the scheduler's output target; it now asserts the
     * mail, which reaches someone on a full disk where a cron.log file would not.
     */
    public function test_the_scheduled_cron_keeps_its_console_output_somewhere(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->breakTheLog();
        config(['logging.default' => 'daily']);
        $this->twoCustomersInCreditOneBroken();

        $this->runTheCron();

        $body = html_entity_decode((string) EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->latest('id')->value('body'), ENT_QUOTES);
        $this->assertStringContainsString('log_not_written: The application log could not be written (UnexpectedValueException: Writing to the log file failed', $body);
    }

    /**
     * Held (probe): the PR's "not covered" note says `Log::channel('registrar-debug')` still throws after a successful
     * EPP renew. It does not reach the renewal: TracingTransport catches its callback's failure. A renew with the debug
     * log unwritable is done once and its result is read as before. The note in docs/decisions.md is inaccurate.
     */
    public function test_a_registrar_debug_log_that_cannot_be_written_never_changes_a_renewal(): void
    {
        config(['logging.channels.registrar-debug' => ['driver' => 'monolog', 'handler' => AdvFullDiskLogHandler::class]]);
        Log::forgetChannel('registrar-debug');
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production', 'debug_log' => true]);
        $transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $transport;
        GrEppRegistrar::reset();
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $user->client()->id, 'country' => 'GR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => $user->client()->id, 'sld' => 'example1', 'tld' => 'gr', 'fqdn' => 'example1.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2, 'registrant_contact_id' => $contact->id, 'admin_contact_id' => $contact->id,
            'tech_contact_id' => $contact->id, 'billing_contact_id' => $contact->id]);
        AdvFullDiskLogHandler::$writes = 0;
        $transport->fixture('02-hello-response.xml', ['JSESSIONID=C1'])->fixture('04-login-response.xml');
        foreach (['live/domain-info-response.xml', 'v4.3/52-domain-renew-response.xml', 'live/domain-info-response.xml'] as $name) {
            $body = (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', (string) file_get_contents(base_path("packages/gr-epp/tests/fixtures/{$name}")));
            $transport->queue(new HttpResponse(200, $body));
        }

        $result = (new GrEppRegistrar)->renew($domain, 2, new DomainRegistrarCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false, debug: true));

        $this->assertGreaterThan(0, AdvFullDiskLogHandler::$writes, 'the debug log was written to, and failed');
        $this->assertNotNull($result->expiresAt);
        $this->assertSame(1, collect($transport->kinds())->filter(fn ($kind) => $kind === 'renew')->count(), 'renew sent once');
    }

    /**
     * Held (probe): the PR's third test ("the log failing with no failed step is a successful run") is not vacuous:
     * the run does write to the broken log, and the run still succeeds.
     */
    public function test_a_run_with_no_failed_step_does_write_to_the_broken_log(): void
    {
        $this->breakTheLog();
        AdvFullDiskLogHandler::$writes = 0;
        Log::info('one write at least'); // the stack as shipped swallows it
        $this->assertGreaterThan(0, AdvFullDiskLogHandler::$writes);

        $this->runTheCron();

        $this->assertDatabaseHas('cron_stats', ['key' => 'email_logs_deleted']);
    }

    // ---------------------------------------------------------------- helpers

    private function breakTheLog(): void
    {
        config(['logging.channels.daily' => ['driver' => 'monolog', 'handler' => AdvFullDiskLogHandler::class]]);
        Log::forgetChannel('daily');
        Log::forgetChannel('stack');
    }

    /**
     * Customer A (first by credit id) whose credit payment fails, and customer B: credit 10.00, a service expired 5 days
     * ago and its open renewal invoice of 10.00.
     *
     * @return array{0: Invoice, 1: Service}
     */
    private function twoCustomersInCreditOneBroken(): array
    {
        $broken = User::factory()->create();
        $other = User::factory()->create();
        $brokenInvoice = Invoice::factory()->create(['client_id' => $broken->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7)]);
        $brokenInvoice->items()->create(['description' => 'Line', 'quantity' => 1, 'price' => 10]);
        $this->giveCredit($broken, 1000);

        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $other->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->subDays(5)]);
        $fine = Invoice::factory()->create(['client_id' => $other->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(5)]);
        $fine->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => now()->subDays(5)->toDateString(), 'period_end' => now()->addDays(25)->toDateString()]);
        $this->giveCredit($other, 1000);

        InvoiceTransaction::creating(function (InvoiceTransaction $transaction) use ($brokenInvoice) {
            if ((int) $transaction->invoice_id === $brokenInvoice->id) {
                throw new \RuntimeException('credit payment broke');
            }
        });

        return [$fine, $service];
    }

    private function giveCredit(User $user, int $cents): void
    {
        (new CreditLedger)->add($user->client()->id, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    private function runTheCron(): ?\Throwable
    {
        try {
            Artisan::call('app:cron-job');
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}

/** A log file on a full disk, as Monolog's StreamHandler fails there. */
class AdvFullDiskLogHandler extends AbstractProcessingHandler
{
    public static int $writes = 0;

    protected function write(LogRecord $record): void
    {
        self::$writes++;

        throw new UnexpectedValueException('Writing to the log file failed: Write of 512 bytes failed with errno=28 No space left on device');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Listeners\CancellationCreatedListener;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Adversarial review of PR #71 (upgrade lifecycle: SVC-6, BIL-11, R9). Failing tests are findings; «holds» tests are
 * attacks that did not get through.
 */
class UpgradeLifecycleAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct(['stock' => 5]);

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    private function upgrade(Service $service, ?Invoice $invoice = null, float $price = 5): ServiceUpgrade
    {
        $target = $this->createProduct(['stock' => 5]);
        $invoice ??= Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => $price]);

        return $upgrade;
    }

    private function staffNotices(): int
    {
        return EmailLog::query()->where('subject', 'like', '%attention%')->count();
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    // ---------------------------------------------------------------- findings

    /**
     * A1. refreshUpgrade reads the invoice's status without its lock, cancels the upgrade, and only then locks the
     * invoice (CancelOpenInvoices). A payment committing in between: the invoice is paid, CancelOpenInvoices sees it
     * paid and does nothing, the payment's ProcessPaid finds the upgrade cancelled and returns silently. Money kept,
     * nothing delivered, nobody told. Same window in CancelPendingUpgrades::forService.
     */
    public function test_a1_a_payment_landing_while_the_cron_cancels_an_expired_upgrade_is_not_lost(): void
    {
        $service = $this->service(['expires_at' => '2026-10-19']);
        $upgrade = $this->upgrade($service);
        $paid = false;
        ServiceUpgrade::updated(function (ServiceUpgrade $changed) use ($upgrade, &$paid) {
            if (!$paid && $changed->id === $upgrade->id && $changed->status === ServiceUpgrade::STATUS_CANCELLED) {
                $paid = true; // the customer's payment commits right after the cron read the invoice as pending
                (new RecordPaymentService)->handle($upgrade->invoice_id, null, 5, transactionId: 'race-pay');
            }
        });

        $this->artisan('app:cron-job');

        $this->assertTrue($paid);
        $invoice = $upgrade->invoice->fresh();
        $lost = $invoice->status === Invoice::STATUS_PAID && $upgrade->fresh()->status === ServiceUpgrade::STATUS_CANCELLED;
        $this->assertFalse($lost && $this->staffNotices() === 0,
            'paid upgrade cancelled under a race: money kept, nothing delivered, no staff notice');
    }

    /**
     * A2. A paid upgrade of a service that no longer runs is cancelled with only a Log::warning: the money stays, nothing
     * is delivered, and staff (who are told to «refund or credit it») get no notice — the cron's paid_applied, which
     * mails a notice for a refunded invoice, says nothing here and clears apply_pending_since.
     */
    public function test_a2_a_paid_upgrade_of_a_dead_service_is_surfaced_to_staff(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']);

        (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'late-pay');
        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $upgrade->invoice->fresh()->status);
        $this->assertGreaterThan(0, $this->staffNotices(), 'money kept for an upgrade not delivered: staff must be told');
    }

    /**
     * A3. Termination cancels the pending upgrade, but its invoice (part paid by a gateway) is left open for staff: the
     * line of a cancelled upgrade stays payable. The customer pays the rest: the invoice is paid, ProcessPaid finds the
     * upgrade cancelled and returns without even the Log::warning of A2.
     */
    public function test_a3_a_cancelled_upgrade_is_not_left_payable_on_an_open_invoice(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-09-05']);
        $upgrade = $this->upgrade($service);
        (new RecordPaymentService)->handle($upgrade->invoice, null, 2, transactionId: 'part-gw');

        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        (new RecordPaymentService)->handle($upgrade->invoice->fresh(), null, 3, transactionId: 'rest');

        $invoice = $upgrade->invoice->fresh();
        // Fixed: the upgrade waited with its invoice kept open; paid in full for a service that no longer runs, it is
        // refused and staff are told to refund or credit it (A2).
        $this->assertFalse($upgrade->fresh()->status === ServiceUpgrade::STATUS_CANCELLED && $invoice->status === Invoice::STATUS_PAID
            && EmailLog::query()->where('subject', 'Paid upgrade not applied: needs staff attention')->doesntExist(),
            'a cancelled upgrade was paid in full afterwards: money kept, nothing delivered, nobody told');
    }

    /**
     * A4. Deleting the upgrade's target plan (or product) cascades to service_upgrades: the line on the upgrade invoice
     * points at nothing, the cron (which walks upgrades) never cancels its invoice, and paying it delivers nothing
     * (ProcessPaid skips a missing upgrade silently). Pre-existing, but the invoice outlives the upgrade and its service.
     */
    public function test_a4_an_upgrade_invoice_does_not_outlive_its_deleted_upgrade(): void
    {
        // Fixed by BIL-6: a plan (or product) an upgrade uses is not deleted, so the upgrade and its invoice stay together.
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-09-05']);
        $upgrade = $this->upgrade($service);
        try {
            Plan::query()->whereKey($upgrade->plan_id)->first()->delete();
        } catch (DisplayException) {
        }
        $this->assertNotNull(ServiceUpgrade::find($upgrade->id), 'no cascade');

        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertNotSame(Invoice::STATUS_UNPAID, Invoice::find($upgrade->invoice_id)->status,
            'an upgrade invoice of a terminated service is still payable');
    }

    /**
     * A5. The service's own plan deleted (plans → services.plan_id nullOnDelete): ServiceUpgrade::calculatePrice reads
     * $service->plan->billing_period on null; the upgrade step fails, and the whole cron run ends in «Cron Job Error»,
     * every run, until the service's due date.
     */
    public function test_a5_the_cron_survives_an_upgrade_of_a_service_whose_plan_was_deleted(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        // As a delete before BIL-6 left it (the foreign key now refuses the delete).
        Schema::withoutForeignKeyConstraints(fn () => DB::table('services')->where('id', $service->id)->update(['plan_id' => null]));
        $this->assertNull($service->fresh()->plan_id);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status);
    }

    /**
     * (was A6) Cron order: credit_applied runs before upgrade_invoices_updated. Credit never pays an upgrade invoice
     * (CreditAutoApply::eligible excludes ServiceUpgrade lines), so it cannot pay one the same run then cancels.
     */
    public function test_holds_credit_never_pays_an_upgrade_invoice_in_the_run(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $service = $this->service(['expires_at' => '2026-10-19']);
        Client::whereKey($service->client_id)->update(['credit_auto_apply' => true]);
        $upgrade = $this->upgrade($service);
        (new CreditLedger)->add((int) $service->client_id, 'USD', 10000, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_SYSTEM);

        $this->artisan('app:cron-job');

        $this->assertNotSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertNotSame(Invoice::STATUS_PAID, $upgrade->invoice->fresh()->status);
    }

    /**
     * A7. A downgrade (or any new, lower recurring amount) keeps the higher price on a renewal invoice with a payment
     * on it (ChangeOpenLine refuses it, by design). Nobody is told: the customer pays the rest at the old plan's price
     * for a period on the new plan. Same, the other way, for an upgrade (under-billed). Not surfaced.
     */
    public function test_a7_a_renewal_line_kept_at_the_old_price_is_surfaced(): void
    {
        $service = $this->service(['expires_at' => '2026-10-25']);
        $renewal = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $line = $renewal->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        (new RecordPaymentService)->handle($renewal, null, 4, transactionId: 'part');
        $upgrade = $this->upgrade($service, price: 0);
        $upgrade->product->plans()->first()->prices()->update(['price' => 2]); // a downgrade to 2/month
        $warnings = [];
        Log::listen(function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertSame('2.00', self::money($service->fresh()->price));
        $this->assertSame('10.00', self::money($line->fresh()->price));
        $logged = collect($warnings)->contains(fn ($message) => str_contains($message, 'keeps its price')); // through PaymentsLog
        $this->assertTrue($logged || $this->staffNotices() > 0, 'renewal billed at the old plan price after a downgrade, silently');
    }

    /**
     * A8. CancellationCreatedListener (immediate) neither locks nor re-reads its service: an upgrade applied after the
     * job loaded the service (a payment, at the same moment) and the listener still gives the stock back to the old
     * product, which the upgrade already gave back. Old product +2, new product -1 for one service that ended.
     */
    public function test_a8_an_immediate_cancellation_racing_an_upgrade_gives_stock_back_once(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create([
            'service_id' => $service->id, 'type' => 'immediate', 'reason' => 'leaving']));
        $cancellation->load('service.product'); // the queued job has loaded its service

        (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'pay'); // applied meanwhile
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock, 'old product: back once (by the upgrade)');
        $this->assertSame(5, (int) $upgrade->product->fresh()->stock, 'new product: taken by the upgrade, back by the cancellation');
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_stock_moves_once_when_the_cron_applies_a_paid_invoice_again(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'pay');
        DB::table('invoices')->where('id', $upgrade->invoice_id)->update(['apply_pending_since' => now()->subHour()]); // as if not applied

        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock);
        $this->assertSame(4, (int) $upgrade->product->fresh()->stock);
    }

    public function test_holds_paid_pending_paid_applies_once(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        $payment = (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'p1');
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame(Invoice::STATUS_UNPAID, $upgrade->invoice->fresh()->status);
        (new RecordPaymentService)->handle($upgrade->invoice->fresh(), null, 5, transactionId: 'p2');

        $this->assertSame(6, (int) $service->product->fresh()->stock);
        $this->assertSame(4, (int) $upgrade->product->fresh()->stock);
    }

    public function test_holds_an_upgrade_line_on_an_invoice_with_a_processing_payment_keeps_its_price(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service, price: 1.23);
        (new RecordPaymentService)->handle($upgrade->invoice, null, 1, transactionId: 'sepa', status: InvoiceTransactionStatus::Processing);

        $this->artisan('app:cron-job');

        $this->assertSame('1.23', self::money($upgrade->invoice->items()->first()->price));
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status);
    }

    public function test_holds_an_upgrade_line_with_nothing_paid_follows_the_price(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service, price: 1.23);

        $this->artisan('app:cron-job');

        $this->assertNotSame('1.23', self::money($upgrade->invoice->items()->first()->price));
    }

    public function test_holds_a_renewal_line_on_a_paid_invoice_is_not_repriced(): void
    {
        $service = $this->service(['expires_at' => '2026-10-25']);
        $renewal = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $line = $renewal->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        DB::table('invoices')->where('id', $renewal->id)->update(['status' => 'paid']);
        $upgrade = $this->upgrade($service);
        $upgrade->product->plans()->first()->prices()->update(['price' => 20]);

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertSame('10.00', self::money($line->fresh()->price));
    }

    public function test_holds_a_suspended_service_is_upgraded(): void
    {
        $service = $this->service(['status' => 'suspended']);
        $upgrade = $this->upgrade($service);

        (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'pay');

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame($upgrade->product_id, $service->fresh()->product_id);
    }

    public function test_holds_an_expired_upgrade_on_an_invoice_billing_another_service_takes_only_its_line(): void
    {
        $service = $this->service(['expires_at' => '2026-10-19']);
        $other = $this->service(['client_id' => $service->client_id, 'expires_at' => '2026-12-01']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $other->id, 'description' => 'Other',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-12-01', 'period_end' => '2027-01-01']);
        $upgrade = $this->upgrade($service, $invoice);

        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([Service::class], $invoice->items()->pluck('reference_type')->all());
    }

    public function test_holds_an_expired_upgrade_on_a_draft_invoice_is_cancelled_with_it(): void
    {
        $service = $this->service(['expires_at' => '2026-10-19']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'draft', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = $this->upgrade($service, $invoice);

        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_holds_an_upgrade_without_an_invoice(): void
    {
        $service = $this->service(['expires_at' => '2026-10-19']);
        $target = $this->createProduct();
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id]);
        $live = $this->service();
        $live2 = ServiceUpgrade::query()->create(['service_id' => $live->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $live2->fresh()->status);
    }

    public function test_holds_staff_cancelled_then_the_cron_runs_then_a_late_payment_delivers_nothing_and_moves_no_stock(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']);

        $this->artisan('app:cron-job');
        $this->assertSame(Invoice::STATUS_CANCELLED, $upgrade->invoice->fresh()->status);

        $this->assertSame($service->product_id, $service->fresh()->product_id);
        $this->assertSame(5, (int) $service->product->fresh()->stock);
        $this->assertSame(5, (int) $upgrade->product->fresh()->stock);
    }
}

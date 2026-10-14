<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of MT-2b (PR #67): cancellations through the model and line by line (SVC-4), audit in workers.
 *
 * test_<what should hold> fails on the PR head and proves a finding; test_holds_<...> are guards that pass.
 * Findings fixed in the review-fixes commit; F6 is fixed differently (an invoice with payments keeps its price), so
 * its last assertion follows that fix.
 */
class CancelOpenInvoicesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        $this->user = User::factory()->create();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]); // the days these fixtures assume (main's #66 changed the defaults)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-30', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function emptyInvoice(string $status = 'unpaid'): Invoice
    {
        return Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status, 'currency_code' => 'USD']);
    }

    private function renewal(Invoice $invoice, Service $service, float $price = 10): InvoiceItem
    {
        return $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id,
            'description' => 'Renewal', 'quantity' => 1, 'price' => $price]);
    }

    /** @param  array<int, Service>  $services */
    private function invoice(array $services): Invoice
    {
        $invoice = $this->emptyInvoice();
        foreach ($services as $service) {
            $this->renewal($invoice, $service);
        }

        return $invoice;
    }

    private function upgrade(Service $service, Invoice $invoice, float $price = 10): ServiceUpgrade
    {
        $bigger = $this->createProduct();
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => $price]);

        return $upgrade;
    }

    private function request(Service $service, string $type = 'immediate'): ServiceCancellation
    {
        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function bills(Invoice $invoice, Service $service): bool
    {
        return $invoice->items()->where('reference_type', Service::class)->where('reference_id', $service->id)->exists();
    }

    // ---------------------------------------------------------------- findings (fail on the PR head)

    /**
     * F1a. An immediate request cancels its pending upgrades' invoices WHOLE (no $onlyFor): an upgrade invoice that
     * also bills another service's renewal loses that renewal too.
     */
    public function test_a_request_keeps_another_services_renewal_on_its_upgrade_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->emptyInvoice();
        $this->upgrade($a, $invoice);
        $this->renewal($invoice, $b);

        $this->request($a);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, "B's renewal was cancelled with A's upgrade");
        $this->assertTrue($this->bills($invoice, $b));
    }

    /**
     * F1b. The cron's upgrade expiry cancels the upgrade's invoice WHOLE: B's renewal on it is cancelled, with a credit
     * note saying «the upgrade was not paid».
     */
    public function test_an_expired_upgrade_keeps_another_services_renewal_on_its_invoice(): void
    {
        Queue::fake();
        $a = $this->service(['expires_at' => '2026-10-19']); // past due by a day: not suspended yet
        $b = $this->service();
        $invoice = $this->emptyInvoice();
        $this->upgrade($a, $invoice);
        $this->renewal($invoice, $b);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, "B's renewal was cancelled because A's upgrade expired");
        $this->assertTrue($this->bills($invoice, $b));
    }

    /**
     * F2. orders_cancelled cancels the order invoice WHOLE on the assumption that every other line is the same unpaid
     * order. An order invoice whose other service staff already activated loses that service's charge: it runs free.
     */
    public function test_an_unpaid_order_keeps_the_charge_of_a_service_already_active(): void
    {
        Queue::fake();
        $a = $this->service(['status' => 'pending', 'expires_at' => null]);
        $a->forceFill(['created_at' => now()->subDays(10)])->save();
        $b = $this->service(['expires_at' => '2026-11-30']); // same order, activated by staff before payment
        $invoice = $this->invoice([$a, $b]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $a->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $b->fresh()->status);
        $this->assertNotSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, "the active service's charge was cancelled");
        $this->assertTrue($this->bills($invoice->fresh(), $b));
    }

    /**
     * F3. The WHMCS importer maps Domain/Addon/Upgrade/Setup/Promo/LateFee lines to reference_type NULL but keeps
     * relid in reference_id. CancelOpenInvoices treats every such line as «loose, goes with the invoice»: a WHMCS
     * invoice with one hosting and a domain renewal is cancelled whole when the hosting is cancelled, and the domain
     * renewal's charge is lost.
     */
    public function test_cancelling_a_hosting_keeps_a_whmcs_domain_line_on_its_invoice(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $invoice = $this->invoice([$a]);
        // As ImportFromWhmcs::importInvoiceItems writes a tblinvoiceitems row of type 'Domain' (relid = the domain id).
        $domain = $invoice->items()->create(['reference_type' => null, 'reference_id' => 777,
            'description' => 'Domain Renewal - example.test - 1 Year/s', 'quantity' => 1, 'price' => 15]);

        $this->request($a);

        $this->assertNotSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'the domain renewal was cancelled with the hosting');
        $this->assertNotNull($domain->fresh());
    }

    /**
     * F4. On a line-by-line removal giveBack() returns ALL credit paid on the invoice, not only what the removed
     * lines no longer need: the remaining service's part, already paid with credit, is unpaid again. For a customer
     * with automatic credit use off nothing pays it back; their remaining service drifts to suspension.
     */
    public function test_a_partial_removal_returns_only_the_credit_the_invoice_no_longer_needs(): void
    {
        Queue::fake([TerminateJob::class]);
        $this->user->client()->forceFill(['credit_auto_apply' => false])->save();
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 15]);
        (new RecordPaymentService)->payWithCredit($invoice); // the customer applied their credit by hand

        $this->request($a);

        $this->assertSame([$b->id], $invoice->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEqualsWithDelta(5.0, $this->credit(), 0.001, "only the 5.00 B's 10.00 does not need comes back");
        $this->assertLessThanOrEqual(0.0, $invoice->fresh()->remaining, "B's part stays paid");
    }

    /**
     * F5. Only pending invoices are passed (and cancel() returns early on anything else): a DRAFT invoice (immutable
     * invoices on, a staff order) that bills the cancelled service stays as it is. Finalised later, it bills a
     * cancelled service.
     */
    public function test_a_request_takes_the_service_off_a_draft_invoice_too(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $draft = $this->emptyInvoice(Invoice::STATUS_DRAFT);
        $this->renewal($draft, $a);

        $this->request($a);

        $draft->refresh();
        $this->assertTrue($draft->status === Invoice::STATUS_CANCELLED || !$this->bills($draft, $a),
            'a draft still bills the cancelled service');
    }

    /**
     * F6. The cron reprices a pending upgrade line without looking at what is paid on it: credit applied by hand above
     * the new price leaves the invoice pending with a negative balance. Nothing marks it paid (the transaction
     * listener only runs on a new transaction, a payment must be positive), so the upgrade the customer paid is never
     * applied, and at expiry it is cancelled and the credit handed back.
     */
    public function test_a_repriced_upgrade_never_owes_less_than_nothing_while_open(): void
    {
        Queue::fake();
        $a = $this->service();
        $invoice = $this->emptyInvoice();
        $upgrade = $this->upgrade($a, $invoice, 20.00); // the same-price product: calculatePrice() comes to 0.00
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 15]);
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice->refresh();
        $this->assertFalse($invoice->status === Invoice::STATUS_UNPAID && $invoice->remaining < 0,
            "pending with remaining {$invoice->remaining}: paid in full, the upgrade is stuck");
        // Fixed by not repricing an invoice with payments: the customer owes the 5.00 left of the price they started paying.
        $this->assertEqualsWithDelta(5.0, $invoice->remaining, 0.001);
        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status);
    }

    // ---------------------------------------------------------------- guards (pass on the PR head)

    public function test_holds_a_processing_payment_settling_after_a_partial_removal_pays_the_rest_and_credits_the_excess(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service(['expires_at' => '2026-10-25']);
        $b = $this->service(['expires_at' => '2026-10-25']);
        $invoice = $this->invoice([$a, $b]);
        (new RecordPaymentService)->handle($invoice, null, 20.00, transactionId: 'sepa-adv', status: InvoiceTransactionStatus::Processing);

        $this->request($a);
        $this->assertSame([$b->id], $invoice->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all());

        (new RecordPaymentService)->handle($invoice, null, 20.00, transactionId: 'sepa-adv');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->credit(), 0.001);
        $this->assertSame(Service::STATUS_CANCELLED, $a->fresh()->status);
    }

    public function test_holds_a_service_billed_twice_on_one_invoice_is_cancelled_once(): void
    {
        $a = $this->service();
        $invoice = $this->invoice([$a, $a]);
        $open = $a->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->get(); // has-many-through: twice

        $this->assertCount(2, $open);
        $this->assertSame([], CancelOpenInvoices::handle($open, 'Cancelled.', $a));

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('-20.00', (string) $invoice->adjustmentNotes()->sole()->amount);
    }

    public function test_holds_an_empty_string_reference_counts_as_a_loose_line(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        $invoice->items()->create(['reference_type' => '', 'description' => 'Setup', 'quantity' => 1, 'price' => 3]);

        $left = CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a);

        $this->assertArrayHasKey("invoice_cancelled #{$invoice->id}", $left);
        $this->assertSame(3, $invoice->items()->count());
    }

    public function test_holds_whole_cancel_returns_partial_credit_and_the_note_covers_the_total(): void
    {
        $a = $this->service();
        $invoice = $this->invoice([$a]);
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 4]);
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertEqualsWithDelta(0.0, $this->credit(), 0.001);

        $this->assertSame([], CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a));

        $this->assertEqualsWithDelta(4.0, $this->credit(), 0.001);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('-10.00', (string) $invoice->adjustmentNotes()->sole()->amount);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_holds_quantity_counts_in_what_the_others_come_to(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->emptyInvoice();
        $this->renewal($invoice, $a);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $b->id, 'description' => 'B x3',
            'quantity' => 3, 'price' => 2.50]);

        $this->assertSame([], CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a));

        $this->assertEqualsWithDelta(7.5, $invoice->fresh()->total, 0.001);
    }

    public function test_holds_a_request_then_the_termination_cancel_once(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-09-10']);
        $invoice = $this->invoice([$a]);

        $this->request($a, 'end_of_period');
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->adjustmentNotes()->count());
    }

    public function test_holds_an_invoice_with_no_customer_is_cancelled(): void
    {
        $a = $this->service();
        $invoice = $this->invoice([$a]);
        Invoice::query()->whereKey($invoice->id)->update(['client_id' => null]);

        $this->assertSame([], CancelOpenInvoices::handle([$invoice->fresh()], 'Cancelled.'));

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    public function test_holds_nested_queued_jobs_put_the_audit_setting_back(): void
    {
        $this->assertFalse(config('audit.console'));
        $user = $this->user;

        dispatch(function () use ($user) {
            dispatch(fn () => $user->update(['first_name' => 'Inner']));
            $user->update(['first_name' => 'Outer']); // after the inner job: still audited
        });

        $this->assertSame(2, Audit::query()->where('auditable_type', User::class)->where('auditable_id', $user->id)->where('event', 'updated')->count());
        $this->assertFalse(config('audit.console'));
    }

    public function test_holds_a_failing_queued_job_puts_the_audit_setting_back(): void
    {
        $this->assertFalse(config('audit.console'));

        try {
            dispatch(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse(config('audit.console'));
    }

    public function test_holds_the_cron_keeps_its_own_audit_setting_after_a_job(): void
    {
        config(['audit.console' => true]); // as the cron sets it
        $user = $this->user;

        dispatch(fn () => $user->update(['first_name' => 'Queued']));

        $this->assertTrue(config('audit.console'));
    }
}

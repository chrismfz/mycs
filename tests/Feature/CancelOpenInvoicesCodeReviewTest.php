<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Jobs\Server\TerminateJob;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Code review of PR #67 (MT-2b): line-by-line cancellations (SVC-4). Each test failed on 62de781c; fixed in the next commit.
 */
class CancelOpenInvoicesCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        $this->user = User::factory()->create();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
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

    /** @param  array<int, Service>  $services */
    private function invoice(array $services, ?string $periodStart = null, ?string $periodEnd = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => 10, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
        }

        return $invoice;
    }

    private function request(Service $service, string $type = 'immediate'): ServiceCancellation
    {
        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /** @return array<int, int> */
    private function servicesBilled(Invoice $invoice): array
    {
        return $invoice->items()->orderBy('id')->pluck('reference_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * #1 A staff credit note (goodwill, written while the invoice is open, AdjustmentNoteObserver) is not looked at by
     * the line split: taking A off leaves an open invoice whose total is below zero.
     */
    public function test_taking_lines_off_never_leaves_an_open_invoice_below_zero(): void
    {
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -15, 'description' => 'Goodwill']);

        CancelOpenInvoices::handle([$invoice], 'Cancelled.', $a);

        $invoice = $invoice->fresh();
        $this->assertGreaterThanOrEqual(0.0, $invoice->total, 'an open invoice with a negative total (B 10.00 - note 15.00)');
    }

    /**
     * #2 The customer paid part of a mixed invoice with credit by hand (opted out of auto-apply). Taking A off gives
     * back all of it, also the part that pays B, and nothing puts it back: B is left unpaid and runs to suspension
     * while the customer's own credit payment sits in the account.
     */
    public function test_credit_the_customer_put_on_a_mixed_invoice_still_pays_what_is_left(): void
    {
        Queue::fake([TerminateJob::class]);
        $this->user->client()->forceFill(['credit_auto_apply' => false])->save();
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$a, $b]);
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 15]);
        (new RecordPaymentService)->payWithCredit($invoice); // «pay with credit» on the invoice page

        $this->request($a);
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([$b->id], $this->servicesBilled($invoice));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'B (10.00) was covered by the 15.00 the customer applied');
        $this->assertEqualsWithDelta(5.0, $this->credit(), 0.001);
    }

    /**
     * #3 A line already applied (paid, the service renewed, then the payment deleted by staff: the invoice is open
     * again and the services stay renewed, decision 1) is a period delivered. An end-of-period request takes it off
     * silently: A runs through that period and its debt is gone, with no notice to staff. Before the PR this mixed
     * invoice was left for staff.
     */
    public function test_a_request_does_not_erase_a_period_already_delivered(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service(['expires_at' => '2026-10-25']);
        $b = $this->service(['expires_at' => '2026-10-25']);
        $invoice = $this->invoice([$a, $b], '2026-10-25', '2026-11-25');
        (new RecordPaymentService)->handle($invoice, null, 20.00, transactionId: 'bank-bounced');
        $this->assertSame('2026-11-25', $a->fresh()->expires_at->toDateString());
        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->sole()); // the transfer bounced
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->request($a, 'end_of_period');

        $this->assertSame('2026-11-25', $a->fresh()->expires_at->toDateString(), 'A still runs through the delivered period');
        $this->assertContains($a->id, $this->servicesBilled($invoice), 'the delivered period of A is still owed (or left for staff)');
    }

    /**
     * #4 An immediate request cancels the service's pending upgrades' invoices whole (no $onlyFor). An upgrade line
     * staff put on an invoice that also renews another service (InvoiceResource lets staff add ServiceUpgrade lines)
     * takes B's renewal down with it: the case SVC-4 is meant to fix.
     */
    public function test_an_upgrade_line_takes_only_itself_off_an_invoice_that_renews_another_service(): void
    {
        Queue::fake([TerminateJob::class]);
        $a = $this->service();
        $b = $this->service();
        $invoice = $this->invoice([$b]);
        $bigger = $this->createProduct();
        $upgrade = ServiceUpgrade::create([
            'service_id' => $a->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        $this->request($a);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, "B's renewal was cancelled with A's upgrade");
        $this->assertSame([$b->id], $this->servicesBilled($invoice));
    }
}

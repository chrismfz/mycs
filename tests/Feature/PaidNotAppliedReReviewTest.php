<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Events\Invoice\Paid;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Second adversarial pass on PR #68 (BIL-9) after the review fixes: keepsItsMoney, the Paid event fired by the cron,
 * the suspension/billing exclusion, the swallowed after-commit failure.
 */
class PaidNotAppliedReReviewTest extends TestCase
{
    use RefreshDatabase;

    private bool $failRenewal = false;

    private ?int $failOnly = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false]);
        Service::updating(function (Service $service) {
            if ($this->failRenewal && $service->isDirty('expires_at') && ($this->failOnly === null || $this->failOnly === $service->id)) {
                throw new RuntimeException('the server went away');
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-15',
        ]);
    }

    /** @param  Service[]  $services */
    private function renewal(array $services): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $services[0]->user_id, 'status' => 'unpaid', 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'created_at' => '2026-10-08']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        }

        return $invoice;
    }

    private function pay(Invoice $invoice, float $amount, bool $failing = true): void
    {
        $this->failRenewal = $failing;
        (new RecordPaymentService)->handle($invoice->fresh(), null, $amount, transactionId: 'tx-' . $invoice->id . '-' . uniqid());
        $this->failRenewal = false;
    }

    private function cron(): ?Throwable
    {
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // ---------------------------------------------------------------- findings

    /**
     * R1. The exclusion is per invoice, not per line: a service whose own line WAS applied, on an invoice where another
     * service's line keeps failing, is never billed again nor suspended once its paid period ends. Free hosting for
     * the healthy service for as long as the sibling line fails; the daily notice names only the invoice.
     */
    public function test_r1_a_healthy_service_on_an_invoice_with_a_failing_sibling_line_is_still_billed_and_suspended(): void
    {
        $user = User::factory()->create();
        $broken = $this->service($user);
        $healthy = $this->service($user);
        $invoice = $this->renewal([$broken, $healthy]);
        $this->failOnly = $broken->id;
        $this->pay($invoice, 20);
        $paidUntil = $healthy->fresh()->expires_at;
        $this->assertTrue($paidUntil->greaterThan(Carbon::parse('2026-10-15')), 'precondition: healthy renewed');

        // The healthy service's period ends; the broken line still fails every day.
        Carbon::setTestNow($paidUntil->copy()->addDays(10)->setTime(9, 0));
        $this->failRenewal = true;
        $this->cron();
        $this->failRenewal = false;

        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'precondition: still pending');
        $billed = Invoice::query()->where('user_id', $user->id)->where('status', 'unpaid')
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $healthy->id))->exists();
        $this->assertTrue($billed || $healthy->fresh()->status === Service::STATUS_SUSPENDED,
            'healthy service 10 days past its paid period: neither billed nor suspended');
    }

    /**
     * R2. (Judgement) The failing service itself: as long as its line fails, it is never billed again nor suspended, for
     * any number of later periods nobody paid. Only a daily «needs staff attention» notice. No bound (e.g. only the
     * period the invoice pays is protected, or the protection ends after N days and the notice becomes an error).
     */
    public function test_r2_the_protection_of_a_line_that_never_applies_is_bounded(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->pay($invoice, 10);

        Carbon::setTestNow('2027-01-20 09:00:00'); // three months later, nothing paid since
        $this->failRenewal = true;
        $this->cron();
        $this->failRenewal = false;

        $this->assertNotNull($invoice->fresh()->apply_pending_since);
        $billed = Invoice::query()->where('user_id', $service->user_id)->where('status', 'unpaid')->exists();
        $this->assertTrue($billed || $service->fresh()->status !== Service::STATUS_ACTIVE,
            'three months of unpaid periods: still active and never billed');
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_a_discount_note_keeps_its_money(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -2, 'description' => 'Goodwill']);
        $this->pay($invoice, 8);
        $this->assertSame('paid', $invoice->fresh()->status);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertNull($invoice->fresh()->apply_pending_since);
        $this->assertTrue($service->fresh()->expires_at->greaterThan(Carbon::parse('2026-10-15')), 'renewed');
    }

    public function test_holds_an_overpayment_credited_keeps_its_money(): void
    {
        config(['settings.credits_enabled' => true]);
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->pay($invoice, 25);
        $this->assertSame('paid', $invoice->fresh()->status);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertTrue($service->fresh()->expires_at->greaterThan(Carbon::parse('2026-10-15')), 'renewed');
    }

    public function test_holds_a_credit_paid_invoice_keeps_its_money(): void
    {
        config(['settings.credits_enabled' => true]);
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        (new CreditLedger)->add($service->user_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'seed');
        $this->failRenewal = true;
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->failRenewal = false;
        $this->assertNotNull($invoice->fresh()->apply_pending_since);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertTrue($service->fresh()->expires_at->greaterThan(Carbon::parse('2026-10-15')), 'renewed');
    }

    public function test_holds_a_partial_refund_is_not_applied_and_staff_are_told(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->pay($invoice, 10);
        ExtensionHelper::refundLocally($invoice->transactions()->firstOrFail(), 3);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
        $this->assertNull($invoice->fresh()->apply_pending_since);
    }

    public function test_holds_paid_fires_once_across_payment_and_runs(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        Event::fake([Paid::class]);
        $this->pay($invoice, 10);
        Event::assertNotDispatched(Paid::class);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();
        $this->cron();
        Carbon::setTestNow('2026-10-21 03:30:00');
        $this->cron();

        Event::assertDispatchedTimes(Paid::class, 1);
    }

    public function test_holds_a_payment_applied_at_once_fires_paid_once_and_the_cron_adds_none(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        Event::fake([Paid::class]);
        $this->pay($invoice, 10, failing: false);

        Carbon::setTestNow('2026-10-20 03:30:00');
        $this->cron();

        Event::assertDispatchedTimes(Paid::class, 1);
    }

    public function test_holds_the_payer_is_not_told_it_failed_and_the_payment_stays(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->pay($invoice, 10); // no exception escapes

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertNotNull($invoice->fresh()->apply_pending_since);
    }

    public function test_holds_leaving_paid_clears_the_marker(): void
    {
        $service = $this->service();
        $invoice = $this->renewal([$service]);
        $this->pay($invoice, 10);
        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->firstOrFail());

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->apply_pending_since);
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-10-10']);
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->cron();
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'unpaid again: suspended as usual');
    }
}

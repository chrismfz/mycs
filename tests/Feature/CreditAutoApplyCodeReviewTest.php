<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent code review of MT-1e (PR #56, decision 5): failing tests for what the review found.
 */
class CreditAutoApplyCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_cancel' => 7]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['user_id' => $this->user->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10], $attributes));
    }

    private function invoice(float $price, string $due, ?Service $service = null, ?string $start = null, ?string $end = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING,
            'currency_code' => 'USD', 'due_at' => now()->modify($due)]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id,
            'period_start' => $start, 'period_end' => $end], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function giveCredit(int $cents): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    /**
     * P2. «Oldest due first» and «not suspended while credit sits unused»: the cron's invoices_created step spends the
     * credit on the renewal it just made (due in 2 days) before the credit_applied sweep reaches the overdue invoice,
     * so the overdue service is suspended in the same run.
     */
    public function test_the_cron_pays_the_overdue_invoice_before_a_new_renewal(): void
    {
        $overdueService = $this->service(['expires_at' => now()->subDays(5)]);
        $overdue = $this->invoice(10, '-5 days', $overdueService, now()->subDays(5)->toDateString(), now()->addDays(25)->toDateString());
        $this->service(['expires_at' => now()->addDays(2)]); // renewal billed by this run
        $this->giveCredit(1000);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $overdue->fresh()->status, 'the credit paid the newer renewal first');
        $this->assertSame('active', $overdueService->fresh()->status, 'suspended although the customer had the credit');
    }

    /**
     * P2 regression. A gateway subscription sale that finds no open invoice lands on the service's latest (paid) invoice
     * and becomes credit (PayPal::recordSubscriptionSale). On main the next renewal the cron makes is paid from it
     * (payWithCredit onlyIfCovers); now every invoice of a subscription service is excluded, so the renewal stays
     * open and the service is suspended although the customer paid.
     */
    public function test_a_subscription_sale_that_became_credit_still_pays_the_services_next_renewal(): void
    {
        $service = $this->service(['expires_at' => now()->addDays(2), 'subscription_id' => 'I-SUBREVIEW']);
        $previous = $this->invoice(10, '-28 days', $service, now()->subDays(28)->toDateString(), now()->addDays(2)->toDateString());
        ExtensionHelper::addPayment($previous->id, 'Stripe', 10.00, null, 'SALE-1', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $previous->fresh()->status);
        // The next sale arrives before the cron made the renewal invoice: it becomes credit.
        ExtensionHelper::addPayment($previous->id, 'Stripe', 10.00, null, 'SALE-2', currency: 'USD');
        $this->assertSame('10.00', $this->balance());

        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewal = Invoice::query()->where('user_id', $this->user->id)->whereKeyNot($previous->id)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $renewal->status, 'the subscription\'s own sale no longer pays its renewal');
    }

    /**
     * P2. Credit applied on its own, in part, to a new order's invoice is lost when the cron cancels the order
     * (orders_cancelled: a mass update, no credit back). Before MT-1e credit only paid an invoice in full, so it never
     * sat on an invoice that is then cancelled.
     */
    public function test_partial_credit_on_an_order_the_cron_cancels_is_not_lost(): void
    {
        $order = $this->service(['status' => 'pending', 'expires_at' => null]);
        $invoice = $this->invoice(10, '+0 days', $order);
        $this->giveCredit(500);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertEquals(5.0, (float) $invoice->transactions()->where('is_credit_transaction', true)->sum('amount'), 'the sweep paid part of the order');

        Service::query()->whereKey($order->id)->update(['created_at' => now()->subDays(8)]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame('5.00', $this->balance(), 'the credit auto-applied to the cancelled order is gone');
    }

    /**
     * P3. Staff take credit back off an invoice (delete the credit payment, decision 1); the next daily sweep puts it
     * straight back on the same invoice, undoing the correction (§«Αρχή»).
     */
    public function test_the_sweep_does_not_undo_staff_removing_credit_from_an_invoice(): void
    {
        $invoice = $this->invoice(100, '+3 days');
        $this->giveCredit(3000);
        $payment = (new CreditAutoApply)->toInvoice($invoice);

        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame('30.00', $this->balance());

        (new CreditAutoApply)->sweep();

        $this->assertSame(0, $invoice->transactions()->where('is_credit_transaction', true)->count(), 'the sweep re-applied what staff removed');
    }

    /** Nit. The cron prints the step by its label: admin.cronjob.credit_applied is missing in lang/en and lang/el. */
    public function test_the_new_cron_step_has_a_label(): void
    {
        foreach (['en', 'el'] as $locale) {
            $this->assertNotSame('admin.cronjob.credit_applied', __('admin.cronjob.credit_applied', [], $locale), $locale);
        }
    }
}

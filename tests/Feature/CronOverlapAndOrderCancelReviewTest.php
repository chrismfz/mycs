<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #75 (BIL-12 part). Each test fails on the PR head and proves a finding.
 */
class CronOverlapAndOrderCancelReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        $this->user = User::factory()->create();
        config(['settings.cronjob_order_cancel' => 7]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cron(): void
    {
        try {
            $this->artisan('app:cron-job')->run();
        } catch (\Throwable) {
        }
    }

    private function pendingService(): Service
    {
        $product = $this->createProduct(['stock' => 5]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => null,
            'created_at' => '2026-10-01',
        ]);
    }

    private function orderInvoice(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-01']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Order', 'quantity' => 1, 'price' => 10]);
        }

        return $invoice;
    }

    /**
     * F1 (high, regression): one order with two services (a domain and its hosting) on one invoice. The first service
     * cancels the whole invoice (no other service running); the second is then re-read under its lock with
     * unpaidOrders(), which requires a PENDING invoice: none left, so it is skipped, and on every later run it looks
     * like a «pending service staff created without an invoice». It stays pending forever, its stock never returned.
     * Before the PR both were cancelled.
     */
    public function test_every_service_of_a_multi_service_unpaid_order_is_cancelled(): void
    {
        $domain = $this->pendingService();
        $hosting = $this->pendingService();
        $invoice = $this->orderInvoice($domain, $hosting);

        $this->cron();
        $this->cron(); // and not on a later run either

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $domain->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $hosting->fresh()->status, 'second service of the order left pending');
        $this->assertSame(6, (int) $hosting->product->fresh()->stock);
    }

    /**
     * F2 (medium): the cron's own credit_applied step (just before orders_cancelled) puts any credit balance onto the
     * abandoned order's invoice, in part. A credit transaction is Succeeded, so unpaidOrders() then excludes the order
     * for good: the order is never cancelled and the credit stays locked on an invoice nobody will pay. CreditAutoApply
     * relies on the cron cancelling it (giveBack: «partial credit is never lost»). Credit is the customer's own money
     * that CancelOpenInvoices gives back, not a payment staff must decide on.
     */
    public function test_partial_credit_swept_by_the_cron_does_not_keep_an_abandoned_order_alive(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $service = $this->pendingService();
        $invoice = $this->orderInvoice($service);
        (new CreditLedger)->add($this->user->id, 'USD', 300, MoneyTrail::CAUSE_OPENING_BALANCE, MoneyTrail::SOURCE_SYSTEM);

        $this->cron();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'abandoned order kept alive by the credit sweep');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(3.00, (float) Credit::query()->where('client_id', $this->user->client()->id)->value('amount'), 0.001, 'credit back');
    }

    /**
     * F3 (low): an order part paid is «left to staff», but nothing tells staff: no notice, no failure. It stays
     * pending with the money on it indefinitely.
     */
    public function test_staff_are_told_about_a_part_paid_order_left_pending(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $service = $this->pendingService();
        $invoice = $this->orderInvoice($service);
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 4, 'status' => InvoiceTransactionStatus::Succeeded->value,
            'transaction_id' => 'bank-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->cron();

        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $notice = EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->latest('id')->first();
        $this->assertNotNull($notice, 'staff are not told about the part-paid order');
        $this->assertStringContainsString("#{$service->id}", $notice->body);
    }
}

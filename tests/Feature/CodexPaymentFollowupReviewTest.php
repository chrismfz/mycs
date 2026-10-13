<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * Follow-up review of PR 2a (Codex, docs/payments.md): PayPal subscription allocation interrupted or picking the
 * wrong cycle, and a reopened cancelled invoice. Kept as written by the reviewer.
 */
class CodexPaymentFollowupReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $start, string $end, string $status = 'pending', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_PENDING : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    public function test_subscription_sale_retries_finish_interrupted_allocation(): void
    {
        $a = $this->service(['subscription_id' => 'I-RETRY']);
        $b = $this->service(['subscription_id' => 'I-RETRY']);
        $invoiceA = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $invoiceB = $this->invoiceFor($b, '2026-10-15', '2026-11-15');
        $resource = ['id' => 'SALE-RETRY', 'billing_agreement_id' => 'I-RETRY', 'amount' => ['total' => '20.00', 'currency' => 'USD']];
        $failOnce = true;
        InvoiceTransaction::creating(function ($txn) use (&$failOnce, $invoiceB) {
            if ($failOnce && $txn->invoice_id === $invoiceB->id && $txn->is_credit_transaction) {
                $failOnce = false;
                throw new \RuntimeException('Simulated interruption during sibling allocation');
            }
        });
        $paypal = new PayPal([]);
        try {
            $paypal->recordSubscriptionSale($resource);
            $this->fail('The interruption was not injected');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated interruption during sibling allocation', $e->getMessage());
        }
        $paypal->recordSubscriptionSale($resource);
        $this->assertSame('paid', $invoiceB->fresh()->status, 'A successful retry must complete the subscription allocation');
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(1, InvoiceTransaction::where('transaction_id', 'SALE-RETRY')->count());
    }

    public function test_subscription_sale_pays_oldest_invoice_of_first_service(): void
    {
        $a = $this->service(['subscription_id' => 'I-OLDEST']);
        $b = $this->service(['subscription_id' => 'I-OLDEST']);
        $octA = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $octB = $this->invoiceFor($b, '2026-10-15', '2026-11-15');
        Carbon::setTestNow('2026-10-13 10:00:00');
        $novA = $this->invoiceFor($a, '2026-11-15', '2026-12-15');
        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-OLDEST', 'billing_agreement_id' => 'I-OLDEST', 'amount' => ['total' => '20.00', 'currency' => 'USD']]);
        $this->assertSame('paid', $octA->fresh()->status, 'The first service must not skip its oldest unpaid cycle');
        $this->assertSame('pending', $novA->fresh()->status, 'First service October=' . $octA->fresh()->status . ', November=' . $novA->fresh()->status . '; second service October=' . $octB->fresh()->status);
        $this->assertSame('paid', $octB->fresh()->status);
    }

    public function test_reopening_cancelled_invoice_restores_its_payable_balance(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);
        $invoice->fresh()->update(['status' => 'pending']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10, transactionId: 'REOPENED', currency: 'USD');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame('0.00', $this->credit(), 'Service has renewed, but the same payment remains spendable credit');
    }
}

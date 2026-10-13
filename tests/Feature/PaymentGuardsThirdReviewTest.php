<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/**
 * PR 2a, third review (docs/payments.md): reopening a cancelled invoice, staff transactions (gateway, deletion)
 * and the PayPal subscription allocation when a step fails.
 */
class PaymentGuardsThirdReviewTest extends TestCase
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
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $start = '2026-10-15', string $end = '2026-11-15'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => $start, 'period_end' => $end,
        ]);

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function expires(Service $service): string
    {
        return Carbon::parse($service->fresh()->expires_at)->toDateString();
    }

    private function transactionsManager(Invoice $invoice): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        return Livewire::actingAs($admin)
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class]);
    }

    private function sale(string $id, string $total): array
    {
        return ['id' => $id, 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => $total, 'currency' => 'USD']];
    }

    // ------------------------------------------------------------------ reopening a cancelled invoice

    /** Cancelled from the admin (credit note), reopened: a declined card neither pays it nor renews. */
    public function test_a_failed_transaction_never_pays_a_reopened_invoice(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        $invoice->update(['status' => 'cancelled']);
        $invoice->fresh()->update(['status' => 'unpaid']);

        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10.00, null, 'pi_declined', currency: 'usd');

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    /** Reopened and paid in full: paid and renewed once, and none of the money becomes credit. */
    public function test_a_reopened_invoice_is_payable_again(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        $invoice->update(['status' => 'cancelled']);
        $invoice->fresh()->update(['status' => 'unpaid']);
        $this->assertEquals(10.00, $invoice->fresh()->remaining);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_ok', currency: 'usd');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('0.00', $this->credit());
    }

    /** Reopening voids only the cancellation's note (a refund note stays); cancelling again writes a new one. */
    public function test_reopening_voids_only_the_cancellation_note(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        // MT-1c: a refund writes its note on a settled invoice only (on an open one it is still to pay in full): the
        // invoice is paid, refunded in part, then set back to pending by staff before it is cancelled.
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_part', currency: 'usd');
        ExtensionHelper::refundLocally($payment->fresh(), 1.00);
        $invoice->fresh()->update(['status' => 'unpaid']);
        // Since 2c an invoice holding money is not cancelled: the 9.00 still on it goes back first.
        ExtensionHelper::refundLocally($payment->fresh(), 9.00);
        $invoice->fresh()->update(['status' => 'cancelled']);
        $invoice->fresh()->update(['status' => 'unpaid']);

        $notes = $invoice->fresh()->adjustmentNotes;
        $this->assertSame(AdjustmentNoteStatus::Voided, $notes->firstWhere('is_cancellation', true)->status);
        $this->assertSame(AdjustmentNoteStatus::Active, $notes->firstWhere('is_cancellation', false)->status, 'the refund note stays');

        $invoice->fresh()->update(['status' => 'cancelled']);
        $this->assertSame(1, $invoice->fresh()->adjustmentNotes()->where('is_cancellation', true)->where('status', 'active')->count());
    }

    // ------------------------------------------------------------------ staff transactions

    /** Staff re-enter a payment the webhook already recorded, gateway selected: nothing is added. */
    public function test_a_staff_transaction_keeps_its_gateway_and_is_deduplicated(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        $stripe = Gateway::query()->where('extension', 'Stripe')->first();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_same', currency: 'usd');

        $this->transactionsManager($invoice)
            ->callTableAction('create', data: ['gateway.name' => $stripe->id, 'amount' => '10.00', 'transaction_id' => 'pi_same'])
            ->assertNotified(__('Payment already recorded'));

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
    }

    /** A new staff transaction with a gateway is stored with that gateway. */
    public function test_a_staff_transaction_is_recorded_with_its_gateway(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $stripe = Gateway::query()->where('extension', 'Stripe')->first();

        $this->transactionsManager($invoice)
            ->callTableAction('create', data: ['gateway.name' => $stripe->id, 'amount' => '10.00', 'transaction_id' => 'pi_wire'])
            ->assertHasNoTableActionErrors();

        $this->assertSame($stripe->id, InvoiceTransaction::query()->sole()->gateway_id);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** Staff typo (100 instead of 10) then delete: the 90 that became credit is taken back. */
    public function test_deleting_a_transaction_takes_back_its_credit(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $manager = $this->transactionsManager($invoice)
            ->callTableAction('create', data: ['amount' => '100.00', 'transaction_id' => 'wire-typo']);
        $this->assertSame('90.00', $this->credit());

        $manager->callTableAction('delete', InvoiceTransaction::query()->sole());

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
    }

    /** The credit was already spent: the deletion is refused and nothing changes. */
    public function test_deleting_a_transaction_whose_credit_was_spent_is_refused(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 20.00, null, 'pi_over', currency: 'usd'); // 10 -> credit
        $other = $this->invoiceFor($this->service());
        (new RecordPaymentService)->payWithCredit($other);
        $this->assertSame('0.00', $this->credit());

        $this->transactionsManager($invoice)
            ->callTableAction('delete', InvoiceTransaction::query()->where('transaction_id', 'pi_over')->sole())
            ->assertNotified(__('Transaction not deleted'));

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'pi_over')->count());
        $this->assertSame('0.00', $this->credit());
    }

    /** Deleting a payment made with credit gives the credit back. */
    public function test_deleting_a_credit_payment_gives_the_credit_back(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 10.00]);
        $paid = (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertSame('0.00', $this->credit());

        (new RecordPaymentService)->deleteTransaction($paid);

        $this->assertSame('10.00', $this->credit());
    }

    /** A credit payment above the balance (its excess went straight back to credit): deleting it returns the rest only. */
    public function test_deleting_an_overpaid_credit_payment_returns_what_the_invoice_took(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 3.00]); // what is left after spending 15
        $paid = ExtensionHelper::addPayment($invoice->id, null, 15.00, isCreditTransaction: true); // 5 back to credit
        $this->assertSame('8.00', $this->credit());

        (new RecordPaymentService)->deleteTransaction($paid);

        $this->assertSame('18.00', $this->credit(), 'the 15 spent come back once: 3 + 5 already back + 10');
    }

    // ------------------------------------------------------------------ fourth round (independent review, Codex)

    /** Staff recorded the payment by hand (no gateway), then the gateway's webhook arrives: one payment. */
    public function test_a_webhook_after_a_manual_entry_of_the_same_id_is_a_replay(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'pi_manual_first');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_manual_first', currency: 'usd');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
    }

    /** Credit bought with a card and already spent: the purchase cannot be refunded. */
    public function test_a_spent_credit_purchase_cannot_be_refunded(): void
    {
        $purchase = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $purchase->items()->create(['reference_id' => null, 'reference_type' => Credit::class, 'description' => 'Credit', 'quantity' => 1, 'price' => 10.00]);
        $paid = ExtensionHelper::addPayment($purchase->id, 'Stripe', 10.00, null, 'pi_buy', currency: 'usd');
        $this->assertSame('10.00', $this->credit());
        (new RecordPaymentService)->payWithCredit($this->invoiceFor($this->service()));
        $this->assertSame('0.00', $this->credit());

        try {
            ExtensionHelper::refundLocally($paid->fresh(), 10.00);
            $this->fail('a refund of credit already spent was accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->assertEquals(0.0, (float) $paid->fresh()->refunded_amount);
    }

    /** An invoice buying credit together with a service: which part is refunded is not known, so it is refused. */
    public function test_a_mixed_credit_purchase_is_refunded_by_hand_only(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $invoice->items()->create(['reference_id' => null, 'reference_type' => Credit::class, 'description' => 'Credit', 'quantity' => 1, 'price' => 5.00]);
        $paid = ExtensionHelper::addPayment($invoice->id, 'Stripe', 15.00, null, 'pi_mixed', currency: 'usd');

        $this->expectException(DisplayException::class); // a paid mixed credit purchase: refused, with a translated message
        ExtensionHelper::refundLocally($paid->fresh(), 5.00);
    }

    /** The admin API refuses to delete an invoice with payments (422), and the payment and its credit stay. */
    public function test_the_api_does_not_delete_an_invoice_with_payments(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 15.00, null, 'pi_api', currency: 'usd'); // 5 -> credit
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create([
            'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'user_id' => $owner->id, 'permissions' => ['admin.invoices.delete'],
        ]);

        $this->deleteJson("/api/v1/admin/invoices/{$invoice->id}", [], ['Authorization' => 'Bearer ' . $token])->assertStatus(422);

        $this->assertNotNull($invoice->fresh());
        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('5.00', $this->credit());
    }

    // ------------------------------------------------------------------ PayPal subscription allocation

    /** The first service's renewal fails after the sale's commit: the other service is still paid (and the retry adds nothing). */
    public function test_a_renewal_failure_of_the_first_service_does_not_strand_the_others(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $invoiceA = $this->invoiceFor($a);
        $invoiceB = $this->invoiceFor($b);
        $failOnce = true;
        Service::updating(function (Service $service) use (&$failOnce, $a) {
            if ($failOnce && $service->id === $a->id && $service->isDirty('expires_at')) {
                $failOnce = false;
                throw new \RuntimeException('renewal of A failed');
            }
        });

        // The sale is recorded; A's renewal is reported and left for the cron (BIL-9), not thrown at PayPal: its retry
        // is a replay that adds nothing, so it never renewed A anyway.
        (new PayPal([]))->recordSubscriptionSale($this->sale('SALE-1', '20.00'));
        $this->assertNotNull($invoiceA->fresh()->apply_pending_since, 'A is left for the cron to apply');
        $this->assertNull($invoiceB->fresh()->apply_pending_since);
        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('paid', $invoiceB->fresh()->status);
        $this->assertSame('2026-11-15', $this->expires($b));

        (new PayPal([]))->recordSubscriptionSale($this->sale('SALE-1', '20.00')); // PayPal's retry
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-1')->count());
        $this->assertSame(1, InvoiceTransaction::query()->whereNotNull('source_transaction_id')->count());
        $this->assertSame('0.00', $this->credit());
    }

    /** A replay never pays a second cycle of a service the sale already paid, even with budget left. */
    public function test_a_replayed_sale_pays_one_cycle_per_service(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $this->invoiceFor($a);
        $octoberB = $this->invoiceFor($b);
        Carbon::setTestNow('2026-10-13 10:00:00');
        $novemberB = $this->invoiceFor($b, '2026-11-15', '2026-12-15');

        (new PayPal([]))->recordSubscriptionSale($this->sale('SALE-30', '30.00')); // 10 more than the two services
        (new PayPal([]))->recordSubscriptionSale($this->sale('SALE-30', '30.00'));

        $this->assertSame('paid', $octoberB->fresh()->status);
        $this->assertSame('unpaid', $novemberB->fresh()->status);
        $this->assertSame('10.00', $this->credit());
    }

    /** The allocation spends only what the sale left, never more, however often it runs. */
    public function test_an_allocation_never_spends_more_than_the_sale_left(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $invoiceA = $this->invoiceFor($a);
        $invoiceB = $this->invoiceFor($b);
        $this->user->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]);

        (new PayPal([]))->recordSubscriptionSale($this->sale('SALE-15', '15.00'));
        $sale = InvoiceTransaction::query()->where('transaction_id', 'SALE-15')->sole();
        (new RecordPaymentService)->allocateFromTransaction($sale, $invoiceB);

        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('unpaid', $invoiceB->fresh()->status);
        $this->assertEquals(5.00, $invoiceB->fresh()->remaining);
        $this->assertSame('50.00', $this->credit(), 'the customer\'s own credit is untouched');
    }
}

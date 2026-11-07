<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-refund-chargeback (sweep 2, 2026-11-07). Each test fails on 55e34ff9 for the
 * reason in its docblock.
 */
class Sweep2FRefundChargebackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $this->user = User::factory()->create();
    }

    private function openInvoice(string $type, float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addDays(3)]);
        $invoice->items()->create($type === 'credit'
            ? ['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]
            : ['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function buyCredit(string $intent, float $price = 100): Invoice
    {
        $invoice = $this->openInvoice('credit', $price);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', $price, transactionId: $intent, currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice->fresh();
    }

    private function spend(float $amount): void
    {
        $other = $this->openInvoice('line', $amount);
        (new RecordPaymentService)->payWithCredit($other);
        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /**
     * F-REFUND-CHARGEBACK-1. Credit purchase 100, 60 spent, chargeback 100: 40 is taken from the credit. The dispute is
     * won and the money comes back: the invoice is paid again, but the 40 never returns (applied_at stayed set, the
     * chargeback's CreditOut is not on the GatewayRefund row, so nothing reverses it).
     */
    public function test_won_dispute_gives_back_the_credit_a_chargeback_took_from_a_spent_purchase(): void
    {
        $invoice = $this->buyCredit('pi_spent');
        $this->spend(60);
        $this->assertEqualsWithDelta(40, $this->credit(), 0.001);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_spent', 'dp_spent', 100, 'usd');
        $this->assertEqualsWithDelta(0, $this->credit(), 0.001);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_spent', 'dp_spent', 100, 'usd');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(40, $this->credit(), 0.001, 'dispute won: the customer is back where they were before the chargeback');
    }

    /**
     * F-REFUND-CHARGEBACK-1 (b). Partial dispute: 50 of a 100 credit purchase, credit untouched. take = record = 50 but
     * bought = 100, so applied_at stays; winning the dispute pays the invoice again and gives nothing back.
     */
    public function test_won_partial_dispute_gives_back_the_credit_it_took(): void
    {
        $invoice = $this->buyCredit('pi_part');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_part', 'dp_part', 50, 'usd');
        $this->assertEqualsWithDelta(50, $this->credit(), 0.001);

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_part', 'dp_part', 50, 'usd');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001, 'dispute won: the 50 taken comes back');
    }

    /**
     * F-REFUND-CHARGEBACK-2. A credit purchase with a 10 debit note (total 110, the credit line gives 100), plus 50 of
     * unrelated credit. A chargeback of 110 takes 110 from the credit (min(record, balance)), not the 100 the purchase
     * gave: 10 of the unrelated credit goes too, while the invoice is owed again in full. refundedAtGateway caps this
     * with boughtCreditLeftCents; chargebackAtGateway does not.
     */
    public function test_chargeback_takes_no_more_credit_than_the_purchase_gave(): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_SYSTEM, note: 'Earlier goodwill');
        $invoice = $this->openInvoice('credit', 100);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'status' => AdjustmentNoteStatus::Active->value,
            'amount' => 10, 'description' => 'Fee']);
        $this->assertEqualsWithDelta(110, (float) $invoice->fresh()->remaining, 0.001);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 110, transactionId: 'pi_fee', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(150, $this->credit(), 0.001);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_fee', 'dp_fee', 110, 'usd');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(110, (float) $invoice->fresh()->remaining, 0.001);
        $this->assertEqualsWithDelta(50, $this->credit(), 0.001, 'only the 100 the purchase gave is taken back');
    }
}

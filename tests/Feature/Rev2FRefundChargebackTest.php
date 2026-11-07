<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent review of the sweep2 f-refund-chargeback fixes. Each test fails on the fix branch for the reason in its
 * docblock.
 */
class Rev2FRefundChargebackTest extends TestCase
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

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /**
     * REV2-FRC-1. GrantBoughtCreditService::giveBack journals its reversal under CAUSE_PURCHASE, and
     * boughtCreditLeftCents counts refunds only after the last CAUSE_PURCHASE event ($granted). So after a won dispute
     * the refunds made before it are forgotten and the purchase is thought to hold its whole credit again.
     *
     * Credit purchase 100 with a 10 debit note (paid 110), 50 unrelated credit. Refund 30 at the gateway (takes 30, the
     * purchase holds 70). Chargeback 80 (takes the 70). Dispute won: the 70 comes back (credit 120). A refund of the 80
     * left on the payment should take back only the 70 the purchase holds (credit 50); it takes 80 (credit 40), 10 of
     * the unrelated credit, the bug F-REFUND-CHARGEBACK-2 was about.
     */
    public function test_refund_after_a_won_dispute_takes_no_more_than_the_purchase_still_holds(): void
    {
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_SYSTEM, note: 'Earlier goodwill');
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addDays(3)]);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 100]);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'status' => AdjustmentNoteStatus::Active->value,
            'amount' => 10, 'description' => 'Fee']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 110, transactionId: 'pi_rev', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(150, $this->credit(), 0.001);

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_rev', 're_rev_1', 30, 'usd');
        $this->assertEqualsWithDelta(120, $this->credit(), 0.001);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_rev', 'dp_rev', 80, 'usd');
        $this->assertEqualsWithDelta(50, $this->credit(), 0.001, 'the chargeback takes the 70 the purchase holds');

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_rev', 'dp_rev', 80, 'usd');
        $this->assertEqualsWithDelta(120, $this->credit(), 0.001, 'dispute won: the 70 is back');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_rev', 're_rev_2', 80, 'usd');
        $this->assertEqualsWithDelta(50, $this->credit(), 0.001, 'only the 70 the purchase still holds is taken, not 10 of the unrelated credit');
    }
}

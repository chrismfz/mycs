<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Mail\SystemMail;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A gateway refund made in its dashboard of a payment that overpaid its invoice (sweep 9γ, owner 2026-10-30): taken
 * first from the account credit the overpayment left, as much as is still there; only the rest from the invoice. Staff
 * are told. Booked against the invoice, the customer had that money back and kept it as credit too.
 */
class GatewayRefundOfOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    /** An invoice of 100 paid 130 at Stripe: 30 became account credit. */
    private function overpaid(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame([Invoice::STATUS_PAID, 30.0], [$invoice->fresh()->status, $this->credit()], 'precondition: 30 in credit');

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function payment(): InvoiceTransaction
    {
        return InvoiceTransaction::query()->where('transaction_id', 'pi_over')->sole();
    }

    private function assertMoneyCheckClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    public function test_a_refund_of_the_overpayment_takes_it_from_the_credit_not_the_invoice(): void
    {
        $invoice = $this->overpaid();

        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));

        $this->assertSame(0.0, $this->credit(), 'the credit it left is what went back');
        $this->assertSame([Invoice::STATUS_PAID, 0], [$invoice->fresh()->status, $invoice->adjustmentNotes()->count()], 'the invoice untouched');
        $payment = $this->payment();
        $this->assertSame(['30.00', '0.00', 100.0], [$payment->refunded_amount, $payment->credited_amount, (float) $payment->refundable_amount]);
        Mail::assertQueued(SystemMail::class);
        $this->assertSame([MoneyTrail::CAUSE_OVERPAYMENT], MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->pluck('cause')->all(),
            'taken back as the overpayment it was');
        $this->assertMoneyCheckClean();
    }

    public function test_a_refund_of_the_whole_payment_takes_the_credit_then_the_invoice(): void
    {
        $invoice = $this->overpaid();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_all', 130.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame('130.00', $this->payment()->refunded_amount);
        $this->assertMoneyCheckClean();
    }

    public function test_only_the_credit_left_is_taken_the_rest_from_the_invoice(): void
    {
        $invoice = $this->overpaid();
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 2000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'test'); // 20 spent

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30b', 30.00, 'usd');

        $this->assertSame(0.0, $this->credit(), 'the 10 left of it');
        $this->assertSame(-20.0, (float) $invoice->adjustmentNotes()->sum('amount'), 'the other 20 from the invoice');
        $this->assertSame('30.00', $this->payment()->refunded_amount);
    }

    public function test_a_payment_without_an_overpayment_is_refunded_from_its_invoice_as_before(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_exact', currency: 'usd');
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'test'); // other credit

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_exact', 're_part', 30.00, 'usd');

        $this->assertSame(50.0, $this->credit(), 'credit from elsewhere is never taken');
        $this->assertSame(-30.0, (float) $invoice->adjustmentNotes()->sum('amount'));
    }
}

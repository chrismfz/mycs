<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A chargeback of a payment that overpaid its invoice (owner 2026-10-30, #552's rule, «keep it simple»): the credit it
 * left is taken back while the credit holds it, else refunded on the payment and staff's, never a debt; a won dispute
 * gives each part back as it was. A chargeback stops the account's automatic charging.
 */
class ChargebackOfOverpaymentTest extends TestCase
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
        $this->user->client()->update(['auto_charge' => true]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /** An invoice of 100 paid 130 at Stripe: 30 became account credit. */
    private function overpaid(): Invoice
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame(30.0, $this->credit());

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /** @return array{0: string, 1: string} refunded, credited */
    private function payment(): array
    {
        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_over')->sole();

        return [(string) $payment->refunded_amount, (string) $payment->credited_amount];
    }

    private function assertMoneyCheckClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    public function test_a_chargeback_takes_back_the_credit_the_overpayment_left_and_a_win_gives_it_back(): void
    {
        $invoice = $this->overpaid();

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));

        $this->assertSame([0.0, Invoice::STATUS_UNPAID], [$this->credit(), $invoice->fresh()->status], 'the bank took all 130: no credit left of it');
        $this->assertSame(['130.00', '0.00'], $this->payment());
        $this->assertFalse((bool) $this->user->client()->fresh()->auto_charge, 'a card that disputed is not charged again by itself');
        $this->assertSame(1, EmailLog::query()->where('subject', 'Chargeback needs staff attention')->count());
        $this->assertMoneyCheckClean();

        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));

        $this->assertSame([30.0, Invoice::STATUS_PAID], [$this->credit(), $invoice->fresh()->status], 'won: the credit and the invoice as they were');
        $this->assertSame(['0.00', '30.00'], $this->payment());
        $this->assertFalse((bool) $this->user->client()->fresh()->auto_charge, 'never turned on again by itself');
        $this->assertMoneyCheckClean();
    }

    public function test_a_spent_overpayment_is_never_a_debt_and_a_win_adds_no_credit_for_it(): void
    {
        $invoice = $this->overpaid();
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($this->invoice(30)->id)); // spent on another invoice

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame([0.0, Invoice::STATUS_UNPAID], [$this->credit(), $invoice->fresh()->status], 'never a debt');
        $this->assertSame(['130.00', '0.00'], $this->payment());
        $this->assertStringContainsString('settle it by hand', (string) EmailLog::query()->where('subject', 'Chargeback needs staff attention')->value('body'));
        $this->assertMoneyCheckClean();

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame([0.0, Invoice::STATUS_PAID], [$this->credit(), $invoice->fresh()->status], 'the other invoice was rightly paid: no credit again');
        $this->assertSame(['0.00', '30.00'], $this->payment());
        $this->assertStringContainsString('if you made a debt of it, undo it', (string) EmailLog::query()->where('subject', 'Chargeback reversed (dispute won)')->value('body'));
        $this->assertMoneyCheckClean();
    }

    public function test_a_chargeback_of_a_payment_without_an_overpayment_stops_automatic_charging_too(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_2', 100.00, 'usd');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertFalse((bool) $this->user->client()->fresh()->auto_charge);
        $this->assertStringContainsString('Automatic charging of the account is turned off',
            (string) EmailLog::query()->where('subject', 'Chargeback needs staff attention')->value('body'));
    }
}

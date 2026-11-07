<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\SwConcPay\SwConcPay;
use Tests\TestCase;

/**
 * Adversarial verification, flow f-concurrency (sweep 2).
 *
 * F-CONCURRENCY-1: credit auto-apply pays an invoice whose saved-card charge is still unresolved (SavedMethodCharge::
 * onItsWay), so a late webhook of that charge finds it paid and the customer paid twice.
 * F-CONCURRENCY-3: a gateway refund of a paid invoice takes the global credit-note counter row (a locking read) after
 * the gateway already gave the money back, against refund()'s own rule.
 */
class Sweep2FConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        if (!class_exists(SwConcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\SwConcPay; class SwConcPay {
                public static bool $refunded = false;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return true; }
                public function supportsRefunds() { return true; }
                public static int $refunds = 0;
                public function refund($transaction, $amount) { self::$refunded = true; return "re_sweep2_" . ++self::$refunds; } }');
        }
        SwConcPay::$refunded = false;
        SwConcPay::$refunds = 0;
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'SwConcPay'], ['name' => 'SwConcPay', 'type' => 'gateway', 'enabled' => true]);
    }

    private function invoice(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function unresolvedCharge(Invoice $invoice, string $status): ChargeAttempt
    {
        $agreement = BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);

        return ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->user->client()->id,
            'billing_agreement_id' => $agreement->id, 'amount' => '100.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => $status, 'started_at' => now()->subDay(), 'sent_at' => now()->subDay()]);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** F-CONCURRENCY-1: a charge of unknown outcome (a timeout, maybe taken) holds the invoice; credit pays it anyway. */
    public function test_credit_does_not_pay_an_invoice_whose_card_charge_has_an_unknown_outcome(): void
    {
        $invoice = $this->invoice(100);
        $this->unresolvedCharge($invoice, ChargeAttempt::STATUS_UNKNOWN);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'precondition: the charge holds the invoice');
        (new CreditLedger)->add($this->user->client()->id, 'USD', 10000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        $paid = (new CreditAutoApply)->forCustomer($this->user->client()->id, 'USD');

        $this->assertSame(0, $paid, 'credit paid an invoice whose card charge may have taken the money already');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('100.00', $this->balance());
    }

    /** F-CONCURRENCY-1: the same for a charge that succeeded at the gateway and whose payment is not recorded yet. */
    public function test_credit_does_not_pay_an_invoice_whose_card_charge_succeeded_but_is_not_recorded(): void
    {
        $invoice = $this->invoice(100);
        $this->unresolvedCharge($invoice, ChargeAttempt::STATUS_SUCCEEDED);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'precondition: the charge holds the invoice');
        (new CreditLedger)->add($this->user->client()->id, 'USD', 10000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');

        $transaction = (new CreditAutoApply)->toInvoice($invoice->id);

        $this->assertNull($transaction, 'credit paid an invoice whose card charge took the money already (webhook late)');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * F-CONCURRENCY-3: after the gateway call, refund() reads the global credit_note_number row (lockForUpdate on
     * MariaDB; SQLite drops FOR UPDATE, so the read itself is what is seen here). Any other credit note held open makes
     * the refund wait or fail after the money left.
     */
    public function test_a_gateway_refund_reads_no_global_counter_after_the_money_left(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'SwConcPay', 100, transactionId: 'ch_sweep2_1', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $payment = $invoice->fresh()->transactions()->sole();

        $after = [];
        DB::listen(function (QueryExecuted $query) use (&$after) {
            if (SwConcPay::$refunded && str_contains($query->sql, '"settings"') && in_array('credit_note_number', $query->bindings, true)) {
                $after[] = $query->sql;
            }
        });

        ExtensionHelper::refund($payment, 30.00, notify: false);

        $this->assertTrue(SwConcPay::$refunded);
        $this->assertSame([], $after, 'after the gateway refunded, refund() read/locked the global counter row: ' . implode(' | ', $after));
    }

    /** F-CONCURRENCY-3 fix: the note still takes the next credit-note number, the counter moves by one, in the set format. */
    public function test_a_gateway_refund_note_takes_the_next_credit_note_number(): void
    {
        config(['settings.credit_note_number_format' => 'CN-{number}', 'settings.credit_note_number_padding' => 3]);
        Setting::query()->updateOrCreate(['key' => 'credit_note_number', 'settingable_type' => null], ['value' => '6', 'type' => 'string']);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'SwConcPay', 100, transactionId: 'ch_sweep2_2', currency: 'USD');
        $payment = $invoice->fresh()->transactions()->sole();

        ExtensionHelper::refund($payment, 30.00, notify: false);
        ExtensionHelper::refund($payment->fresh(), 10.00, notify: false);

        $this->assertSame(['CN-007', 'CN-008'], $invoice->fresh()->adjustmentNotes()->orderBy('id')->pluck('number')->all());
        $this->assertSame('8', (string) Setting::query()->where('key', 'credit_note_number')->whereNull('settingable_type')->value('value'));
        $this->assertSame('8', (string) config('settings.credit_note_number'));
        // A credit note written another way (a staff refund to credit) goes on from the same counter.
        ExtensionHelper::refundToCredit($payment->fresh(), 5.00, notify: false);
        $this->assertSame('CN-009', $invoice->fresh()->adjustmentNotes()->orderByDesc('id')->value('number'));
    }

    /** F-CONCURRENCY-3 fix: a refund its webhook recorded already writes no note, and the counter locked for it takes no number. */
    public function test_a_gateway_refund_already_recorded_leaves_no_gap_in_the_credit_notes(): void
    {
        Setting::query()->updateOrCreate(['key' => 'credit_note_number', 'settingable_type' => null], ['value' => '6', 'type' => 'string']);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'SwConcPay', 100, transactionId: 'ch_sweep2_3', currency: 'USD');
        $payment = $invoice->fresh()->transactions()->sole();
        GatewayRefund::query()->create(['invoice_transaction_id' => $payment->id, 'gateway' => 'SwConcPay', 'reference' => 're_sweep2_1',
            'payment_reference' => 'ch_sweep2_3', 'amount' => 30, 'currency_code' => 'USD', 'recorded_amount' => 30,
            'origin' => GatewayRefund::ORIGIN_GATEWAY]);

        ExtensionHelper::refund($payment, 30.00, notify: false);

        $this->assertSame(0, $invoice->fresh()->adjustmentNotes()->count());
        $this->assertSame('6', (string) Setting::query()->where('key', 'credit_note_number')->whereNull('settingable_type')->value('value'));
    }
}

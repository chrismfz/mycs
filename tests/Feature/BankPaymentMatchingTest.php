<?php

namespace Tests\Feature;

use App\Admin\Widgets\PaymentsTracking;
use App\Helpers\ExtensionHelper;
use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\ChatNotificationRule;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\BankAlerts\PaymentMatcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\BankTransfer\BankTransfer;
use Tests\TestCase;

/**
 * The bank's credits matched to the invoices they pay (owner 2026-10-25; Bank transfer's knob): only an authentic, pending,
 * recent credit naming exactly one unpaid EUR invoice by its number, with exactly its remaining; recorded once, ever.
 */
class BankPaymentMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendChatNotification::class]);
        $this->gateway = Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function invoice(string $number, string $price = '150.00', string $currency = 'EUR', ?int $id = null): Invoice
    {
        $invoice = Invoice::factory()->create(array_filter(['id' => $id, 'client_id' => User::factory()->create()->client()->id, 'status' => 'unpaid',
            'currency_code' => $currency, 'number' => $number === '' ? null : $number, 'due_at' => now()->addDays(7)], fn ($v) => $v !== null));
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function credit(string $reason, string $amount = '150.00', array $attributes = []): BankMovement
    {
        return BankMovement::query()->create(array_merge(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => $amount, 'balance' => '1000.00',
            'reason' => $reason, 'executed_at' => now()->subHour(), 'message_key' => uniqid('', true), 'authentic' => true, 'status' => 'pending'], $attributes));
    }

    public function test_a_credit_naming_one_unpaid_invoice_with_its_remaining_pays_it_once(): void
    {
        $invoice = $this->invoice('32999');
        $credit = $this->credit('ΙΩΑΝΝΗΣ ΔΟΚΙΜΑΣΤΙΚΟΣ ΤΙΜΟΛΟΓΙΟ 32999');

        $this->assertSame(['matched' => 1, 'left' => 0], PaymentMatcher::run());
        $this->assertSame(['matched' => 0, 'left' => 0], PaymentMatcher::run(), 'a second run does nothing');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $transaction = InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(['150.00', 'bank-alert:' . $credit->id, $this->gateway->id], [(string) $transaction->amount, $transaction->transaction_id, $transaction->gateway_id]);
        $credit->refresh();
        $this->assertSame([BankMovement::MATCHED, $invoice->id], [$credit->status, $credit->invoice_id]);
        $this->assertNotNull($credit->matched_at);
    }

    public function test_never_paid_twice_even_if_the_credit_is_put_back_to_pending(): void
    {
        $invoice = $this->invoice('32999', '100.00');
        $credit = $this->credit('32999', '100.00');
        PaymentMatcher::run();
        // Somehow back to pending, its invoice unpaid again (staff reversed the payment by hand): the same credit never
        // records a second payment (its transaction id is its own).
        DB::table('bank_movements')->where('id', $credit->id)->update(['status' => 'pending', 'invoice_id' => null]);

        PaymentMatcher::run();

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'bank-alert:' . $credit->id)->count());
        $this->assertSame('100.00', number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sum('amount'), 2, '.', ''));
    }

    public function test_a_different_amount_or_a_partly_paid_invoice_is_left_for_staff_with_why(): void
    {
        $this->invoice('32999', '150.00');
        $short = $this->credit('32999', '100.00');
        $partly = $this->invoice('33000', '150.00');
        $partly->transactions()->create(['amount' => 50]);
        $rest = $this->credit('ΥΠΟΛΟΙΠΟ 33000', '100.00');

        PaymentMatcher::run();

        $this->assertSame(BankMovement::PENDING, $short->fresh()->status);
        $this->assertStringContainsString('150.00 left to pay, not 100.00', (string) $short->fresh()->match_note);
        $this->assertSame(BankMovement::MATCHED, $rest->fresh()->status, 'what is left to pay, not the total');
    }

    public function test_none_or_several_invoices_named_is_left_for_staff(): void
    {
        $this->invoice('32999', '50.00');
        $this->invoice('33000', '50.00');
        $two = $this->credit('32999 33000', '50.00');
        $none = $this->credit('ΑΝΑΝΕΩΣΗ example.gr', '50.00');

        PaymentMatcher::run();

        $this->assertSame([BankMovement::PENDING, BankMovement::PENDING], [$two->fresh()->status, $none->fresh()->status]);
        $this->assertStringContainsString('more than one', (string) $two->fresh()->match_note);
        $this->assertStringContainsString('No unpaid EUR invoice', (string) $none->fresh()->match_note);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_only_authentic_pending_recent_eur_credits(): void
    {
        $this->invoice('31000', '10.00');
        $this->invoice('31001', '10.00');
        $this->invoice('31002', '10.00');
        $this->invoice('31003', '10.00');
        $this->invoice('31004', '10.00', 'USD');
        $unverified = $this->credit('31000', '10.00', ['authentic' => false, 'status' => 'unverified']);
        $history = $this->credit('31001', '10.00', ['status' => 'history']);
        $old = $this->credit('31002', '10.00', ['executed_at' => now()->subDays(8)]);
        $debit = $this->credit('31003', '10.00', ['direction' => 'debit', 'status' => 'ignored']);
        $usd = $this->credit('31004', '10.00');

        PaymentMatcher::run(7);

        $this->assertSame(0, InvoiceTransaction::query()->count(), 'none of them pays anything');
        $this->assertSame(BankMovement::PENDING, $usd->fresh()->status);
        foreach ([$unverified, $history, $old, $debit] as $movement) {
            $this->assertNotSame(BankMovement::MATCHED, $movement->fresh()->status);
        }
    }

    public function test_nixpal_numbers_and_an_id_that_is_no_number(): void
    {
        $numbered = $this->invoice('2026-6845', '30.00');
        $byId = $this->invoice('2026-6846', '40.00', id: 7238); // its id is no number the customer was given
        $this->credit('INV 2026-6845', '30.00');
        $idOnly = $this->credit('PAYMENT ' . $byId->id, '40.00');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_PAID, $numbered->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $byId->fresh()->status, 'never matched by an id when it has a number');
        $this->assertSame(BankMovement::PENDING, $idOnly->fresh()->status);
    }

    public function test_an_invoice_with_no_number_is_named_by_its_id(): void
    {
        $invoice = $this->invoice('', '20.00', id: 41234);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => null]); // as an old imported one: no number
        $this->credit('ΠΛΗΡΩΜΗ ' . $invoice->id, '20.00');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_staff_are_told_in_the_chat_and_see_it_matched(): void
    {
        ChatNotificationRule::query()->create(['name' => 'Billing', 'webhook_url' => 'https://chat.example.test/hooks/abcdefghijk', 'events' => ['payment_matched']]);
        $invoice = $this->invoice('32999');
        $credit = $this->credit('32999');

        PaymentMatcher::run();

        $told = Queue::pushed(SendChatNotification::class)->map(fn ($job) => $job->text)->implode("\n");
        $this->assertStringContainsString('Payment auto matched', $told);
        $this->assertStringContainsString('#32999', $told);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(PaymentsTracking::class)->assertCanSeeTableRecords([$credit])
            ->assertTableActionHidden('reopen', $credit)->assertTableActionHidden('acknowledge', $credit);
        $this->assertSame($invoice->id, $credit->fresh()->invoice_id);
    }

    public function test_the_command_matches_only_when_the_knob_is_on(): void
    {
        $this->invoice('32999');
        $this->credit('32999');
        $this->assertFalse((new BankTransfer([]))->alertSettings() !== null, 'off until it is set up');
        foreach (['alerts_enabled' => true, 'alerts_host' => 'imap.example.test', 'alerts_username' => 'alerts', 'alerts_password' => 'x', 'alerts_folders' => ' ',
            'alerts_auto_match' => false] as $key => $value) {
            $this->gateway->settings()->create(['key' => $key, 'value' => $value, 'encrypted' => $key === 'alerts_password']);
        }
        $this->assertFalse(ExtensionHelper::getExtension('gateway', 'BankTransfer', $this->gateway->fresh()->settings)->alertSettings()['auto_match']);
        $this->assertSame(0, InvoiceTransaction::query()->count(), 'the knob off: nothing paid');
    }
}

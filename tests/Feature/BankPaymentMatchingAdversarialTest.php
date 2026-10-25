<?php

namespace Tests\Feature;

use App\Admin\Widgets\PaymentsTracking;
use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\BankAlerts\PaymentMatcher;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #458 (automatic bank matching): each test is a way the matcher records money it should not.
 * The row-lock one (lock order against a staff payment) is in BankPaymentMatchingConcurrencyAdversarialTest.
 */
class BankPaymentMatchingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendChatNotification::class]);
        $this->gateway = Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function invoice(?string $number, string $price, ?int $clientId = null, ?int $id = null, string $status = 'unpaid'): Invoice
    {
        $invoice = Invoice::factory()->create(array_filter(['id' => $id, 'client_id' => $clientId ?? User::factory()->create()->client()->id,
            'status' => 'unpaid', 'currency_code' => 'EUR', 'number' => $number, 'due_at' => now()->addDays(7)], fn ($v) => $v !== null));
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        // As the PR's tests: an old/proforma one has no number; the status as given (a paid one, raw: no side effects).
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => $number, 'status' => $status]);

        return $invoice->fresh();
    }

    private function credit(string $reason, string $amount): BankMovement
    {
        return BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => $amount, 'balance' => '1000.00',
            'reason' => $reason, 'executed_at' => now()->subHour(), 'message_key' => uniqid('', true), 'authentic' => true, 'status' => 'pending']);
    }

    private function received(int $invoiceId): string
    {
        return number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoiceId)->sum('amount'), 2, '.', '');
    }

    /**
     * ADV-1. A credit the matcher left for staff stays pending, and is tried again on every run for N days. Staff do what
     * the note asks: they record it by hand where it belongs (the customer's other invoice; «Add payment» and ✓ are two
     * separate steps, and nothing ties the hand-entered payment to the credit). The named invoice's remaining later
     * becomes the amount (a line edited, credit applied, a partial payment): the next run records the same 100 € again.
     */
    public function test_a_credit_left_for_staff_and_recorded_by_hand_is_not_matched_again_later(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $named = $this->invoice('32999', '150.00', $clientId);
        $meant = $this->invoice('33000', '100.00', $clientId);
        $credit = $this->credit('ΠΛΗΡΩΜΗ ΤΙΜΟΛΟΓΙΟΥ 32999', '100.00');

        $this->assertSame(['matched' => 0, 'left' => 1], PaymentMatcher::run(), 'left for staff: 150 left to pay, not 100');

        // Staff: the customer said it was for 33000. Recorded by hand, as before #458; ✓ not clicked yet.
        (new RecordPaymentService)->handle($meant->id, $this->gateway, '100.00', enteredByStaff: true, paidAt: now());
        // Later on: 32999 is reduced to 100 (a line edited down / a discount).
        $named->items()->first()->update(['price' => '100.00']);

        PaymentMatcher::run();

        $this->assertSame('100.00', $this->received($meant->id));
        $this->assertSame('0.00', $this->received($named->id), 'one bank credit of 100 € recorded twice: 32999 paid for free');
        $this->assertSame(Invoice::STATUS_UNPAID, $named->fresh()->status);
        $this->assertNotSame(BankMovement::MATCHED, $credit->fresh()->status);
    }

    /**
     * ADV-2. A date in the reference («2026-10-25») is read as the {YEAR}-{NUMBER} number «2026-10»: the dash form's
     * lookahead only refuses a digit, not a following «-NN». Another customer's invoice 2026-10 with that amount is paid.
     */
    public function test_a_date_in_the_reference_is_no_invoice_number(): void
    {
        $other = $this->invoice('2026-10', '12.40');
        $this->credit('ΑΝΑΝΕΩΣΗ DOMAIN 2026-10-25', '12.40');

        $this->assertSame([], array_values(array_intersect(['2026-10'], (new BankMovement(['reason' => 'ΑΝΑΝΕΩΣΗ DOMAIN 2026-10-25']))->invoiceTokens())),
            '«2026-10-25» is a date, not invoice 2026-10');
        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $other->fresh()->status, 'another customer\'s invoice paid by a date');
    }

    /**
     * ADV-3. A token with leading zeros («0150», an IBAN group «0110 1250 ...», a branch code) names the invoice whose
     * id is that integer: whereIn('id', ['0150']) compares as a number. An id is matched only when the invoice has no
     * number (an old one, or a nixpal proforma before it is paid), so these are exactly the open ones.
     */
    public function test_a_token_with_leading_zeros_names_no_invoice_by_id(): void
    {
        $proforma = $this->invoice(null, '24.80', id: 150);
        $this->credit('ΚΑΤΑΘΕΣΗ ΚΑΤΑΣΤΗΜΑ 0150', '24.80');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $proforma->fresh()->status, '«0150» is not invoice id 150');
    }

    /**
     * ADV-4. One token, two invoices: the number «7238» of a paid invoice (customer X, who paid it again) and the id 7238
     * of another customer's open proforma (no number). Ambiguity is counted only after the paid one is filtered out, so
     * X's money pays Y's proforma when the amounts agree (identical renewals: same plan, same .gr price). The PR's own
     * rule says an id is «no number the customer was given»; here it was another customer's number.
     */
    public function test_a_token_that_is_one_invoices_number_and_anothers_id_is_ambiguous(): void
    {
        $this->invoice('7238', '12.40', status: 'paid'); // X's, paid already
        $proformaOfY = $this->invoice(null, '12.40', id: 7238);
        $credit = $this->credit('ΠΛΗΡΩΜΗ 7238', '12.40');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $proformaOfY->fresh()->status, 'X\'s number paid Y\'s proforma');
        $this->assertSame(BankMovement::PENDING, $credit->fresh()->status);
    }

    /**
     * ADV-5. «One credit is paid once, ever: its transaction id is its own» (PaymentMatcher's docblock). The replay check
     * of RecordPaymentService looks for the id only among the same customer's invoices, so once the credit is pending
     * again (the PR's own premise in test_never_paid_twice...), the reference naming a second invoice of another
     * customer records the same bank credit a second time.
     */
    public function test_never_paid_twice_even_on_another_customers_invoice(): void
    {
        $first = $this->invoice('32999', '50.00');
        $second = $this->invoice('33000', '50.00', status: 'draft'); // not open yet
        // (Setup adjusted when fixing ADV-4: a reference naming two invoices is now ambiguous from the start; the reason
        // names 32999 first, then 33000, to reach the same state.)
        $credit = $this->credit('32999', '50.00');
        PaymentMatcher::run();
        $this->assertSame(Invoice::STATUS_PAID, $first->fresh()->status);

        DB::table('invoices')->where('id', $second->id)->update(['status' => 'unpaid']);
        DB::table('bank_movements')->where('id', $credit->id)->update(['status' => 'pending', 'invoice_id' => null, 'match_note' => null, 'reason' => '33000']);
        PaymentMatcher::run();

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', PaymentMatcher::TRANSACTION_PREFIX . $credit->id)->count(),
            'the same bank credit recorded on two customers\' invoices');
        $this->assertSame(Invoice::STATUS_UNPAID, $second->fresh()->status);
    }

    // ---- Round 2 (fixes at 5183ea0b) ----

    /**
     * R2-1. «Tried once» is keyed on match_note, but a null note does not mean «never seen by staff». A credit staff saw
     * to before the matcher ever tried it (the knob was off, or it was IGNORED as a configured reference) has no note;
     * staff recorded it by hand elsewhere and clicked ✓. «Back to pending» (reopen) clears the ✓ and leaves the note null,
     * so the next run hands it to the matcher: the same 100 € recorded a second time on the invoice its reference names.
     */
    public function test_a_credit_staff_saw_to_and_reopened_is_not_matched_automatically(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $named = $this->invoice('32999', '100.00', $clientId);
        $meant = $this->invoice('33000', '100.00', $clientId);
        $credit = $this->credit('ΠΛΗΡΩΜΗ 32999', '100.00');
        // Knob off at the time: staff recorded it by hand on 33000 (the customer said so) and saw to it.
        (new RecordPaymentService)->handle($meant->id, $this->gateway, '100.00', enteredByStaff: true, paidAt: now());
        $credit->update(['status' => BankMovement::ACKNOWLEDGED, 'acknowledged_at' => now()]);

        // Later (knob on): staff reopen it, to look at it again.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(PaymentsTracking::class)->callTableAction('reopen', $credit);
        $this->assertSame(BankMovement::PENDING, $credit->fresh()->status);

        PaymentMatcher::run();

        $this->assertSame('0.00', $this->received($named->id), 'a credit staff handled by hand is recorded again by the matcher');
        $this->assertNotSame(BankMovement::MATCHED, $credit->fresh()->status);
    }

    /**
     * R2-2. The same gap when the knob is turned on: every pending credit of the last N days with no note is matched on
     * the first run, including those staff were already handling by hand (recorded, ✓ not yet clicked). Before the knob
     * a pending credit with a hand-entered payment was harmless; the first run records it again.
     */
    public function test_turning_the_knob_on_does_not_match_credits_staff_already_recorded_by_hand(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $named = $this->invoice('32999', '100.00', $clientId);
        $meant = $this->invoice('33000', '100.00', $clientId);
        $credit = $this->credit('ΠΛΗΡΩΜΗ 32999', '100.00');
        $credit->forceFill(['created_at' => now()->subDays(2), 'executed_at' => now()->subDays(2)])->save();
        (new RecordPaymentService)->handle($meant->id, $this->gateway, '100.00', enteredByStaff: true, paidAt: now()->subDays(2));

        PaymentMatcher::run(); // the knob's first run

        $this->assertSame('0.00', $this->received($named->id), 'a credit staff recorded by hand two days ago is recorded again');
    }

    /**
     * R2-3. The date rule strips «2026-10-25», «25/10/2026», «25.10.26», but not a year-first date with «/» or «.»
     * («2026/10/25», «2026.10.25», as some banking apps write it): «2026» stays a token and names the open, number-less
     * proforma whose id is 2026.
     */
    public function test_a_year_first_date_with_slashes_or_dots_names_no_invoice(): void
    {
        foreach (['ΠΛΗΡΩΜΗ 2026/10/25', 'ΠΛΗΡΩΜΗ 2026.10.25'] as $reason) {
            $this->assertNotContains('2026', (new BankMovement(['reason' => $reason]))->invoiceTokens(), "«{$reason}» is a date");
        }
        $proforma = $this->invoice(null, '24.80', id: 2026);
        $this->credit('ΠΛΗΡΩΜΗ 2026/10/25', '24.80');

        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $proforma->fresh()->status);
    }
}

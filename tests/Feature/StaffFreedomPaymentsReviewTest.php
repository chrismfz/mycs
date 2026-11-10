<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ekdosi\EkdosiFeed;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\MoneyCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Independent and adversarial review of #781 (staff freedom on payments: «Edit» a payment, «Undo refund»). Each test is
 * one finding (REV-781-n) and fails on the PR's head. On invoices the WHMCS importer brought with its own steps: #480 paid
 * there, #501 unpaid and paid here (service 31, to 2026-10-10), #502 and #503 unpaid (service 32). Made-up rows only.
 */
class StaffFreedomPaymentsReviewTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Staff $staff;

    private User $user;

    private InvoiceTransaction $payment;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-09-10');
        $this->whmcsService(32, '2026-09-25');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-08-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [32 => 10.0], due: '2026-09-25');
        $this->whmcsInvoice(503, 'Unpaid', [32 => 10.0], due: '2026-10-25');
        $this->importWhmcs();
        // After the import: its last step reloads the settings, and a value set before it is lost (credit auto-apply off).
        config(['settings.credits_enabled' => true]);
        $this->imported(501);
        $this->imported(502);
        $this->payment = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(501), null, '10.00', 'tr_501');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
        Carbon::setTestNow('2026-09-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: int, 2: int} status, what is left and the money it keeps, in cents */
    private function state(int $invoice): array
    {
        $fresh = Invoice::query()->findOrFail($invoice);

        return [$fresh->status, RecordPaymentService::toCents($fresh->remaining), $fresh->moneyKeptCents()];
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** @param  list<string>  $known  rules a scenario leaves on purpose */
    private function assertMoneyClean(array $known = [], string $when = 'after the correction'): void
    {
        $rules = array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received']));
        $found = array_values(array_unique(array_column((new MoneyCheck)->run($rules), 'rule')));
        sort($found);
        sort($known);
        $this->assertSame($known, $found, "the money check {$when}: " . json_encode((new MoneyCheck)->run($rules)));
    }

    private function refund(InvoiceTransaction $payment): MoneyEvent
    {
        return UndoRefund::refunds($payment)->sole();
    }

    private function stripe(): Gateway
    {
        return Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
    }

    /**
     * REV-781-1 (high). A refund recorded «by hand» by mistake on a Stripe payment of 10.00 (4.00) is undone: its journal
     * event is reversed, the payment holds 10.00 again. A real refund of 4.00 then made in Stripe's dashboard (re_1) is
     * told by its webhook. ExtensionHelper::recordGatewayRefund adds up the by-hand refund events of the payment WITHOUT
     * leaving the reversed ones out (they could not exist before «Undo refund»), takes re_1 for «the refund recorded by
     * hand» and records nothing: 4.00 left the account and MyCS shows the invoice paid with 10.00 on it.
     */
    public function test_rev_781_1_a_gateway_refund_after_an_undone_by_hand_refund_is_recorded(): void
    {
        $this->stripe();
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        ExtensionHelper::refundLocally($charge, 4.00, notify: false);
        (new UndoRefund)->handle($charge, $this->refund($charge)->id);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'precondition: undone, the payment holds 10.00');

        $recorded = ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR');

        $this->assertSame('4.00', (string) $charge->fresh()->refunded_amount, 'the money left at the gateway: no by-hand refund stands that it could be');
        $this->assertTrue($recorded);
        $this->assertNotSame(GatewayRefund::ORIGIN_HAND, GatewayRefund::query()->where('reference', 're_1')->sole()->origin);
        $this->assertSame([Invoice::STATUS_PAID, 0, 600], $this->state(502));
        $this->assertMoneyClean();
    }

    /**
     * REV-781-2a (high). UndoRefund lowers refunded_amount on the payment BEFORE it voids the refund's note. Saving the
     * payment fires InvoiceTransactionCreatedListener, which closes an open invoice its payments cover, reading the
     * half-undone state: the money is back, the note still takes the refund off the total.
     *
     * #503 (10.00) paid with 7.00 + 3.00; 3.00 of the 7.00 refunded by hand (note −3.00, paid, 7.00 kept); the 3.00
     * payment deleted (a bounced transfer): unpaid, 4.00 kept, 3.00 to pay. The refund undone: 7.00 kept of 10.00, so it
     * still owes 3.00. It ends PAID owing 3.00 (no «paid by hand» flag): the 3.00 is never asked for again.
     */
    public function test_rev_781_2a_an_undo_on_an_open_invoice_does_not_close_it_while_it_still_owes(): void
    {
        $seven = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(503), null, '7.00', 'tr_503a');
        $three = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(503), null, '3.00', 'tr_503b');
        ExtensionHelper::refundLocally($seven, 3.00, notify: false);
        (new RecordPaymentService)->deleteTransaction($three);
        $this->assertSame([Invoice::STATUS_UNPAID, 300, 400], $this->state(503), 'precondition');
        $this->assertMoneyClean(when: 'before the undo');

        (new UndoRefund)->handle($seven, $this->refund($seven)->id);

        $this->assertSame([Invoice::STATUS_UNPAID, 300, 700], $this->state(503), '7.00 of 10.00 is on it: it owes 3.00 and is not paid');
        $this->assertMoneyClean();
    }

    /**
     * REV-781-2b (high). The same order of steps, on an invoice staff opened again to correct it: #501 (10.00, paid with
     * 10.00), 3.00 refunded by hand (note −3.00), set back to unpaid (7.00 kept of 7.00). The refund undone: the listener
     * sees 10.00 on a total of 7.00, closes it and gives 3.00 as account credit (settle); then the note is voided and
     * the total is 10.00 again. It ends PAID with 7.00 on it, owing 3.00, and the customer holds 3.00 of credit: 3.00
     * made up. Right: paid, 10.00 kept, no credit.
     */
    public function test_rev_781_2b_an_undo_on_a_reopened_invoice_gives_no_credit_for_money_its_total_needs(): void
    {
        Invoice::query()->whereKey([502, 503])->update(['status' => Invoice::STATUS_DRAFT]); // nothing else open for credit to pay
        ExtensionHelper::refundLocally($this->payment, 3.00, notify: false);
        $this->actingAs($this->staff);
        $invoice = Invoice::query()->findOrFail(501);
        $invoice->status = Invoice::STATUS_UNPAID;
        $invoice->save();
        $this->assertSame([Invoice::STATUS_UNPAID, 0, 700], $this->state(501), 'precondition: open again, the note still on it');

        (new UndoRefund)->handle($this->payment, $this->refund($this->payment)->id);

        $this->assertSame('0.00', $this->credit(), 'no credit: the 3.00 that came back is what the invoice bills again');
        $this->assertSame([0, 1000], array_slice($this->state(501), 1), 'nothing left to pay, 10.00 kept');
        $this->assertMoneyClean();
    }

    /**
     * REV-781-3a (medium-high). «Edit» re-points a payment its gateway recorded and keeps its id at the provider (the
     * PR's recordedByGateway). But it is deleteTransaction + recordByStaff, and the deletion takes along every
     * gateway_refunds record of the payment «that moved no money»: a chargeback won back, a dispute won early, a failed
     * refund. Those rows are what makes the gateway's replay of that event a replay.
     *
     * ch_502 (10.00) on #502: charged back (dp_1), the dispute won, the money back. A replay of the chargeback records
     * nothing (control). Staff move the payment to #503. The same replay now charges it back again: #503 owes 10.00
     * that the bank gave back. On main the row and its id go together; here the id lives on without its history.
     */
    public function test_rev_781_3a_a_moved_gateway_payment_keeps_its_won_chargeback_so_a_replay_records_nothing(): void
    {
        $this->stripe();
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'));
        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'));
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'precondition: won, paid again');
        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'), 'control: before the move its replay records nothing');

        $moved = (new EditPayment)->handle($charge->fresh(), ['invoice_id' => 503]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(503));

        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'), 'the same replay after the move');
        $this->assertSame('0.00', (string) $moved->fresh()->refunded_amount);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(503), 'the bank gave the money back: it is on the invoice');
    }

    /**
     * REV-781-3b (medium). The same loss, against the PR's own promise for an undone gateway refund («its record becomes
     * failed, so a late event records nothing and the payment may be deleted/edited again»): after the edit (a move to
     * #503) the «failed» record is gone and the gateway's late event of re_1 records the refund staff had undone.
     */
    public function test_rev_781_3b_a_gateway_refund_undone_stays_undone_after_its_payment_is_moved(): void
    {
        $this->stripe();
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'));
        (new UndoRefund)->handle($charge, $this->refund($charge)->id, moneyLeftAtGateway: true);
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'), 'control: before the move its late event records nothing');

        $moved = (new EditPayment)->handle($charge->fresh(), ['invoice_id' => 503]);

        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'), 'the same late event after the move');
        $this->assertSame('0.00', (string) $moved->fresh()->refunded_amount);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(503));
    }

    /**
     * REV-781-4 (medium). A transaction id corrected in place is not «delete + add»: recordByStaff applies the refunds and
     * chargebacks its gateway reported for that id before the payment was known here (gateway_refunds «waiting»,
     * RecordPaymentService::applyWaitingRefunds); the in-place edit does not.
     *
     * Staff typed a Stripe payment of 10.00 on #502 with a wrong id (ch_typo). Stripe reports a refund of 4.00 (re_1) for
     * ch_502: no such payment here, it waits. Staff correct the id to ch_502 with «Edit». The refund keeps waiting: the
     * invoice shows 10.00 kept while 4.00 went back. (Deleting it and adding it with the right id, on main: 4.00
     * refunded at once. Checked with a control run, not committed.)
     */
    public function test_rev_781_4_an_id_corrected_in_place_takes_the_refund_that_waited_for_it(): void
    {
        $this->stripe();
        $typed = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '10.00', 'ch_typo');
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'));
        $this->assertSame(GatewayRefund::ORIGIN_WAITING, GatewayRefund::query()->sole()->origin, 'precondition: it waits for ch_502');

        (new EditPayment)->handle($typed, ['transaction_id' => 'ch_502']);

        $this->assertSame('ch_502', $typed->fresh()->transaction_id);
        $this->assertSame('4.00', (string) $typed->fresh()->refunded_amount, 'the refund that waited for this id is recorded, as when the payment is added with it');
        $this->assertSame([Invoice::STATUS_PAID, 0, 600], $this->state(502));
    }

    /**
     * REV-781-5 (medium). «On an invoice paid before the cut-over the date is refused: a new date moves paid_at, by which
     * Ekdosi's feed takes invoices.» The refusal hangs on EkdosiDocument::issuedBeforeCutOver (every money event of the
     * invoice is the importer's), and any refund made here switches that off for good, also one that was undone since.
     *
     * #480, paid in WHMCS on 2026-09-03 (its document issued there): 3.00 refunded by hand, then that refund undone:
     * nothing changed (paid, 10.00 kept). The date of its imported payment is now edited to 2026-09-19: paid_at follows
     * and the feed («paid, no document», since 2026-09-15) hands #480 to Ekdosi: a second fiscal document.
     */
    public function test_rev_781_5_the_paid_date_of_an_invoice_paid_in_whmcs_stays_after_a_refund_was_made_and_undone(): void
    {
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        $paidAt = Invoice::query()->findOrFail(480)->paid_at;
        $fed = fn (): array => collect((new EkdosiFeed)->page('paid_unfiled', '2026-09-15', 0, 100)['invoices'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertTrue(EkdosiDocument::issuedBeforeCutOver(480));
        $this->assertNotContains(480, $fed(), 'precondition: paid before the window');
        ExtensionHelper::refundLocally($old, 3.00, notify: false);
        (new UndoRefund)->handle($old, $this->refund($old)->id);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480), 'precondition: as it was');

        try {
            (new EditPayment)->handle($old->fresh(), ['paid_at' => Carbon::parse('2026-09-19 10:00:00')]);
        } catch (DisplayException) {
            // refused: what the PR says for such an invoice
        }

        $this->assertEquals($paidAt, Invoice::query()->findOrFail(480)->paid_at, 'its paid date, by which Ekdosi takes invoices, did not move');
        $this->assertNotContains(480, $fed(), 'and Ekdosi is not handed an invoice whose document WHMCS issued');
    }

    /**
     * REV-781-6 (medium). «Edit» asks for the permission to DELETE payments, and an amount edit is delete + record. A role
     * that may delete but not record payments (no admin.invoice_transactions.create: «Add payment» and «Mark paid» are
     * refused to it, and #780 asks for that permission for anything that makes an invoice «paid» without money) raises a
     * 10.00 payment to 510.00: 500.00 of account credit, spendable, with no payment behind it.
     */
    public function test_rev_781_6_a_role_that_may_not_record_payments_does_not_record_money_through_edit(): void
    {
        $clerk = Staff::factory()->create(['role_id' => Role::create(['name' => 'Clerk', 'permissions' => [
            'admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update', 'admin.invoice_transactions.viewAny', 'admin.invoice_transactions.view', 'admin.invoice_transactions.delete',
        ]])->id]);
        $this->assertFalse($clerk->can('create', InvoiceTransaction::class), 'precondition: may not record a payment');

        try {
            $this->livewireAs($clerk)
                ->test(TransactionsRelationManager::class, ['ownerRecord' => Invoice::query()->findOrFail(501), 'pageClass' => EditInvoice::class])
                ->callTableAction('editPayment', $this->payment, data: ['amount' => '510.00']);
        } catch (\Throwable) {
            // hidden or refused: either way nothing is recorded
        }

        $this->assertSame('0.00', $this->credit(), 'no money recorded by someone who may not record payments');
        $this->assertSame(1000, (int) InvoiceTransaction::query()->where('invoice_id', 501)->get()->sum(fn (InvoiceTransaction $payment) => RecordPaymentService::toCents($payment->amount)));
    }

    /**
     * REV-781-7 (low-medium). deleteTransaction keeps an invoice «paid by hand» only when staff's word (paid_by_staff_at)
     * is not later than the payment (REV-780-6 / REV-780-3-1), reading the payment's created_at. «Edit» now lets staff
     * move that date, and the decision flips.
     *
     * #501 paid with 10.00 on 09-20 10:00; raised by 2.00 at 12:00 (staff's word for the 2.00: flag at 12:00, later than
     * the payment, so deleting the payment reopens it: REV-780-3-1). On 09-25 the payment's date is corrected to 09-22
     * (the transfer's value date). The payment is then deleted (bounced): the invoice stays PAID with nothing on it,
     * owing 12.00.
     */
    public function test_rev_781_7_a_corrected_payment_date_does_not_turn_a_raise_into_paid_by_hand_before_the_payment(): void
    {
        $this->actingAs($this->staff);
        Invoice::query()->findOrFail(501)->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'amount' => 2, 'description' => 'Raise']);
        $invoice = Invoice::query()->findOrFail(501);
        $this->assertSame([Invoice::STATUS_PAID, 200, 1000], $this->state(501));
        $this->assertTrue($invoice->paid_by_staff_at->greaterThan($this->payment->fresh()->created_at), 'precondition: the word came after the payment, for the raise');
        Carbon::setTestNow('2026-09-25 12:00:00');

        (new EditPayment)->handle($this->payment->fresh(), ['paid_at' => Carbon::parse('2026-09-22 10:00:00')]);
        (new RecordPaymentService)->deleteTransaction($this->payment->fresh());

        $this->assertSame(Invoice::STATUS_UNPAID, $this->state(501)[0], 'its only payment is gone: staff\'s word was for the 2.00 of the raise (REV-780-3-1)');
    }

    /**
     * REV-781-8 (low-medium). An undo that brings back more than the invoice bills leaves the part over on a PAID invoice
     * (UndoRefund::statusFollows refuses «over» only for a refunded one; an open one is closed by the listener with the
     * part over as credit). #502 (10.00): 6.00 paid and refunded by hand while open (no note), then paid with 10.00.
     * The refund undone: paid, 16.00 on it, «paid over 6.00» in the money check; the customer's 6.00 is not their credit
     * («Add payment» of 6.00 on it would be). Either refused, or the part over is credit.
     */
    public function test_rev_781_8_an_undo_does_not_leave_a_paid_invoice_holding_more_than_it_bills(): void
    {
        $part = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '6.00', 'tr_502a');
        ExtensionHelper::refundLocally($part, 6.00, notify: false);
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '10.00', 'tr_502b');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'precondition');
        $this->assertMoneyClean(when: 'before the undo');

        try {
            (new UndoRefund)->handle($part, $this->refund($part)->id);
        } catch (DisplayException) {
            $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'refused whole');

            return;
        }

        $this->assertSame(0, $this->state(502)[1], 'nothing over on the invoice (the 6.00 is credit)');
        $this->assertMoneyClean();
    }

    /**
     * REV-781-9 (low). CreditAutoApply never pays an invoice that has a refund-to-credit event (the credit would go
     * straight back into it), and reads the event whether or not it was reversed. After «Undo refund» of a refund to
     * credit, the invoice is shut out of auto-apply for good.
     *
     * #503 (10.00): 6.00 paid, refunded to credit, the refund undone (6.00 on it again, credit 0.00). The customer then
     * gets 4.00 of credit (an overpayment elsewhere). Auto-apply should pay the 4.00 #503 owes; it stays unpaid.
     */
    public function test_rev_781_9_credit_pays_an_invoice_whose_refund_to_credit_was_undone(): void
    {
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_DRAFT]); // nothing else open for the credit
        $part = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(503), null, '6.00', 'tr_503a');
        ExtensionHelper::refundToCredit($part, 6.00, notify: false);
        (new UndoRefund)->handle($part, $this->refund($part)->id);
        $this->assertSame(['0.00', [Invoice::STATUS_UNPAID, 400, 600]], [$this->credit(), $this->state(503)], 'precondition: as before the refund');
        (new RecordPaymentService)->handle(501, null, '4.00', transactionId: 'over', enteredByStaff: true); // paid already: 4.00 of credit

        (new CreditAutoApply)->forCustomer($this->user->client()->id, 'EUR');

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(503), 'the credit pays what it owes, as for any open invoice');
        $this->assertSame('0.00', $this->credit());
    }
}

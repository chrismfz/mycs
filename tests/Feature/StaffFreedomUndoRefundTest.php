<?php

namespace Tests\Feature;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on payments (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-17, docs/staff-freedom.md): «Undo refund».
 * The exact reverse of recording it, by hand, to credit, or (with staff's word) one the gateway made. On invoices the
 * WHMCS importer brought with its own steps: #480 paid there, #501 unpaid and paid here (service 31 runs to 2026-10-10),
 * #502 unpaid (service 32).
 */
class StaffFreedomUndoRefundTest extends TestCase
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
        $this->importWhmcs();
        // After the import: its last step reloads the settings.
        $this->storeSettings(['credits_enabled' => true]);
        $this->imported(501);
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
    private function state(int $invoice = 501): array
    {
        $fresh = Invoice::query()->findOrFail($invoice);

        return [$fresh->status, RecordPaymentService::toCents($fresh->remaining), $fresh->moneyKeptCents()];
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** Σ payment − Σ refund of an invoice's journal, reversed events left out: the money the journal says it holds or gave to credit. */
    private function journalNet(int $invoice = 501): int
    {
        return (int) MoneyEvent::query()->where('invoice_id', $invoice)->whereIn('kind', [MoneyEventKind::Payment->value, MoneyEventKind::Refund->value])
            ->whereDoesntHave('reversal')->get()
            ->sum(fn (MoneyEvent $event) => ($event->kind === MoneyEventKind::Payment ? 1 : -1) * RecordPaymentService::toCents($event->amount));
    }

    /** @param  list<string>  $known  rules a scenario leaves on purpose */
    private function assertMoneyClean(array $known = []): void
    {
        $rules = array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received']));
        $found = array_values(array_unique(array_column((new MoneyCheck)->run($rules), 'rule')));
        sort($found);
        sort($known);
        $this->assertSame($known, $found, 'the money check after the correction: ' . json_encode((new MoneyCheck)->run($rules)));
    }

    private function assertUnusedBounded(int $invoice = 501, int $service = 31): void
    {
        $unused = RecordPaymentService::toCents(ChangeCycle::unused(Service::query()->findOrFail($service), CarbonImmutable::today()));
        $this->assertLessThanOrEqual(Invoice::query()->findOrFail($invoice)->moneyKeptCents(), $unused, 'unused days are worth more than the money kept');
    }

    private function assertPeriodUntouched(): void
    {
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the period given stays given, nothing renewed again');
    }

    private function refund(InvoiceTransaction $payment): MoneyEvent
    {
        return UndoRefund::refunds($payment)->sole();
    }

    private function refused(\Closure $undo): string
    {
        try {
            $undo();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }
        $this->fail('The undo was not refused.');
    }

    private function table(int $invoice = 501)
    {
        return $this->livewireAs($this->staff)
            ->test(TransactionsRelationManager::class, ['ownerRecord' => Invoice::query()->findOrFail($invoice), 'pageClass' => EditInvoice::class]);
    }

    public function test_a_refund_by_hand_is_undone_exactly_and_the_payment_follows_the_normal_rules_again(): void
    {
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_DRAFT]); // nothing open for credit to pay
        ExtensionHelper::refundLocally($this->payment, 3.00, notify: false);
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state());
        $note = AdjustmentNote::query()->where('invoice_id', 501)->where('is_refund', true)->sole();
        $refund = $this->refund($this->payment);
        $this->assertSame(UndoRefund::BY_HAND, UndoRefund::kind($refund));

        $this->table()->callTableAction('undoRefund', $this->payment, data: ['refund' => $refund->id])
            ->assertHasNoTableActionErrors()->assertNotified(__('invoices.refund_undo.done'));

        $this->assertSame('0.00', (string) $this->payment->fresh()->refunded_amount);
        $this->assertSame(AdjustmentNoteStatus::Voided, $note->fresh()->status, 'its note is voided with it');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(), 'as before the refund');
        $this->assertSame(1000, $this->journalNet());
        $this->assertSame(MoneyTrail::CAUSE_REFUND_UNDONE, MoneyEvent::query()->where('reverses_id', $refund->id)->sole()->cause);
        $this->assertSame($this->staff->id, (int) MoneyEvent::query()->where('reverses_id', $refund->id)->sole()->actor_id, 'who undid it');
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched();
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
        $this->assertTrue(UndoRefund::refunds($this->payment)->isEmpty());
        $this->table()->assertTableActionHidden('undoRefund', $this->payment);
        // The voided note is not a discount waiting to be brought back.
        $this->actingAs($this->staff);
        $this->assertFalse(VoidAdjustmentNote::allowed($note->fresh()));
        $this->assertSame(__('invoices.refund_undo.note_stays_voided'), $this->refused(fn () => $note->fresh()->update(['status' => AdjustmentNoteStatus::Active])));
        // Undone twice: nothing.
        $this->assertSame(__('invoices.refund_undo.gone'), $this->refused(fn () => (new UndoRefund)->handle($this->payment, $refund->id)));

        // «A refund hangs on it» no longer holds: the payment is edited and deleted as any other.
        $edited = (new EditPayment)->handle($this->payment->fresh(), ['amount' => '12.00']);
        $this->assertSame('2.00', (string) $edited->credited_amount);
        (new RecordPaymentService)->deleteTransaction($edited);
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state());
        $this->assertSame('0.00', $this->credit());
        // The money adds up; service 31 keeps the month the deleted payment gave it: told to staff (service_unbilled).
        $this->assertMoneyClean(['service_unbilled']);
    }

    public function test_a_refund_in_full_made_the_invoice_refunded_and_its_undo_makes_it_paid_again_with_no_second_period(): void
    {
        ExtensionHelper::refundLocally($this->payment, 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->state()[0]);
        $this->assertSame('0.00', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()));
        $line = Invoice::query()->findOrFail(501)->items()->sole();
        $this->assertNotNull($line->forgone_at, 'precondition: refunded in full, its period was given up');

        $this->livewireAs($this->staff)->test(ListInvoiceTransactions::class)
            ->callTableAction('undoRefund', $this->payment, data: ['refund' => $this->refund($this->payment)->id])->assertHasNoTableActionErrors();

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state());
        $this->assertSame(1000, $this->journalNet());
        $this->assertPeriodUntouched();
        $after = $line->fresh();
        $this->assertNull($after->forgone_at, 'its period is its own again');
        $this->assertEquals([$line->period_start, $line->period_end, $line->applied_at], [$after->period_start, $after->period_end, $after->applied_at], 'and nothing applied a second time');
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
    }

    public function test_a_refund_to_credit_is_undone_and_its_credit_taken_back(): void
    {
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_DRAFT]); // nothing open for the credit to pay
        ExtensionHelper::refundToCredit($this->payment, 4.00, notify: false);
        $this->assertSame(['4.00', [Invoice::STATUS_PAID, 0, 600]], [$this->credit(), $this->state()]);
        $this->assertSame(UndoRefund::TO_CREDIT, UndoRefund::kind($this->refund($this->payment)));

        $this->table()->callTableAction('undoRefund', $this->payment, data: ['refund' => $this->refund($this->payment)->id])->assertHasNoTableActionErrors();

        $this->assertSame('0.00', $this->credit());
        $this->assertSame('0.00', (string) $this->payment->fresh()->credited_amount);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state());
        $this->assertFalse(RecordPaymentService::refundedToCredit($this->payment));
        $this->assertSame(0, AdjustmentNote::query()->where('invoice_id', 501)->where('status', AdjustmentNoteStatus::Active->value)->count());
        $this->assertPeriodUntouched();
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
    }

    public function test_a_refund_to_credit_already_spent_is_undone_only_with_the_tick_and_the_credit_goes_negative(): void
    {
        ExtensionHelper::refundToCredit($this->payment, 4.00, notify: false);
        (new RecordPaymentService)->payWithCredit(502); // the customer used it on another invoice
        $this->assertSame('0.00', $this->credit());
        $refund = $this->refund($this->payment);

        $this->assertSame(__('invoices.refund_undo.credit_spent'), $this->refused(fn () => (new UndoRefund)->handle($this->payment, $refund->id)));
        $this->assertSame([Invoice::STATUS_PAID, 0, 600], $this->state(), 'refused whole');

        $this->table()->callTableAction('undoRefund', $this->payment, data: ['refund' => $refund->id, 'allow_negative_credit' => true])->assertHasNoTableActionErrors();

        $this->assertSame('-4.00', $this->credit(), 'they owe it');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state());
        $this->assertSame([Invoice::STATUS_UNPAID, 600, 400], $this->state(502), 'what the credit paid stays paid');
        $this->assertUnusedBounded();
        $this->assertMoneyClean(['credit_negative']);
    }

    public function test_a_refund_the_gateway_made_is_undone_only_as_a_correction_of_the_books_and_calls_no_gateway(): void
    {
        Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'));
        $this->assertSame([Invoice::STATUS_PAID, 0, 600], $this->state(502));
        $refund = $this->refund($charge);
        $this->assertSame(UndoRefund::BY_GATEWAY, UndoRefund::kind($refund));
        $this->assertStringContainsString('gateway', $this->refused(fn () => (new RecordPaymentService)->deleteTransaction($charge)));

        $this->assertSame(__('invoices.refund_undo.gateway_unconfirmed'), $this->refused(fn () => (new UndoRefund)->handle($charge, $refund->id)));
        $this->table(502)->callTableAction('undoRefund', $charge, data: ['refund' => $refund->id])->assertNotified(__('invoices.refund_undo.refused'));
        $this->assertSame('4.00', (string) $charge->fresh()->refunded_amount);

        // No gateway class exists for «Stripe» here: a call to it would throw.
        $this->table(502)->callTableAction('undoRefund', $charge, data: ['refund' => $refund->id, 'money_left_at_gateway' => true])->assertHasNoTableActionErrors();

        $this->assertSame('0.00', (string) $charge->fresh()->refunded_amount);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502));
        $this->assertSame(1000, $this->journalNet(502));
        $record = GatewayRefund::query()->where('reference', 're_1')->sole();
        $this->assertSame([GatewayRefund::ORIGIN_FAILED, '0.00'], [$record->origin, (string) $record->recorded_amount]);
        // The gateway's event of that refund, late or again, records nothing.
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'));
        $this->assertSame('0.00', (string) $charge->fresh()->refunded_amount);
        $this->assertMoneyClean();
        // And the payment follows the normal rules again.
        (new RecordPaymentService)->deleteTransaction($charge->fresh());
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(502));
        $this->assertSame(0, GatewayRefund::query()->count());
        // Its payment renewed service 32 to 2026-10-25 (its line came with that period, WhmcsLinePeriods; before, the line
        // of a service's only invoice took the period from its start and renewed nothing, S8-CRON-BILLING-1): deleted,
        // the service runs on an invoice that is unpaid again, and that is told.
        $this->assertSame('2026-10-25', Service::query()->findOrFail(32)->expires_at->toDateString());
        $this->assertMoneyClean(['service_unbilled']);
    }

    public function test_a_refund_by_hand_the_gateway_then_reported_is_the_gateways(): void
    {
        Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        ExtensionHelper::refundLocally($charge, 4.00, notify: false);
        ExtensionHelper::refundedAtGateway('Stripe', 'ch_502', 're_1', 4.00, 'EUR'); // matched to it, not recorded twice
        $this->assertSame(GatewayRefund::ORIGIN_HAND, GatewayRefund::query()->sole()->origin);
        $refund = $this->refund($charge);

        $this->assertSame(UndoRefund::BY_GATEWAY, UndoRefund::kind($refund));
        $this->assertSame(__('invoices.refund_undo.gateway_unconfirmed'), $this->refused(fn () => (new UndoRefund)->handle($charge, $refund->id)));
        (new UndoRefund)->handle($charge, $refund->id, moneyLeftAtGateway: true);

        $this->assertSame(GatewayRefund::ORIGIN_FAILED, GatewayRefund::query()->sole()->origin);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502));
        $this->assertMoneyClean();
    }

    public function test_kept_a_chargeback_is_not_a_refund_to_undo(): void
    {
        Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'));
        $event = MoneyEvent::query()->where('invoice_transaction_id', $charge->id)->where('kind', MoneyEventKind::Refund->value)->sole();
        $before = [$this->state(502), (string) $charge->fresh()->refunded_amount];

        $this->assertTrue(UndoRefund::refunds($charge)->isEmpty());
        $this->table(502)->assertTableActionHidden('undoRefund', $charge);
        $this->assertSame(__('invoices.refund_undo.gone'), $this->refused(fn () => (new UndoRefund)->handle($charge, $event->id, moneyLeftAtGateway: true)));
        $this->assertSame($before, [$this->state(502), (string) $charge->fresh()->refunded_amount]);
    }

    public function test_kept_no_undo_under_a_fiscal_document_on_a_cancelled_invoice_or_a_credit_purchase(): void
    {
        ExtensionHelper::refundLocally($this->payment, 3.00, notify: false);
        $refund = $this->refund($this->payment);

        $document = EkdosiDocument::query()->create(['invoice_id' => 501, 'mark' => '400001234560501', 'code' => 'ΤΙΜ501']);
        $this->assertSame(__('invoices.refund_undo.ekdosi'), $this->refused(fn () => (new UndoRefund)->handle($this->payment, $refund->id)));
        $this->table()->callTableAction('undoRefund', $this->payment, data: ['refund' => $refund->id])->assertNotified(__('invoices.refund_undo.refused'));
        $document->delete();

        DB::table('invoice_items')->insert(['invoice_id' => 501, 'description' => 'Credit', 'quantity' => 1, 'price' => 0, 'reference_type' => Credit::class, 'reference_id' => 1]);
        $this->assertSame(__('invoices.refund_undo.credit_purchase'), $this->refused(fn () => (new UndoRefund)->handle($this->payment, $refund->id)));
        DB::table('invoice_items')->where('invoice_id', 501)->where('reference_type', Credit::class)->delete();

        DB::table('invoices')->where('id', 501)->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertSame(__('invoices.refund_undo.cancelled'), $this->refused(fn () => (new UndoRefund)->handle($this->payment, $refund->id)));
        DB::table('invoices')->where('id', 501)->update(['status' => Invoice::STATUS_PAID]);

        $this->assertSame('3.00', (string) $this->payment->fresh()->refunded_amount, 'refused whole each time');
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state());
        $this->assertMoneyClean();
    }

    public function test_a_part_refund_made_here_on_an_invoice_paid_in_whmcs_is_undone_and_gives_no_period(): void
    {
        $this->imported(480);
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        ExtensionHelper::refundLocally($old, 3.00, notify: false);
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state(480));

        $this->table(480)->callTableAction('undoRefund', $old, data: ['refund' => $this->refund($old)->id])->assertHasNoTableActionErrors();

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480));
        $this->assertSame(1000, $this->journalNet(480));
        $this->assertPeriodUntouched();
        $this->imported(480); // its line still as the importer brought it: its period, applied
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
    }

    public function test_an_undo_pays_an_invoice_paid_in_whmcs_again_and_renews_nothing_and_kept_none_of_a_refund_whmcs_made(): void
    {
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        // Refunded here in full: its line came with its period (2026-08-10 to 09-10), applied (WhmcsLinePeriods). Paid
        // again by the undo, it is a replay as a native line's: no second period for service 31 out of the same 10.00.
        // The refusal «would renew» stays for a line that came with no period (ImportLinePeriodsTest).
        ExtensionHelper::refundLocally($old, 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->state(480)[0]);

        (new UndoRefund)->handle($old, $this->refund($old)->id);

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480), 'paid again, as before the refund');
        $this->assertSame(AdjustmentNoteStatus::Voided, AdjustmentNote::query()->where('invoice_id', 480)->where('is_refund', true)->sole()->status);
        $this->assertNull(InvoiceItem::query()->where('invoice_id', 480)->sole()->forgone_at, 'its period is its own again');
        $this->assertPeriodUntouched();
        $this->assertMoneyClean();

        // One WHMCS made (the importer's own event): its documents were issued there.
        $older = InvoiceTransaction::query()->where('invoice_id', 501)->sole();
        $event = MoneyEvent::query()->create(['kind' => MoneyEventKind::Refund, 'amount' => '2.00', 'currency_code' => 'EUR', 'client_id' => $this->user->client()->id,
            'invoice_id' => 501, 'invoice_transaction_id' => $older->id, 'source' => MoneyTrail::SOURCE_IMPORT, 'occurred_at' => now()]);
        DB::table('invoice_transactions')->where('id', $older->id)->update(['refunded_amount' => '2.00']);

        $this->assertSame(__('invoices.refund_undo.before_cut_over'), $this->refused(fn () => (new UndoRefund)->handle($older, $event->id)));
        $this->assertSame('2.00', (string) $older->fresh()->refunded_amount);
    }

    public function test_a_refund_of_an_open_invoice_wrote_no_note_and_its_undo_covers_it_again(): void
    {
        // Part paid, part of that refunded while open: the balance grew back, no note. Then the rest is paid.
        $part = (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '6.00', 'tr_502a');
        ExtensionHelper::refundLocally($part, 6.00, notify: false);
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(502));
        $this->assertSame(0, AdjustmentNote::query()->where('invoice_id', 502)->count());
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '4.00', 'tr_502b');

        (new UndoRefund)->handle($part, $this->refund($part)->id);

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'covered again: paid');
        $this->assertSame(1000, $this->journalNet(502));
        $this->assertUnusedBounded(502, 32);
        $this->assertMoneyClean();
    }
}

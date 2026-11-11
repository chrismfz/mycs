<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on payments (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-15, docs/staff-freedom.md): «Edit» on a
 * recorded payment. What moves no money is corrected in place; its amount or its invoice is «delete + record again» by
 * the payments service. On invoices the WHMCS importer brought with its own steps: #480 paid there (frozen), #501 unpaid
 * and paid here (service 31 runs to 2026-10-10), #502 unpaid (service 32).
 */
class StaffFreedomEditPaymentTest extends TestCase
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
        $this->imported(502);
        // Paid here, after the cut-over: its line takes its period (to 2026-10-10) and is applied, as any payment does.
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

    /** Σ payment − Σ refund of an invoice's journal, reversals the other way: the money the journal says it took. */
    private function journalNet(int $invoice): int
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

    /** Whatever was edited, the days left of service 31 are never worth more than the money its invoice keeps. */
    private function assertUnusedBounded(int $invoice = 501, int $service = 31): void
    {
        $unused = RecordPaymentService::toCents(ChangeCycle::unused(Service::query()->findOrFail($service), CarbonImmutable::today()));
        $this->assertLessThanOrEqual(Invoice::query()->findOrFail($invoice)->moneyKeptCents(), $unused, 'unused days are worth more than the money kept');
    }

    private function assertPeriodUntouched(): void
    {
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the period given stays given, nothing renewed again');
    }

    private function refused(\Closure $edit): string
    {
        try {
            $edit();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }
        $this->fail('The edit was not refused.');
    }

    private function table(int $invoice = 501)
    {
        return $this->livewireAs($this->staff)
            ->test(TransactionsRelationManager::class, ['ownerRecord' => Invoice::query()->findOrFail($invoice), 'pageClass' => EditInvoice::class]);
    }

    public function test_staff_correct_the_date_gateway_id_and_fee_in_place_and_the_paid_date_follows(): void
    {
        $bank = Gateway::query()->create(['name' => 'Bank', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $events = MoneyEvent::query()->count();
        config(['audit.console' => true]);
        $this->assertSame('2026-09-20', Invoice::query()->findOrFail(501)->paid_at->toDateString());

        $this->table()->callTableAction('editPayment', $this->payment, data: [
            'paid_at' => '2026-09-18', 'gateway_id' => $bank->id, 'transaction_id' => 'wire-501', 'fee' => '0.35',
        ])->assertHasNoTableActionErrors()->assertNotified(__('invoices.payment_edit.saved'));

        $payment = $this->payment->fresh();
        $this->assertNotNull($payment, 'the same row: nothing was deleted');
        $this->assertSame(['2026-09-18', $bank->id, 'wire-501', '0.35', '10.00'],
            [$payment->created_at->toDateString(), $payment->gateway_id, $payment->transaction_id, (string) $payment->fee, (string) $payment->amount]);
        $this->assertSame('2026-09-18', Invoice::query()->findOrFail(501)->paid_at->toDateString(), 'the invoice was paid the day its payment says');
        $this->assertSame($events, MoneyEvent::query()->count(), 'no money moved: the journal has nothing new');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame(1000, $this->journalNet(501));
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched();
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
        // Who, before → after: the row's audit, and the date by name (timestamps are not in the model's audit).
        $audits = $payment->audits()->get();
        $this->assertTrue($audits->contains(fn ($audit) => ($audit->new_values['transaction_id'] ?? null) === 'wire-501' && ($audit->old_values['transaction_id'] ?? null) === 'tr_501'));
        $this->assertTrue($audits->contains(fn ($audit) => $audit->event === 'payment_date_changed' && str_starts_with((string) ($audit->new_values['paid_at'] ?? ''), '2026-09-18')));
        $this->assertSame($this->staff->id, (int) $audits->last()->user_id);
    }

    public function test_a_transaction_id_another_payment_has_is_refused(): void
    {
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '3.00', 'wire-x');

        $this->table()->callTableAction('editPayment', $this->payment, data: ['transaction_id' => 'wire-x'])
            ->assertNotified(__('invoices.payment_edit.refused'));

        $this->assertSame('tr_501', $this->payment->fresh()->transaction_id);
        $this->assertStringContainsString('#502', $this->refused(fn () => (new EditPayment)->handle($this->payment, ['transaction_id' => 'wire-x'])));
        // The same id on another customer's payment of the same gateway account is the same money at the gateway.
        $stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $other = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $other->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        ExtensionHelper::addPayment($other->id, 'Stripe', 10.00, null, 'ch_other');
        $this->assertStringContainsString('#' . $other->id, $this->refused(fn () => (new EditPayment)->handle($this->payment, ['transaction_id' => 'ch_other', 'gateway_id' => $stripe->id])));
        $this->assertStringContainsString('#' . $other->id, $this->refused(fn () => (new EditPayment)->handle($this->payment, ['transaction_id' => 'ch_other'])), 'typed with no gateway too: it is the provider\'s id');
        // What staff typed for another customer («cash») is no twin.
        $cash = Invoice::factory()->create(['client_id' => $other->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $cash->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00']);
        (new RecordPaymentService)->recordByStaff($cash, null, '10.00', 'cash');
        (new EditPayment)->handle($this->payment, ['transaction_id' => 'cash']);
        $this->assertSame('cash', $this->payment->fresh()->transaction_id);
        $this->assertMoneyClean();
    }

    public function test_a_lower_amount_is_delete_and_record_again_the_invoice_opens_and_a_higher_one_pays_it_once(): void
    {
        $this->table()->callTableAction('editPayment', $this->payment, data: ['amount' => '6.00'])->assertHasNoTableActionErrors();

        $this->assertNull($this->payment->fresh(), 'deleted and recorded again: a new row');
        $payment = InvoiceTransaction::query()->where('invoice_id', 501)->sole();
        $this->assertSame(['6.00', 'tr_501', '2026-09-20'], [(string) $payment->amount, $payment->transaction_id, $payment->created_at->toDateString()]);
        $this->assertSame([Invoice::STATUS_UNPAID, 400, 600], $this->state(501), 'no longer covered: open again, as after a deletion');
        $this->assertSame(600, $this->journalNet(501), 'the 10.00 is reversed, the 6.00 journaled');
        $this->assertSame(1, MoneyEvent::query()->where('invoice_id', 501)->where('kind', MoneyEventKind::Reversal->value)->count());
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched();
        $this->assertUnusedBounded();
        $this->assertMoneyClean(['service_unbilled']); // service 31 runs on a month invoice #501 no longer has the money for

        (new EditPayment)->handle($payment, ['amount' => '10.00']);

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame(1000, $this->journalNet(501));
        $this->assertPeriodUntouched(); // paid again gives no second period
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
    }

    public function test_a_higher_amount_leaves_credit_and_taking_it_back_follows_the_deletions_rule_and_tick(): void
    {
        $payment = (new EditPayment)->handle($this->payment, ['amount' => '15.00']);

        $this->assertSame('5.00', (string) $payment->credited_amount, 'what the invoice cannot take is credit, as for any payment');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        // The credit pays the customer's open invoice at once (auto-apply, after the commit): it is spent.
        $this->assertSame('0.00', $this->credit());
        $this->assertSame([Invoice::STATUS_UNPAID, 500, 500], $this->state(502));
        $this->assertMoneyClean();

        // The amount is then put right.
        $message = $this->refused(fn () => (new EditPayment)->handle($payment, ['amount' => '10.00']));
        $this->assertSame(__('Part of this payment became account credit and the customer has already used it: delete it on its own and confirm that their credit goes negative (they owe it).'), $message);
        $this->assertSame('15.00', (string) $payment->fresh()->amount, 'refused whole: nothing deleted');

        (new EditPayment)->handle($payment, ['amount' => '10.00'], allowNegativeCredit: true);

        $this->assertSame('-5.00', $this->credit(), 'with the tick: they owe it');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame(1000, $this->journalNet(501));
        $this->assertPeriodUntouched();
        $this->assertUnusedBounded();
        $this->assertMoneyClean(['credit_negative']);
    }

    public function test_a_payment_is_moved_to_another_invoice_of_the_customer(): void
    {
        $this->livewireAs($this->staff)->test(ListInvoiceTransactions::class)
            ->callTableAction('editPayment', $this->payment, data: ['invoice_id' => 502])->assertHasNoTableActionErrors();

        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(501), 'the invoice it paid is open again');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502), 'the target is paid');
        $this->assertSame([0, 1000], [$this->journalNet(501), $this->journalNet(502)]);
        $moved = InvoiceTransaction::query()->where('invoice_id', 502)->sole();
        $this->assertSame(['tr_501', '2026-09-20'], [$moved->transaction_id, $moved->created_at->toDateString()], 'its id and its day go with it');
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched(); // services already renewed stay renewed: staff decide
        $this->assertSame('0.00', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()), 'and its days are worth no credit without the money');
        $this->assertMoneyClean(['service_unbilled']); // told to staff: service 31 runs on, its invoice #501 owes again
    }

    public function test_kept_a_payment_moves_only_within_its_customer_and_currency(): void
    {
        $other = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $usd = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        foreach ([$other->id, $usd->id, 999999] as $target) {
            $this->assertSame(__('invoices.payment_edit.other_customer'), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['invoice_id' => $target])));
        }
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertNotNull($this->payment->fresh());
        $this->assertMoneyClean();
    }

    public function test_a_payment_its_gateway_recorded_keeps_its_amount_id_and_invoice_and_takes_a_date_and_fee(): void
    {
        Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $charge = ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502');
        $this->assertTrue(EditPayment::recordedByGateway($charge));
        $this->assertFalse(EditPayment::recordedByGateway($this->payment), 'what staff typed is theirs to correct');

        $this->assertSame(__('invoices.payment_edit.gateway_amount'), $this->refused(fn () => (new EditPayment)->handle($charge, ['amount' => '9.00'])));
        $this->assertSame(__('invoices.payment_edit.gateway_identity'), $this->refused(fn () => (new EditPayment)->handle($charge, ['transaction_id' => 'ch_other'])));
        $this->assertSame(__('invoices.payment_edit.gateway_identity'), $this->refused(fn () => (new EditPayment)->handle($charge, ['gateway_id' => null])));
        // Not moved either (the cut of REV-781-3): what the gateway reported on it would be left behind.
        $this->assertSame(__('invoices.payment_edit.gateway_move'), $this->refused(fn () => (new EditPayment)->handle($charge, ['invoice_id' => 501])));
        // The form does not offer them (a disabled field is not submitted), so what staff type there changes nothing.
        $this->table(502)->callTableAction('editPayment', $charge, data: ['paid_at' => '2026-09-19', 'fee' => '0.45', 'amount' => '9.00', 'invoice_id' => 501])
            ->assertHasNoTableActionErrors();

        $same = $charge->fresh();
        $this->assertSame(['10.00', 'ch_502', '0.45', '2026-09-19', 502], [(string) $same->amount, $same->transaction_id, (string) $same->fee, $same->created_at->toDateString(), $same->invoice_id]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502));
        // The webhook's replay of it records nothing.
        $this->assertSame($charge->id, ExtensionHelper::addPayment(502, 'Stripe', 10.00, null, 'ch_502')->id);
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'ch_502')->count());
        $this->assertMoneyClean();
    }

    public function test_an_id_or_gateway_corrected_in_place_takes_what_waited_for_it_and_ekdosi_keeps_money_and_dates(): void
    {
        // Issued is final: an invoice with a document, or sent to Ekdosi, keeps its payments' money and dates.
        $document = EkdosiDocument::query()->create(['invoice_id' => 501, 'mark' => '400001234560501', 'code' => 'ΤΙΜ501']);
        foreach ([['amount' => '8.00'], ['invoice_id' => 502], ['paid_at' => Carbon::parse('2026-09-19 10:00:00')]] as $change) {
            $this->assertSame(__('invoices.payment_edit.ekdosi', ['invoice' => 501]), $this->refused(fn () => (new EditPayment)->handle($this->payment, $change)));
        }
        (new EditPayment)->handle($this->payment, ['fee' => '0.10']); // what moves nothing is still corrected
        $document->delete();
        Invoice::query()->whereKey(502)->update(['ekdosi_requested_at' => now()]);
        $this->assertSame(__('invoices.payment_edit.ekdosi_request', ['invoice' => 502]), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['invoice_id' => 502])));
        Invoice::query()->whereKey(502)->update(['ekdosi_requested_at' => null]);
        Invoice::query()->whereKey(501)->update(['ekdosi_requested_at' => now()]);
        $this->assertSame(__('invoices.payment_edit.ekdosi_request', ['invoice' => 501]), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['amount' => '8.00'])));

        $this->assertSame(['10.00', '0.10', 501], [(string) $this->payment->fresh()->amount, (string) $this->payment->fresh()->fee, $this->payment->fresh()->invoice_id]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertMoneyClean();
    }

    public function test_kept_a_payment_with_a_refund_is_not_edited_as_it_is_not_deleted(): void
    {
        ExtensionHelper::refundLocally($this->payment, 3.00, notify: false);

        $message = $this->refused(fn () => (new EditPayment)->handle($this->payment, ['amount' => '12.00']));

        $this->assertSame(__('This payment has refunds: it cannot be deleted (its refund history and gateway transaction id must stay).'), $message);
        $this->assertSame($message, $this->refused(fn () => (new EditPayment)->handle($this->payment, ['invoice_id' => 502])));
        // What moves no money is still corrected.
        (new EditPayment)->handle($this->payment, ['fee' => '0.10']);
        $this->assertSame('0.10', (string) $this->payment->fresh()->fee);
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state(501));
        $this->assertUnusedBounded();
        $this->assertMoneyClean();
    }

    public function test_an_imported_payment_on_an_invoice_paid_before_the_cut_over_keeps_amount_invoice_and_date(): void
    {
        $this->imported(480);
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        $this->assertTrue(EkdosiDocument::issuedBeforeCutOver(480));
        $paidAt = Invoice::query()->findOrFail(480)->paid_at;

        foreach ([['amount' => '8.00'], ['invoice_id' => 502]] as $change) {
            $this->assertSame(__('invoices.payment_edit.before_cut_over'), $this->refused(fn () => (new EditPayment)->handle($old, $change)));
        }
        // The date WHMCS recorded: never corrected here, whatever was recorded on the invoice since.
        $this->assertSame(__('invoices.payment_edit.date_imported'), $this->refused(fn () => (new EditPayment)->handle($old, ['paid_at' => Carbon::parse('2026-09-19 10:00:00')])));
        $this->assertSame(__('invoices.payment_edit.target_before_cut_over'), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['invoice_id' => 480])));
        $this->table(480)->callTableAction('editPayment', $old, data: ['amount' => '8.00'])->assertNotified(__('invoices.payment_edit.refused'));

        // Its id and fee write no money event: it stays what it was, paid before the cut-over.
        $this->table(480)->callTableAction('editPayment', $old, data: ['transaction_id' => 'wire-480', 'fee' => '0.20'])->assertHasNoTableActionErrors();

        $this->assertSame(['wire-480', '0.20', '10.00'], [$old->fresh()->transaction_id, (string) $old->fresh()->fee, (string) $old->fresh()->amount]);
        $this->assertTrue(EkdosiDocument::issuedBeforeCutOver(480), 'still frozen');
        $this->assertEquals($paidAt, Invoice::query()->findOrFail(480)->paid_at, 'its paid date, by which Ekdosi takes invoices, did not move');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480));
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertPeriodUntouched();
        $this->assertMoneyClean();
    }

    /**
     * The importer gave #480's line its period (2026-08-10 to 09-10) and marked it applied (WhmcsLinePeriods): opened and
     * paid again by an edit, it is a replay as a native line's, and renews nothing. The refusal «would renew» stays for a
     * line that came with no period (ImportLinePeriodsTest).
     */
    public function test_an_invoice_paid_in_whmcs_opened_and_paid_again_by_an_edit_renews_nothing_once_money_was_recorded_on_it_here(): void
    {
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        // A payment recorded on it here (it is paid: the money is credit) makes it no longer «all the importer's».
        (new RecordPaymentService)->handle(480, null, '2.00', transactionId: 'extra', enteredByStaff: true);
        $this->assertFalse(EkdosiDocument::issuedBeforeCutOver(480));
        $this->assertFalse(EditPayment::paidAgainWouldRenew(Invoice::query()->findOrFail(480)), 'its line is applied: a payment gives it nothing again');

        (new EditPayment)->handle($old, ['amount' => '10.01']);

        // The exact state: paid, nothing left, the 10.00 it bills kept on it; the cent over went to credit, as the 2.00.
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480));
        $new = InvoiceTransaction::query()->where('invoice_id', 480)->where('transaction_id', 'tr_480')->sole();
        $this->assertSame(['10.01', '0.01'], [(string) $new->amount, (string) $new->credited_amount]);
        $this->assertPeriodUntouched();
        $this->assertMoneyClean();
    }

    public function test_kept_a_credit_payment_and_one_that_did_not_arrive_are_not_edited(): void
    {
        (new RecordPaymentService)->handle(501, null, '4.00', transactionId: 'over', enteredByStaff: true); // paid already: credit, which pays 502
        $byCredit = InvoiceTransaction::query()->where('invoice_id', 502)->where('is_credit_transaction', true)->sole();
        $processing = ExtensionHelper::addProcessingPayment(502, null, 6.00, null, 'sepa-1');

        $this->assertSame(__('invoices.payment_edit.credit_payment'), $this->refused(fn () => (new EditPayment)->handle($byCredit, ['amount' => '1.00'])));
        $this->assertSame(__('invoices.payment_edit.not_succeeded'), $this->refused(fn () => (new EditPayment)->handle($processing, ['transaction_id' => 'sepa-2'])));
        $this->assertSame(__('The amount must be positive.'), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['amount' => '0'])));
        $this->table(502)->assertTableActionHidden('editPayment', $byCredit)->assertTableActionHidden('editPayment', $processing);
        $this->assertSame(InvoiceTransactionStatus::Processing, $processing->fresh()->status);
        $this->assertMoneyClean();
    }

    public function test_only_staff_who_may_delete_payments_edit_them(): void
    {
        $clerk = Staff::factory()->create(['role_id' => Role::create(['name' => 'Clerk', 'permissions' => [
            'admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update', 'admin.invoice_transactions.viewAny', 'admin.invoice_transactions.view', 'admin.invoice_transactions.update',
        ]])->id]);

        $this->livewireAs($clerk)
            ->test(TransactionsRelationManager::class, ['ownerRecord' => Invoice::query()->findOrFail(501), 'pageClass' => EditInvoice::class])
            ->assertTableActionHidden('editPayment', $this->payment);
        $this->table()->assertTableActionVisible('editPayment', $this->payment);

        // May delete but not record payments: what moves nothing is theirs to correct; an amount or another invoice is not.
        $deleter = Staff::factory()->create(['role_id' => Role::create(['name' => 'Deleter', 'permissions' => [
            'admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update', 'admin.invoice_transactions.viewAny', 'admin.invoice_transactions.view', 'admin.invoice_transactions.delete',
        ]])->id]);
        $this->actingAs($deleter);
        $this->assertSame(__('invoices.payment_edit.may_not_record'), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['amount' => '11.00'])));
        $this->assertSame(__('invoices.payment_edit.may_not_record'), $this->refused(fn () => (new EditPayment)->handle($this->payment, ['invoice_id' => 502])));
        (new EditPayment)->handle($this->payment, ['fee' => '0.20']);
        $this->assertSame(['10.00', '0.20'], [(string) $this->payment->fresh()->amount, (string) $this->payment->fresh()->fee]);
    }
}

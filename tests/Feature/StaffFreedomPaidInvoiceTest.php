<?php

namespace Tests\Feature;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
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
 * Staff freedom on invoices, item 1 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-1, docs/staff-freedom.md): lines
 * and staff notes on a PAID invoice change; it stays paid and says its balance. On an invoice the WHMCS importer brought
 * unpaid (a «Hosting» line with no period, never applied) and its payment recorded here: the service runs to 2026-10-10.
 * One the importer brought already PAID keeps its lines (REV-780-7): its document was issued before the cut-over.
 */
class StaffFreedomPaidInvoiceTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $invoice;

    private Staff $staff;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-09-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-08-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->imported(501);
        // Paid here, after the cut-over: its line takes its period (to 2026-10-10) and is applied, as any payment does.
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(501), null, '10.00', 'tr_501');
        $this->invoice = Invoice::query()->findOrFail(501);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'f', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.invoice_items.update', 'admin.invoice_items.delete', 'admin.invoice_items.create']]);

        return $token;
    }

    /** @return array{0: string, 1: int, 2: int} status, total and what is left, in cents */
    private function state(): array
    {
        $fresh = $this->invoice->fresh();

        return [$fresh->status, RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)];
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->invoice->client_id)->value('amount') ?? 0), 2, '.', '');
    }

    /** The service's paid-until date and whether the imported line was applied: what no edit of a paid line may move. */
    private function assertPeriodUntouched(): void
    {
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the period given stays given, nothing renewed again');
        $this->assertSame(Service::STATUS_ACTIVE, Service::query()->findOrFail(31)->status);
    }

    public function test_staff_raise_a_line_of_a_paid_imported_invoice_it_stays_paid_and_owes_and_a_payment_settles_it(): void
    {
        $this->assertSame([Invoice::STATUS_PAID, 1000, 0], $this->state(), 'precondition: paid, balanced');
        $applied = $this->invoice->items()->sole()->applied_at;
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);
        $line = array_key_first($page->get('data.items'));

        $page->assertFormFieldIsEnabled("items.{$line}.price")
            ->assertSee(__('This invoice is paid. A change to its lines leaves it paid, with a balance to settle (shown here). The periods it already gave stay given: nothing is renewed or taken back.'))
            ->set("data.items.{$line}.price", '12.00')->call('save')->assertNotified(__('Check its balance'));

        $this->assertSame([Invoice::STATUS_PAID, 1200, 200], $this->state(), 'still paid, and it owes 2.00');
        $this->assertStringContainsString('2.00 EUR', (string) StaffLineEdit::balanceNotice($this->invoice->id));
        $this->assertStringContainsString('stay given', (string) StaffLineEdit::balanceNotice($this->invoice->id), 'what it does to the service is said');
        $this->assertPeriodUntouched();
        $this->assertEquals($applied, $this->invoice->items()->sole()->applied_at, 'the line is not applied again by an edit');
        $this->assertSame(['paid_balance'], array_column((new MoneyCheck)->run(['paid_balance', 'open_settled']), 'rule'), 'the daily check names it');

        // The existing way to settle it: record the payment on it. It lands on the invoice, not as credit.
        $payment = (new RecordPaymentService)->handle($this->invoice->fresh(), null, '2.00', transactionId: 'wire-extra', enteredByStaff: true);

        $this->assertSame([Invoice::STATUS_PAID, 1200, 0], $this->state());
        $this->assertSame('0.00', (string) $payment->credited_amount, 'the 2.00 pays the invoice');
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched();
        $this->assertSame([], (new MoneyCheck)->run(['paid_balance', 'open_settled', 'payment_not_journaled', 'credit_journal']));
    }

    public function test_a_payment_on_a_balanced_paid_invoice_still_becomes_credit(): void
    {
        $payment = (new RecordPaymentService)->handle($this->invoice, null, '5.00', transactionId: 'wire-twice', enteredByStaff: true);

        $this->assertSame('5.00', (string) $payment->credited_amount, 'kept: a paid invoice with nothing owed takes no money');
        $this->assertSame('5.00', $this->credit());
        $this->assertSame([Invoice::STATUS_PAID, 1000, 0], $this->state());
    }

    public function test_staff_lower_a_line_of_a_paid_invoice_and_close_as_paid_gives_the_part_over_as_credit(): void
    {
        $line = $this->invoice->items()->sole();

        $response = $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '8.00'])->assertSuccessful();

        $this->assertStringContainsString('2.00 EUR', (string) $response->json('warning'));
        $this->assertSame([Invoice::STATUS_PAID, 800, -200], $this->state(), 'still paid, 2.00 over');
        $this->assertSame('0.00', $this->credit(), 'nothing became credit on its own');
        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()])
            ->assertActionVisible('closeAsPaid')->callAction('closeAsPaid')->assertHasNoActionErrors();

        $this->assertSame([Invoice::STATUS_PAID, 800, 0], $this->state());
        $this->assertSame('2.00', $this->credit(), 'the part over is the customer\'s, once');
        $this->assertFalse((new RecordPaymentService)->settle($this->invoice->id), 'a second click gives nothing more');
        $this->assertSame('2.00', $this->credit());
        $this->assertPeriodUntouched();
        $this->assertSame([], (new MoneyCheck)->run(['paid_balance', 'open_settled', 'credit_journal']));
    }

    public function test_a_line_is_added_to_and_taken_off_a_paid_invoice(): void
    {
        $token = $this->token();

        $added = $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $this->invoice->id, 'description' => 'Extra IP', 'price' => '3.00', 'quantity' => 1, 'taxed' => false,
        ])->assertSuccessful();
        $this->assertStringContainsString('3.00 EUR', (string) $added->json('warning'));
        $this->assertSame([Invoice::STATUS_PAID, 1300, 300], $this->state());

        $extra = $this->invoice->items()->whereNull('reference_type')->sole();
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $extra))->assertNoContent();
        $this->assertSame([Invoice::STATUS_PAID, 1000, 0], $this->state());

        // Kept (REV-780-1c): the line that gave the service its period is not removed from a paid invoice; its price is
        // set to 0.00 instead. The same money is over, and the period stays given, paid for nothing.
        $hosting = $this->invoice->items()->where('reference_type', Service::class)->sole();
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $hosting))->assertStatus(422);
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $hosting), ['price' => '0.00'])->assertSuccessful();

        $this->assertSame([Invoice::STATUS_PAID, 0, -1000], $this->state(), 'the renewal bills nothing: 10.00 over, still paid');
        $this->assertPeriodUntouched();
        $this->assertSame('0.00', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()),
            'and its days left are worth nothing as credit');
    }

    /** Kept (REV-780-7, «issued is final»): an invoice the importer brought PAID had its document issued before the cut-over. */
    public function test_kept_an_invoice_paid_before_the_cut_over_keeps_its_lines_and_notes(): void
    {
        $old = $this->imported(480);
        $this->assertTrue(EkdosiDocument::issuedBeforeCutOver($old->id));
        $this->assertFalse(EkdosiDocument::issuedBeforeCutOver($this->invoice->id), 'one paid here is not');
        $line = $old->items()->sole();
        $token = $this->token();

        $response = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '8.00'])->assertStatus(422);
        $this->assertStringContainsString('before the cut-over', (string) $response->json('message'));
        $this->assertStringContainsString('Ekdosi', (string) $response->json('message'));
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $old->id, 'description' => 'Extra', 'price' => '3.00', 'quantity' => 1,
        ])->assertStatus(422);
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $old->getKey()]);
        $page->assertFormFieldIsDisabled('items.' . array_key_first($page->get('data.items')) . '.price')->assertSee(EkdosiDocument::cutOverRefusal());
        try {
            $old->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -3, 'description' => 'Late']);
            $this->fail('a staff note was written on an invoice paid before the cut-over');
        } catch (DisplayException $e) {
            $this->assertSame(EkdosiDocument::cutOverRefusal(), $e->getMessage());
        }

        $this->assertSame(['10.00', 1], [(string) $line->fresh()->price, $old->items()->count()]);
        // A description still changes, as on any frozen invoice.
        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['description' => 'Hosting, renamed'])->assertSuccessful();
    }

    public function test_staff_write_and_void_a_note_on_a_paid_invoice(): void
    {
        $notes = $this->livewireAs($this->staff)->test(AdjustmentNotesRelationManager::class, ['ownerRecord' => $this->invoice, 'pageClass' => EditInvoice::class]);

        $notes->assertTableActionVisible('create')
            ->callTableAction('create', data: ['amount' => '-3.00', 'type' => 'credit', 'description' => 'Goodwill'])->assertHasNoTableActionErrors()
            ->assertNotified(__('Check its balance'));

        $note = $this->invoice->adjustmentNotes()->sole();
        $this->assertSame([Invoice::STATUS_PAID, 700, -300], $this->state(), 'paid, 3.00 over');
        $this->assertTrue(VoidAdjustmentNote::allowed($note));

        $notes->callTableAction('void', $note)->assertHasNoTableActionErrors();

        $this->assertSame(AdjustmentNoteStatus::Voided, $note->fresh()->status);
        $this->assertSame([Invoice::STATUS_PAID, 1000, 0], $this->state());
        $this->assertSame('0.00', $this->credit());
        $this->assertPeriodUntouched();
    }

    /** Kept (owner 2026-11-10): a refunded invoice is final, its lines and notes stay. */
    public function test_kept_a_refunded_invoice_keeps_its_lines_and_notes(): void
    {
        ExtensionHelper::refundLocally($this->invoice->transactions()->sole(), 10.0, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->invoice->fresh()->status, 'precondition: all its money went back');
        $line = $this->invoice->items()->sole();
        $token = $this->token();

        $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '8.00'])->assertStatus(422);
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line))->assertStatus(422);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $this->invoice->id, 'description' => 'Extra', 'price' => '3.00', 'quantity' => 1,
        ])->assertStatus(422);
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);
        $page->assertFormFieldIsDisabled('items.' . array_key_first($page->get('data.items')) . '.price')
            ->assertSee(__('A refunded invoice keeps its lines: bill the customer on a new invoice.'));
        try {
            $this->invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -3, 'description' => 'Late']);
            $this->fail('a staff note was written on a refunded invoice');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('refunded', $e->getMessage());
        }
        $this->livewireAs($this->staff)->test(AdjustmentNotesRelationManager::class, ['ownerRecord' => $this->invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertTableActionHidden('create');

        $this->assertSame('10.00', (string) $line->fresh()->price);
        $this->assertSame(1, $this->invoice->items()->count());
    }

    /** Kept: issued is final. A paid invoice Ekdosi issued its document for keeps its lines and its total. */
    public function test_kept_a_paid_invoice_with_a_fiscal_document_from_ekdosi_stays_frozen(): void
    {
        EkdosiDocument::query()->create(['invoice_id' => $this->invoice->id, 'mark' => '400001234500002', 'code' => 'ΤΠΥ8', 'state' => EkdosiDocument::ACTIVE]);
        $line = $this->invoice->items()->sole();

        $this->withToken($this->token())->putJson(route('api.v1.admin.invoice-items.update', $line), ['price' => '8.00'])->assertStatus(422);
        $page = $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()]);
        $page->assertFormFieldIsDisabled('items.' . array_key_first($page->get('data.items')) . '.price');
        try {
            $this->invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -3, 'description' => 'Goodwill']);
            $this->fail('a staff note was written on an invoice with a fiscal document');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Ekdosi', $e->getMessage());
        }

        $this->assertSame([Invoice::STATUS_PAID, 1000, 0], $this->state());
    }

    /** The customer never sees a paid invoice silently owe (or hold) money: the page and the PDF say the balance. */
    public function test_the_customer_page_and_the_pdf_say_what_a_paid_invoice_owes_or_holds_over(): void
    {
        $balanced = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $this->invoice->fresh()])->assertOk();
        $balanced->assertDontSee(__('invoices.paid_owes'))->assertDontSee(__('invoices.paid_over'));

        StaffLineEdit::run($this->invoice->id, true, fn () => $this->invoice->items()->sole()->update(['price' => '12.00']));
        $owes = $this->invoice->fresh();
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $owes])->assertOk()
            ->assertSee(__('invoices.paid_owes'))->assertSeeHtml('data-paid-balance')->assertSee($owes->formattedTotal->format(2.0));
        $pdf = view('pdf.invoice', ['invoice' => $owes])->render();
        $this->assertMatchesRegularExpression('/' . preg_quote(__('invoices.balance'), '/') . '<\/td>\s*<td class="num">[^<]*2[.,]00/u', $pdf, 'the PDF prints the balance it owes');

        StaffLineEdit::run($this->invoice->id, true, fn () => $this->invoice->items()->sole()->update(['price' => '7.50']));
        $over = $this->invoice->fresh();
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $over])->assertOk()
            ->assertSee(__('invoices.paid_over'))->assertSee($over->formattedTotal->format(2.5));
        $this->assertMatchesRegularExpression('/' . preg_quote(__('invoices.balance'), '/') . '<\/td>\s*<td class="num">[^<]*-[^<]*2[.,]50/u',
            view('pdf.invoice', ['invoice' => $over])->render());
        $this->assertNotSame(__('invoices.paid_owes', [], 'en'), __('invoices.paid_owes', [], 'el'), 'said in Greek too');
    }

    public function test_the_admin_page_says_the_balance_of_a_paid_invoice(): void
    {
        StaffLineEdit::run($this->invoice->id, true, fn () => $this->invoice->items()->sole()->update(['price' => '12.00']));

        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->invoice->getKey()])
            ->assertSeeHtml('data-paid-balance')->assertSee('Add payment')->assertActionHidden('closeAsPaid');
    }
}

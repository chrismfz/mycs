<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\AdjustmentNote;
use App\Models\Audit;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 4 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-22, docs/staff-freedom.md): staff
 * edit the description and amount of an adjustment note they wrote, and delete one; each change is in the note's audit,
 * as a void is. On an unpaid invoice the WHMCS importer brought (a «Hosting» line, no period).
 */
class StaffFreedomNotesTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $invoice;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        Config::set('audit.console', true); // the tests run in the console: audited as a request is
        config(['settings.credits_enabled' => true]);
        $client = User::factory()->create()->client();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($client->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->importWhmcs();
        $this->invoice = $this->imported(502);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function note(float $amount = -3.0, string $description = 'Goodwill', ?Invoice $invoice = null): AdjustmentNote
    {
        return ($invoice ?? $this->invoice)->adjustmentNotes()->create([
            'type' => $amount < 0 ? AdjustmentNoteType::Credit->value : AdjustmentNoteType::Debit->value, 'amount' => $amount, 'description' => $description,
        ]);
    }

    private function notes(?Staff $staff = null, ?Invoice $invoice = null): mixed
    {
        return $this->livewireAs($staff ?? $this->staff)->test(AdjustmentNotesRelationManager::class, ['ownerRecord' => ($invoice ?? $this->invoice)->fresh(), 'pageClass' => EditInvoice::class]);
    }

    /** @return array{0: int, 1: int} the invoice's total and what is left to pay, in cents */
    private function money(?Invoice $invoice = null): array
    {
        $fresh = ($invoice ?? $this->invoice)->fresh();

        return [RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)];
    }

    public function test_staff_edit_the_amount_and_description_of_a_note_and_the_change_is_audited(): void
    {
        $note = $this->note();
        $this->assertSame([700, 700], $this->money(), 'precondition: 10.00 less a 3.00 note');

        $this->notes()->assertTableActionVisible('edit', $note)
            ->callTableAction('edit', $note, data: ['amount' => '-4.50', 'description' => 'Goodwill, agreed by phone'])->assertHasNoTableActionErrors();

        $fresh = $note->fresh();
        $this->assertSame(['-4.50', 'Goodwill, agreed by phone', AdjustmentNoteType::Credit->value, $note->number, $this->invoice->id],
            [(string) $fresh->amount, $fresh->description, (string) $fresh->type, $fresh->number, $fresh->invoice_id], 'its number and invoice stay');
        $this->assertSame([550, 550], $this->money());
        $audit = Audit::query()->where('auditable_type', (new AdjustmentNote)->getMorphClass())->where('auditable_id', $note->id)->where('event', 'updated')->sole();
        $this->assertSame('-3.00', number_format((float) $audit->old_values['amount'], 2, '.', ''));
        $this->assertSame('-4.50', number_format((float) $audit->new_values['amount'], 2, '.', ''));
        $this->assertSame('Goodwill', $audit->old_values['description']);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'a note renews nothing');
    }

    /** A discount turned into a charge: the type follows the sign. */
    public function test_an_edit_that_changes_the_sign_changes_the_type(): void
    {
        $note = $this->note();

        $this->notes()->callTableAction('edit', $note, data: ['amount' => '2.00', 'description' => 'Late fee'])->assertHasNoTableActionErrors();

        $this->assertSame(AdjustmentNoteType::Debit->value, (string) $note->fresh()->type);
        $this->assertSame([1200, 1200], $this->money());
    }

    public function test_staff_delete_a_note_the_total_follows_and_the_deletion_is_audited(): void
    {
        $first = $this->note();
        $second = $this->note(-1.0, 'Rounding');
        $this->assertSame([600, 600], $this->money());

        $this->notes()->assertTableActionVisible('delete', $first)->callTableAction('delete', $first)->assertHasNoTableActionErrors();

        $this->assertNull(AdjustmentNote::query()->find($first->id));
        $this->assertSame([900, 900], $this->money(), 'only the other note is left');
        $deleted = Audit::query()->where('auditable_type', (new AdjustmentNote)->getMorphClass())->where('auditable_id', $first->id)->where('event', 'deleted')->sole();
        $this->assertSame('Goodwill', $deleted->old_values['description']);
        // A gap in the numbers is fine: the next note does not take the deleted one's.
        $third = $this->note(-0.5, 'Another');
        $this->assertNotSame($first->number, $third->number);
        $this->assertNotSame($second->number, $third->number);
    }

    /** On a paid invoice too (item 1): it stays paid, and is said to owe the note that went. */
    public function test_a_note_deleted_from_a_paid_invoice_leaves_it_paid_and_said_to_owe(): void
    {
        $note = $this->note();
        (new RecordPaymentService)->recordByStaff($this->invoice->fresh(), null, '7.00', 'wire-7');
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'precondition: its payment renewed the service');

        $this->notes()->callTableAction('delete', $note)->assertHasNoTableActionErrors()->assertNotified(__('Check its balance'));

        $this->assertSame([1000, 300], $this->money());
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame('2026-11-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the period given stays given, once');
        $this->assertSame('0.00', number_format((float) (Credit::query()->where('client_id', $this->invoice->client_id)->value('amount') ?? 0), 2, '.', ''));
    }

    /** Kept: a refund's and a cancellation's notes follow their own events. */
    public function test_kept_a_refunds_and_a_cancellations_note_are_not_edited_or_deleted(): void
    {
        $this->invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Duplicate']);
        $cancellation = $this->invoice->adjustmentNotes()->where('is_cancellation', true)->sole();
        $paid = Invoice::query()->findOrFail(480);
        ExtensionHelper::refundLocally($paid->transactions()->sole(), 4.0, notify: false);
        $refund = $paid->adjustmentNotes()->where('is_refund', true)->sole();

        foreach ([[$cancellation, $this->invoice], [$refund, $paid]] as [$note, $invoice]) {
            $this->notes(invoice: $invoice)->assertTableActionHidden('edit', $note)->assertTableActionHidden('delete', $note);
            foreach ([fn () => $note->fresh()->update(['amount' => -1]), fn () => $note->fresh()->update(['description' => 'x']), fn () => $note->fresh()->delete()] as $change) {
                try {
                    $change();
                    $this->fail('a note that follows a refund or a cancellation was changed by hand');
                } catch (DisplayException $e) {
                    $this->assertStringContainsString('follows a refund or a cancellation', $e->getMessage());
                }
            }
            $this->assertNotNull($note->fresh());
        }
        $this->assertSame('-4.00', (string) $refund->fresh()->amount);
    }

    /** Kept: a refunded invoice is final, and one with a fiscal document from Ekdosi keeps its total (issued is final). */
    public function test_kept_notes_of_a_refunded_invoice_and_of_one_with_a_fiscal_document_stay(): void
    {
        $documented = $this->note();
        EkdosiDocument::query()->create(['invoice_id' => $this->invoice->id, 'mark' => '400001234500003', 'code' => 'ΤΠΥ7', 'state' => EkdosiDocument::ACTIVE]);
        $paid = Invoice::query()->findOrFail(480);
        $onRefunded = AdjustmentNote::withoutEvents(fn () => $this->note(-1.0, 'Old', $paid)); // written while it was open
        ExtensionHelper::refundLocally($paid->transactions()->sole(), 10.0, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $paid->fresh()->status, 'precondition: all its money went back');

        foreach ([[$documented, $this->invoice, 'Ekdosi'], [$onRefunded, $paid, 'refunded']] as [$note, $invoice, $why]) {
            $this->notes(invoice: $invoice)->assertTableActionHidden('edit', $note)->assertTableActionHidden('delete', $note);
            foreach ([fn () => $note->fresh()->update(['amount' => -2]), fn () => $note->fresh()->update(['description' => 'x']), fn () => $note->fresh()->delete()] as $change) {
                try {
                    $change();
                    $this->fail("a note of an invoice that is final ({$why}) was changed");
                } catch (DisplayException $e) {
                    $this->assertStringContainsString($why, $e->getMessage());
                }
            }
        }
        $this->assertSame(['-3.00', '-1.00'], [(string) $documented->fresh()->amount, (string) $onRefunded->fresh()->amount]);
    }

    /** Kept: its invoice and its number are the note's; and the permissions are asked. */
    public function test_kept_a_note_keeps_its_invoice_and_number_and_needs_the_permission(): void
    {
        $note = $this->note();
        foreach ([['invoice_id' => 480], ['number' => 'CN-9999'], ['is_refund' => true]] as $change) {
            try {
                $note->fresh()->update($change);
                $this->fail('a note changed its ' . array_key_first($change));
            } catch (DisplayException) {
            }
        }
        $viewer = Staff::factory()->create(['role_id' => Role::create(['name' => 'Viewer' . uniqid(), 'permissions' => ['admin.invoices.viewAny', 'admin.invoices.update', 'admin.adjustment_notes.viewAny']])->id]);

        $this->notes($viewer)->assertTableActionHidden('edit', $note)->assertTableActionHidden('delete', $note);

        $this->assertSame([$this->invoice->id, '-3.00'], [$note->fresh()->invoice_id, (string) $note->fresh()->amount]);
    }

    /** Kept: a credit purchase takes no discount, by an edit either (it could never become paid, S6-MONEY-LEDGER-1). */
    public function test_kept_a_debit_note_on_a_credit_purchase_is_not_edited_into_a_discount(): void
    {
        $funds = Invoice::query()->create(['client_id' => $this->invoice->client_id, 'currency_code' => 'EUR', 'due_at' => now()->addWeek(), 'status' => Invoice::STATUS_UNPAID]);
        $funds->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => '50.00', 'taxed' => false, 'reference_type' => Credit::class]);
        $fee = $this->note(2.0, 'Bank fee', $funds);

        try {
            $fee->fresh()->update(['amount' => -5, 'type' => AdjustmentNoteType::Credit->value]);
            $this->fail('a discount was edited onto a credit purchase');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('buys account credit', $e->getMessage());
        }
        $this->assertSame(['2.00', AdjustmentNoteStatus::Active], [(string) $fee->fresh()->amount, $fee->fresh()->status]);
    }
}

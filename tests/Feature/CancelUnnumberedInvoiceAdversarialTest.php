<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of #241 (no cancellation credit note for an invoice that was never numbered). Failing tests are
 * findings; test_holds_… pin behaviour that held under attack.
 */
class CancelUnnumberedInvoiceAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array{0: mixed, 1: string}>  $settings */
    private function settings(array $settings): void
    {
        foreach ($settings as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
    }

    private function proforma(): void
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'],
            'invoice_number' => [100, 'string'], 'credit_note_number' => [7, 'string']]);
    }

    private function counter(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [100, 'string'], 'credit_note_number' => [7, 'string']]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => $status, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'Hosting']);

        return $invoice->fresh();
    }

    private function creditNoteCounter(): int
    {
        return (int) Setting::query()->where('key', 'credit_note_number')->whereNull('settingable_type')->value('value');
    }

    private function credit(Invoice $invoice): float
    {
        return (float) Credit::query()->where('client_id', $invoice->client_id)->where('currency_code', 'USD')->value('amount');
    }

    /**
     * ADV-1: the decision reads `number` from the model being saved. Invoice::save() re-reads the status under the lock
     * (a copy loaded before another request changed it), but not the number: a draft issued (numbered INV-101) by another
     * request meanwhile, then cancelled from the copy loaded before, is a numbered invoice cancelled without its credit
     * note.
     */
    public function test_a_draft_issued_meanwhile_then_cancelled_from_an_older_copy_still_gets_its_credit_note(): void
    {
        $this->counter();
        $draft = $this->invoice(Invoice::STATUS_DRAFT);
        $stale = Invoice::query()->findOrFail($draft->id); // e.g. the cancel request, loaded before the issue committed

        Invoice::query()->findOrFail($draft->id)->update(['status' => Invoice::STATUS_UNPAID]); // another request issues it
        $this->assertSame('INV-101', $draft->fresh()->number);

        $stale->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame('INV-101', $draft->fresh()->number);
        $this->assertSame(1, $draft->adjustmentNotes()->where('is_cancellation', true)->count(),
            'a numbered invoice (INV-101) was cancelled without its credit note');
    }

    /**
     * ADV-2: number = id (myip). The importer writes WHMCS's `invoicenum`, which myip leaves empty (the number is the id),
     * so an imported unpaid invoice has no `number` while it is shown and was sent as «Invoice #id» (displayTitle). The
     * same holds for an invoice whose id was taken as a number (setId, adversarial ADV-5 of #238). Cancelled, it is an
     * issued invoice of myip that gets no credit note, unlike one MyCS made.
     */
    public function test_an_issued_invoice_of_number_is_id_without_the_column_still_gets_its_credit_note(): void
    {
        $this->settings(['invoice_number_is_id' => [true, 'boolean'], 'credit_note_number' => [7, 'string']]);
        $invoice = $this->invoice(Invoice::STATUS_DRAFT);
        // As the WHMCS importer writes it (rows directly): unpaid, invoicenum empty.
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID, 'number' => null]);
        $invoice = $invoice->fresh();
        $this->assertStringContainsString((string) $invoice->id, $invoice->displayTitle());

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(1, $invoice->adjustmentNotes()->where('is_cancellation', true)->count(),
            'an issued myip invoice (number = id) was cancelled without its credit note');
    }

    public function test_holds_a_cancelled_proforma_paid_late_in_part_then_reopened_and_marked_paid(): void
    {
        $this->proforma();
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        // A part arrives late: all of it the customer's credit, the proforma untouched.
        ExtensionHelper::addPayment($invoice->id, null, 4, null, 'tx_part', currency: 'USD');
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertEqualsWithDelta(4.0, $this->credit($invoice), 0.001);
        $this->assertSame([], (new MoneyCheck)->run());

        // Staff reopen it with Mark Paid (the form's amount: what is left, no cancellation note to add).
        $this->assertEqualsWithDelta(10.0, $invoice->remaining, 0.001);
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1', reopen: true, upToRemaining: true);

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEqualsWithDelta(0.0, $invoice->remaining, 0.001);
        $this->assertSame(now()->format('Y') . '-101', $invoice->number);
        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertSame(7, $this->creditNoteCounter());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_a_proforma_cancelled_reopened_and_cancelled_again_never_spends_a_credit_note_number(): void
    {
        $this->proforma();
        $invoice = $this->invoice();

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertSame(7, $this->creditNoteCounter());
        $this->assertEqualsWithDelta(10.0, $invoice->fresh()->total, 0.001);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_holds_a_numbered_invoice_cancelled_twice_has_one_active_note(): void
    {
        $this->counter();
        $invoice = $this->invoice();

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(1, $invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)->count());
        $this->assertSame(9, $this->creditNoteCounter());
        $this->assertEqualsWithDelta(0.0, $invoice->fresh()->remaining, 0.001);
    }

    public function test_holds_a_draft_cancelled_then_issued_gets_a_note_only_once_numbered(): void
    {
        $this->counter();
        $draft = $this->invoice(Invoice::STATUS_DRAFT);

        $draft->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertSame(0, $draft->adjustmentNotes()->count());

        $draft->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // reopened as issued: numbered now
        $this->assertSame('INV-101', $draft->fresh()->number);
        $draft->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(1, $draft->adjustmentNotes()->where('is_cancellation', true)->count());
        $this->assertSame(8, $this->creditNoteCounter());
    }
}

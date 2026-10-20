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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An invoice cancelled before it was ever numbered (a proforma, or a draft, Δ2) gets no cancellation credit note: it was
 * never an invoice, there is nothing to credit, and no credit note number is spent (owner 2026-10-20). A numbered one
 * still gets its credit note. A cancelled invoice holds no money either way (InvoiceObserver), so nothing else moves.
 */
class CancelUnnumberedInvoiceTest extends TestCase
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

    public function test_a_cancelled_proforma_gets_no_credit_note_and_spends_no_number(): void
    {
        $this->proforma();
        $invoice = $this->invoice();

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertSame(7, $this->creditNoteCounter());
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_a_cancelled_draft_gets_no_credit_note(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [100, 'string'], 'credit_note_number' => [7, 'string']]);
        $draft = $this->invoice(Invoice::STATUS_DRAFT);

        $draft->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(0, $draft->adjustmentNotes()->count());
        $this->assertSame(7, $this->creditNoteCounter());
    }

    public function test_a_numbered_invoice_cancelled_still_gets_its_credit_note(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [100, 'string'], 'credit_note_number' => [7, 'string']]);
        $invoice = $this->invoice();
        $this->assertSame('INV-101', $invoice->number);

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $note = $invoice->adjustmentNotes()->sole();
        $this->assertTrue((bool) $note->is_cancellation);
        $this->assertSame('-10.00', (string) $note->amount);
        $this->assertSame(8, $this->creditNoteCounter());
    }

    public function test_a_late_payment_on_a_cancelled_proforma_becomes_credit(): void
    {
        // The money a customer still sends (a transfer, a gateway page left open) is theirs as credit; the proforma stays
        // cancelled, unnumbered, and the payment never fails after it was taken.
        $this->proforma();
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        ExtensionHelper::addPayment($invoice->id, null, 10, null, 'tx_late', currency: 'USD');

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertEqualsWithDelta(10.0, (float) Credit::query()->where('client_id', $invoice->client_id)->value('amount'), 0.001);
    }

    public function test_a_reopened_proforma_is_payable_in_full_and_numbered_when_paid(): void
    {
        $this->proforma();
        $invoice = $this->invoice();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);

        $invoice = $invoice->fresh();
        $this->assertEqualsWithDelta(10.0, $invoice->remaining, 0.001);
        $this->assertSame(0, $invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)->count());

        ExtensionHelper::addPayment($invoice->id, null, 10, null, 'tx_reopened', currency: 'USD');
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(now()->format('Y') . '-101', $invoice->number);
        $this->assertSame(7, $this->creditNoteCounter());
    }
}

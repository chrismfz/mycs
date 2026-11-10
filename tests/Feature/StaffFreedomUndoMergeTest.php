<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Staff freedom on invoices, item 5 (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-25, docs/staff-freedom.md): a
 * merge is undone: a merged-away invoice is reopened (an empty invoice), and either side is deleted. On unpaid invoices
 * the WHMCS importer brought for two services («Hosting» lines with no period, none applied).
 */
class StaffFreedomUndoMergeTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private Invoice $a;

    private Invoice $b;

    private Staff $staff;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->clientId = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->clientId, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsService(32, '2026-10-12');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0, 32 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->whmcsInvoice(503, 'Unpaid', [32 => 10.0], due: '2026-10-12');
        $this->importWhmcs();
        $this->a = $this->imported(502);
        $this->b = $this->imported(503);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cents(Invoice $invoice): array
    {
        $fresh = $invoice->fresh();

        return [RecordPaymentService::toCents($fresh->total), RecordPaymentService::toCents($fresh->remaining)];
    }

    private function expires(int $service): string
    {
        return Service::query()->findOrFail($service)->expires_at->toDateString();
    }

    public function test_a_merged_away_imported_invoice_is_reopened_as_an_empty_invoice_and_the_target_keeps_the_lines(): void
    {
        (new MergeInvoices)->handle($this->a->id, [$this->b->id]);
        $this->assertSame([Invoice::STATUS_CANCELLED, $this->a->id], [$this->b->fresh()->status, $this->b->fresh()->merged_into_id]);

        $this->livewireAs($this->staff)->test(EditInvoice::class, ['record' => $this->b->getKey()])
            ->set('data.status', Invoice::STATUS_UNPAID)->call('save')->assertHasNoFormErrors()->assertNotNotified(__('Invoice not saved'));

        $reopened = $this->b->fresh();
        $this->assertSame([Invoice::STATUS_UNPAID, null], [$reopened->status, $reopened->merged_into_id], 'open again, and the «merged into» link is dropped');
        $this->assertSame([0, 0], $this->cents($this->b), 'just an empty invoice');
        $this->assertSame(0, $reopened->adjustmentNotes()->count());
        $this->assertSame([2000, 2000], $this->cents($this->a), 'the target bills both lines, once');
        $this->assertSame(1, InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 32)->whereIn('invoice_id', [$this->a->id, $this->b->id])->count());
        $this->assertSame([], (new MoneyCheck)->run(['paid_balance', 'open_settled', 'cancelled_applied', 'cancelled_holds_money']));

        // The money: paying the target renews each service once; the reopened one renewed nothing.
        (new RecordPaymentService)->recordByStaff($this->a->fresh(), null, '20.00', 'wire-20');
        $this->assertSame(Invoice::STATUS_PAID, $this->a->fresh()->status);
        $this->assertSame(['2026-11-10', '2026-11-12'], [$this->expires(31), $this->expires(32)]);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->b->fresh()->status);
    }

    public function test_either_side_of_a_merge_is_deleted(): void
    {
        (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        $this->b->fresh()->delete();
        $this->assertNull(Invoice::query()->find($this->b->id));
        $this->assertSame([2000, 2000], $this->cents($this->a), 'the merged-away one took nothing with it');

        // The other way round: the target deleted, the merged-away one no longer points at it.
        $c = Invoice::query()->create(['client_id' => $this->clientId, 'currency_code' => 'EUR', 'due_at' => '2026-10-20', 'status' => Invoice::STATUS_UNPAID]);
        $c->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => '5.00', 'taxed' => false]);
        (new MergeInvoices)->handle($c->id, [$this->a->id]);
        $c->fresh()->delete();

        $this->assertNull(Invoice::query()->find($c->id));
        $this->assertSame([Invoice::STATUS_CANCELLED, null], [$this->a->fresh()->status, $this->a->fresh()->merged_into_id]);
        $this->assertSame(0, InvoiceItem::query()->whereIn('invoice_id', [$this->a->id, $c->id])->count(), 'its lines went with the invoice they were on');
        $this->assertSame(['2026-10-10', '2026-10-12'], [$this->expires(31), $this->expires(32)], 'no period was given or taken');
    }

    /** Kept: an invoice with money on it, or with a fiscal document, or recording a period given up on, is not deleted. */
    public function test_kept_a_merge_target_with_a_payment_or_a_fiscal_document_is_not_deleted(): void
    {
        (new MergeInvoices)->handle($this->a->id, [$this->b->id]);
        (new RecordPaymentService)->handle($this->a->fresh(), null, '5.00', transactionId: 'wire-5', enteredByStaff: true);

        try {
            $this->a->fresh()->delete();
            $this->fail('an invoice with a payment was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
        }
        EkdosiDocument::query()->create(['invoice_id' => $this->b->id, 'mark' => '400001234500004', 'code' => 'ΤΠΥ6', 'state' => EkdosiDocument::CANCELLED]);
        try {
            $this->b->fresh()->delete();
            $this->fail('an invoice with a fiscal document was deleted');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Ekdosi', $e->getMessage());
        }
        $this->assertSame(2, Invoice::query()->whereKey([$this->a->id, $this->b->id])->count());
    }

    /** Kept: a payment does not reopen a merged invoice (its lines, and what is owed, are on the other one). */
    public function test_kept_a_staff_payment_does_not_reopen_a_merged_invoice(): void
    {
        (new MergeInvoices)->handle($this->a->id, [$this->b->id]);

        try {
            (new RecordPaymentService)->recordByStaff($this->b->fresh(), null, '10.00', 'wire-x', reopen: true);
            $this->fail('a payment reopened a merged invoice');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('merged into', $e->getMessage());
        }
        $this->assertSame([Invoice::STATUS_CANCELLED, 0], [$this->b->fresh()->status, $this->b->transactions()->count()]);
    }
}

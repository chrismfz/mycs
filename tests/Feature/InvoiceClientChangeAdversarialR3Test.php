<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Round-3 (final) adversarial review of #554: the closed-invoice refusal against every other path that changes an
 * invoice's customer, and what the observer path (staff's edit, the API) still lets through that MoveToClient refuses.
 * test_bug_* fail on the PR as it stands; test_holds_* are guarantees that held.
 */
class InvoiceClientChangeAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'EUR', 'settings.invoice_snapshot' => true, 'settings.tax_enabled' => true,
            'settings.tax_type' => 'exclusive', 'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'GR']);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        TaxRate::query()->create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        Gateway::query()->firstOrCreate(['extension' => 'BankTransfer'], ['name' => 'Bank Transfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function customer(string $country, string $first): Client
    {
        $user = User::factory()->create(['first_name' => $first]);
        $user->client()->primaryContact->update(['country' => $country, 'first_name' => $first]);

        return $user->client()->fresh();
    }

    private function invoice(Client $client, float $price = 100, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => $status]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /** @param array<string, mixed> $changes */
    private function save(Invoice $invoice, array $changes): Invoice
    {
        Invoice::query()->findOrFail($invoice->id)->update($changes);

        return Invoice::query()->findOrFail($invoice->id);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    // ---------------------------------------------------------------- bugs

    /**
     * ADV-554-R3-1 (Medium). An unpaid invoice with an active credit note staff wrote (a numbered document, issued while
     * it was the first customer's) moves through staff's edit or the API: the observer refuses payments, a card charge,
     * renewals and closed invoices, but not a credit note, which MoveToClient (InvoiceLineMove::refusal) refuses. The note
     * is a gross amount fixed under the first customer's VAT; retaxed for a customer abroad, the invoice now bills less
     * than its note: an open invoice of −10, money the business owes a customer who never paid anything.
     */
    public function test_bug_an_invoice_with_a_credit_note_moves_and_its_total_goes_below_zero(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni')); // 124 with Greek VAT
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -110, 'description' => 'Goodwill']);
        $this->assertSame(14.0, (float) $invoice->fresh()->total, 'precondition');
        $abroad = $this->customer('US', 'John');

        try {
            $moved = $this->save($invoice, ['client_id' => $abroad->id]);
        } catch (DisplayException) {
            $this->assertSame($invoice->client_id, Invoice::query()->whereKey($invoice->id)->value('client_id'));

            return; // refused, as MoveToClient refuses it: the fix
        }

        $this->assertGreaterThanOrEqual(0.0, (float) $moved->total, 'moved with the first customer\'s credit note, the invoice bills less than nothing');
    }

    // ---------------------------------------------------------------- holds

    /**
     * Paid and moved in one save (the API takes {"status": "paid", "client_id": …}): the paid check in updating() runs
     * before the retax, but the retax of a paid invoice's lines is refused (InvoiceItemObserver) and the whole save rolls
     * back: never paid with a balance under the new customer's tax. (Its message, «A paid invoice keeps its lines», is
     * not the clearest for this case; nothing is written.)
     */
    public function test_holds_paid_and_moved_in_one_save_is_refused_and_nothing_is_written(): void
    {
        $abroad = $this->customer('US', 'John');
        $invoice = Invoice::query()->create(['client_id' => $abroad->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'price' => 100]);
        $invoice->items()->create(['description' => 'Discount', 'quantity' => 1, 'price' => -100, 'taxed' => false]);
        $invoice = $invoice->fresh();
        $this->assertSame([Invoice::STATUS_UNPAID, 0.0], [$invoice->status, (float) $invoice->total], 'precondition');
        $greek = $this->customer('GR', 'Eleni');

        try {
            $this->save($invoice, ['client_id' => $greek->id, 'status' => Invoice::STATUS_PAID]);
            $this->fail('paid and moved in one save');
        } catch (DisplayException) {
        }

        $kept = Invoice::query()->findOrFail($invoice->id);
        $this->assertSame([$abroad->id, Invoice::STATUS_UNPAID, 0.0], [(int) $kept->client_id, $kept->status, (float) $kept->total]);
    }

    /** A customer deleted (no money history) leaves its cancelled invoice without a customer through the key: no refusal. */
    public function test_holds_deleting_a_customer_with_a_cancelled_invoice_is_not_refused(): void
    {
        $client = $this->customer('GR', 'Eleni');
        $invoice = $this->invoice($client, status: Invoice::STATUS_DRAFT);
        $this->save($invoice, ['status' => Invoice::STATUS_CANCELLED]);

        $client->delete();

        $this->assertNull(Invoice::query()->whereKey($invoice->id)->value('client_id'));
    }

    /** A refunded invoice keeps its customer, reopened in the same save or not. */
    public function test_holds_a_refunded_invoice_never_moves(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'));
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_REFUNDED]); // as a refund leaves it
        $abroad = $this->customer('US', 'John');

        foreach ([['client_id' => $abroad->id], ['client_id' => $abroad->id, 'status' => Invoice::STATUS_UNPAID]] as $changes) {
            try {
                $this->save($invoice, $changes);
                $this->fail('a refunded invoice is not moved');
            } catch (DisplayException) {
            }
        }
        $this->assertSame($invoice->client_id, Invoice::query()->whereKey($invoice->id)->value('client_id'));
    }

    /** A paid zero invoice (no payments) keeps its customer; reopened and moved in one save, it is the new one's. */
    public function test_holds_a_paid_invoice_moves_only_reopened(): void
    {
        $invoice = $this->invoice($this->customer('GR', 'Eleni'), 0);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $abroad = $this->customer('US', 'John');

        try {
            $this->save($invoice, ['client_id' => $abroad->id]);
            $this->fail('a paid invoice is not moved');
        } catch (DisplayException) {
        }

        $moved = $this->save($invoice, ['client_id' => $abroad->id, 'status' => Invoice::STATUS_UNPAID]);
        $this->assertSame($abroad->id, (int) $moved->client_id);
    }

    /** A cancelled invoice reopened as a draft and moved in one save: open again, the new customer's tax. */
    public function test_holds_cancelled_reopened_as_draft_and_moved_is_retaxed(): void
    {
        $invoice = $this->save($this->invoice($this->customer('GR', 'Eleni')), ['status' => Invoice::STATUS_CANCELLED]);

        $moved = $this->save($invoice, ['client_id' => $this->customer('US', 'John')->id, 'status' => Invoice::STATUS_DRAFT]);

        $this->assertSame(100.0, (float) $moved->total);
        $this->assertFalse(AdjustmentNote::query()->where('invoice_id', $invoice->id)->where('is_cancellation', true)->where('status', 'active')->exists());
    }

    /**
     * MoveToClient of a service whose account also holds a paid and a cancelled invoice for it: those stay with the first
     * customer (its history), the open one moves; the new closed-invoice refusal never fires on this path.
     */
    public function test_holds_move_to_client_leaves_closed_invoices_and_moves_the_open_one(): void
    {
        $from = $this->customer('GR', 'Eleni');
        $to = $this->customer('US', 'John');
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $from->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 100, 'expires_at' => now()->addMonth()]);
        $make = function (string $status) use ($from, $service): Invoice {
            $invoice = Invoice::query()->create(['client_id' => $from->id, 'currency_code' => 'EUR', 'due_at' => now(), 'status' => Invoice::STATUS_UNPAID]);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => 100, 'description' => 'Hosting']);
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);

            return $invoice;
        };
        $paid = $make(Invoice::STATUS_PAID);
        $cancelled = $make(Invoice::STATUS_CANCELLED);
        $open = $make(Invoice::STATUS_UNPAID);

        $result = app(MoveToClient::class)->service($service, $to, $this->staff());

        $this->assertSame([$open->id], $result['moved']);
        $this->assertSame([$from->id, $from->id, $to->id], array_map(fn (Invoice $invoice) => (int) Invoice::query()->whereKey($invoice->id)->value('client_id'), [$paid, $cancelled, $open]));
        $this->assertSame(100.0, (float) Invoice::query()->findOrFail($open->id)->total);
    }
}

<?php

namespace Tests\Feature;

use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of #264 (the invoice PDF as WHMCS's): what the customer reads on the PDF must add up and match
 * what the rest of the app says the invoice owes. test_p* fail on the PR as it is; test_holds_* are attacks it resists.
 */
class InvoicePdfAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        Cache::flush();
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function setting(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value]);
        SettingsProvider::reload();
    }

    private function invoice(array $lines = [['Hosting', 1, 100]], string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        foreach ($lines as $line) {
            [$description, $quantity, $price] = $line;
            $invoice->items()->create(['description' => $description, 'quantity' => $quantity, 'price' => $price] + ($line[3] ?? []));
        }
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, float $amount, array $extra = []): string
    {
        $id = $extra['transaction_id'] ?? 'txn-' . uniqid();
        DB::table('invoice_transactions')->insert(['transaction_id' => $id] + $extra + ['invoice_id' => $invoice->id, 'amount' => $amount,
            'status' => InvoiceTransactionStatus::Succeeded->value, 'gateway_id' => $this->gateway->id, 'is_credit_transaction' => false,
            'refunded_amount' => 0, 'credited_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return (string) $id;
    }

    private function credit(Invoice $invoice, float $amount, array $extra = []): string
    {
        return $this->pay($invoice, $amount, $extra + ['is_credit_transaction' => true, 'gateway_id' => null, 'transaction_id' => 'credit-' . uniqid()]);
    }

    // Author (#264 fixes): the stamp is in capitals now, without Greek accents (review F6); the labels below are upper-cased.
    private function html(Invoice $invoice): string
    {
        return view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Findings (fail on #264 as it is)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * P1 — High. An overpayment turned into credit (credited_amount) is not shown anywhere on the PDF: the row prints
     * the gross 140,00 and only a refunded_amount gets a «Refunded» line. The customer reads Total 125,00, a payment of
     * 140,00 and Balance 0,00: Total − payments = −15 ≠ Balance, and the 15,00 that went to their account credit is
     * invisible. Same with «refund to account credit» on a paid invoice (credited_amount, note −X): payment 100,00,
     * Total 70,00, Balance 0,00.
     * Fix: print the credited part on the row as the refund is («Moved to credit: 15,00»), or print each row net.
     */
    public function test_p1_an_overpayment_moved_to_credit_is_shown_on_its_transaction(): void
    {
        $invoice = $this->invoice([['Hosting', 1, 100], ['Setup', 2, 12.5]]); // 125 due
        $this->pay($invoice, 140, ['credited_amount' => 15]);
        $invoice = $invoice->fresh();

        $this->assertSame(0.0, $invoice->statement()['balance']);
        $html = $this->html($invoice);
        $this->assertStringContainsString('140,00 EUR', $html);
        $this->assertStringContainsString('15,00 EUR', $html, 'the 15,00 moved to the account credit is not on the PDF: 125 − 140 ≠ 0');
    }

    /**
     * P2 — Medium. A payment still processing (a SEPA debit, a bank transfer awaiting confirmation) is not on the PDF
     * at all: «No related transactions found» and a red UNPAID stamp, though the app itself counts it as money on its
     * way (Invoice::hasPayments, the portal's «payment_pending»/«Η πληρωμή είναι σε επεξεργασία»). The customer who
     * paid reads that nothing was paid. (The balance is right not to count it.) The template's stamp even has a
     * 'payment_pending' case no invoice status ever reaches.
     * Fix: list processing transactions with their status (not summed), and/or say «payment pending» on the stamp.
     */
    public function test_p2_a_processing_payment_is_listed_not_hidden(): void
    {
        $invoice = $this->invoice();
        $id = $this->pay($invoice, 100, ['status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa-processing-1']);
        $invoice = $invoice->fresh();

        $this->assertSame($invoice->remaining, $invoice->statement()['balance']);
        $html = $this->html($invoice);
        $this->assertStringContainsString($id, $html, 'the processing payment is not on the PDF');
        $this->assertStringNotContainsString(e(__('invoices.no_transactions')), $html);
    }

    /**
     * P3 — Low. The adjustments row is a float sum of the notes (->sum('amount')) compared with != 0. Notes that net to
     * zero cents (debit 0,10 + debit 0,20 + credit −0,30) sum to 5.55e-17, so the PDF prints an «Adjustments 0,00 EUR»
     * row — noise on what the customer reads, and a sum that is not in cents as the rest of statement() is.
     * Fix: sum the active notes in cents (as total() does) and show the row only when that is not 0.
     */
    public function test_p3_notes_that_net_to_zero_print_no_adjustments_row(): void
    {
        $invoice = $this->invoice();
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 0.10, 'description' => 'Fee A']);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 0.20, 'description' => 'Fee B']);
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => -0.30, 'description' => 'Goodwill']);
        $invoice = $invoice->fresh();

        $this->assertSame(100.0, $invoice->total);
        $this->assertStringNotContainsString(e(__('invoices.ledger_adjustments')), $this->html($invoice), 'an «Adjustments 0,00» row is printed');
    }

    /**
     * P4 — Low. With notes visible to the customer, the notes table prints the note's status as ucfirst() of its code:
     * «Active»/«Voided» in English on a PDF that is otherwise in the customer's language (Greek), against CLAUDE.md
     * («strings to the customer in lang/»).
     * Fix: a lang key per note status (e.g. invoices.note_statuses.active/voided) in el and en.
     */
    public function test_p4_the_note_status_is_in_the_customers_language(): void
    {
        $this->setting('notes_client_visible', '1');
        $this->setting('app_language', 'el');
        App::setLocale('el');
        $invoice = $this->invoice();
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 5, 'description' => 'Τέλος']);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'voided', 'amount' => 3, 'description' => 'Λάθος']);

        $html = $this->html($invoice);

        $this->assertStringNotContainsString('>Active<', $html);
        $this->assertStringNotContainsString('>Voided<', $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Holds (attacks #264 resists)
    // ------------------------------------------------------------------------------------------------------------

    /** Every kind of transaction and note at once: the balance is remaining, and Total − net payments = Balance. */
    public function test_holds_balance_is_remaining_and_total_minus_payments_is_balance(): void
    {
        $invoice = $this->invoice([['Hosting', 1, 100], ['Mailbox', 3, 9.99]]); // 129,97
        $this->credit($invoice, 20, ['refunded_amount' => 2.5]);
        $this->credit($invoice, 5, ['credited_amount' => 1]);
        $this->pay($invoice, 40, ['refunded_amount' => 10]);
        $this->pay($invoice, 30, ['credited_amount' => 4.33]);
        $this->pay($invoice, 999, ['status' => InvoiceTransactionStatus::Failed->value]);
        $this->pay($invoice, 888, ['status' => InvoiceTransactionStatus::Processing->value]);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 7.25, 'description' => 'Late fee']);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'voided', 'amount' => 50, 'description' => 'Wrong']);
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => -2.22, 'description' => 'Goodwill']);
        $invoice = $invoice->fresh();

        $s = $invoice->statement();
        $net = array_sum(array_map(fn ($t) => $this->cents((float) $t->amount) - $this->cents((float) $t->refunded_amount) - $this->cents((float) $t->credited_amount), $s['payments']));
        $this->assertSame(135.0, $invoice->total);
        $this->assertSame(21.5, $s['credit']);
        $this->assertSame($invoice->remaining, $s['balance']);
        $this->assertSame($this->cents($s['total']) - $net, $this->cents($s['balance']));
        $this->assertCount(2, $s['payments'], 'only the succeeded gateway payments');
        $this->assertStringContainsString($this->money($invoice, $s['balance']), $this->html($invoice));
    }

    /** A credit transaction is the «Credit» row only: never a payment row too, never counted twice. */
    public function test_holds_credit_is_shown_once_not_as_a_payment(): void
    {
        $invoice = $this->invoice();
        $id = $this->credit($invoice, 30);
        $invoice = $invoice->fresh();

        $s = $invoice->statement();
        $this->assertSame([30.0, 70.0, 70.0], [$s['credit'], $s['total'], $s['balance']]);
        $this->assertSame([], $s['payments']);
        $html = $this->html($invoice);
        $this->assertStringNotContainsString($id, $html);
        $this->assertStringContainsString(e(__('invoices.no_transactions')), $html);
    }

    /** A credit transaction partly refunded (back, or to credit again): the Credit row is what stayed on the invoice. */
    public function test_holds_a_refunded_credit_transaction_is_net_in_the_credit_row(): void
    {
        $invoice = $this->invoice();
        $this->credit($invoice, 60, ['refunded_amount' => 10, 'credited_amount' => 15]);
        $invoice = $invoice->fresh();

        $s = $invoice->statement();
        $this->assertSame([35.0, 65.0, 65.0], [$s['credit'], $s['total'], $s['balance']]);
        $this->assertSame($invoice->remaining, $s['balance']);
    }

    /** A paid invoice partly refunded: the refund's note lowers the total, the row says what was refunded, Balance 0. */
    public function test_holds_a_partial_refund_on_a_paid_invoice_adds_up(): void
    {
        $invoice = $this->invoice(status: Invoice::STATUS_PAID);
        $this->pay($invoice, 100, ['refunded_amount' => 30]);
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => -30, 'description' => 'Refund', 'is_refund' => true]);
        $invoice = $invoice->fresh();

        $s = $invoice->statement();
        $this->assertSame([70.0, 0.0], [$s['total'], $s['balance']]);
        $html = $this->html($invoice);
        $this->assertStringContainsString(e(__('invoices.refunded_amount')) . ': 30,00 EUR', $html);
        $this->assertStringContainsString('-30,00 EUR', $html); // the adjustments row
        $this->assertStringContainsString(e(mb_strtoupper(Invoice::statusLabel('paid'))), $html);
    }

    /** A wholly refunded invoice: Total 0, Balance 0, the «Refunded» stamp; nothing reads as owed. */
    public function test_holds_a_refunded_invoice_owes_nothing(): void
    {
        $invoice = $this->invoice(status: Invoice::STATUS_REFUNDED);
        $this->pay($invoice, 100, ['refunded_amount' => 100]);
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => -100, 'description' => 'Refund', 'is_refund' => true]);
        $invoice = $invoice->fresh();

        $this->assertSame([0.0, 0.0], [$invoice->statement()['total'], $invoice->statement()['balance']]);
        $this->assertStringContainsString(e(mb_strtoupper(Invoice::statusLabel('refunded'))), $this->html($invoice));
    }

    /** A cancelled invoice: its cancellation note takes the whole total, Balance 0, grey «Cancelled» stamp. */
    public function test_holds_a_cancelled_invoice_owes_nothing(): void
    {
        $invoice = $this->invoice();
        $invoice->createCancellationCreditNote();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice = $invoice->fresh();

        $this->assertSame([0.0, 0.0], [$invoice->statement()['total'], $invoice->statement()['balance']]);
        $html = $this->html($invoice);
        $this->assertStringContainsString(e(mb_strtoupper(Invoice::statusLabel('cancelled'))), $html);
        $this->assertStringContainsString('#999999', $html);
    }

    /** A voided note counts nowhere: not in the total, not in the adjustments row. */
    public function test_holds_a_voided_note_counts_nowhere(): void
    {
        $invoice = $this->invoice();
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'voided', 'amount' => 50, 'description' => 'Wrong']);
        $invoice = $invoice->fresh();

        $this->assertSame([100.0, 100.0], [$invoice->total, $invoice->statement()['balance']]);
        $this->assertStringNotContainsString(e(__('invoices.ledger_adjustments')), $this->html($invoice));
    }

    /** Hundreds of small amounts: everything in cents, nothing drifts. */
    public function test_holds_many_small_amounts_do_not_drift(): void
    {
        $invoice = $this->invoice(array_fill(0, 30, ['Item', 1, 0.1]));
        for ($i = 0; $i < 29; $i++) {
            $this->pay($invoice, 0.1);
        }
        $invoice = $invoice->fresh();

        $s = $invoice->statement();
        $this->assertSame(3.0, $invoice->total);
        $this->assertSame(0.1, $s['balance']);
        $this->assertSame($invoice->remaining, $s['balance']);
    }

    /** An overpaid invoice with no credited_amount (imported): a negative balance, the same as remaining. */
    public function test_holds_a_negative_balance_is_remaining(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, 115);
        $invoice = $invoice->fresh();

        $this->assertSame(-15.0, $invoice->statement()['balance']);
        $this->assertSame($invoice->remaining, $invoice->statement()['balance']);
        $this->assertStringContainsString('-15,00 EUR', $this->html($invoice));
    }

    /** Subtotal + the tax lines + adjustments − credit = Total, with lines at two rates. */
    public function test_holds_tax_lines_and_subtotal_add_up_to_the_total(): void
    {
        $invoice = $this->invoice([
            ['Hosting', 1, 10.01, ['taxed' => true, 'tax_rate' => 24]],
            ['Domain', 3, 3.33, ['taxed' => true, 'tax_rate' => 13]],
            ['Fee', 1, 1.11, ['taxed' => false, 'tax_rate' => 0]],
        ]);
        $this->credit($invoice, 5);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 0.07, 'description' => 'Fee']);
        $invoice = $invoice->fresh();

        $taxes = array_sum(array_map(fn ($line) => $this->cents($line['amount']), $invoice->taxLines()));
        $notes = $this->cents((float) $invoice->adjustmentNotes->sum('amount'));
        $s = $invoice->statement();
        $this->assertSame($this->cents($s['total']), $this->cents($invoice->subtotal) + $taxes + $notes - $this->cents($s['credit']));
        $this->assertSame($this->cents($invoice->total), $this->cents($invoice->subtotal) + $taxes + $notes);
    }

    /** With tax on, a transaction's amount is printed as paid, not with tax worked out again on it. */
    public function test_holds_transaction_amounts_are_not_taxed_again(): void
    {
        $this->setting('tax_enabled', '1');
        $this->setting('tax_type', 'exclusive');
        $invoice = $this->invoice();
        $this->pay($invoice, 50);

        $this->assertStringContainsString('50,00 EUR', $this->html($invoice));
        $this->assertStringNotContainsString('62,00 EUR', $this->html($invoice));
    }

    /** Dates in the customer's language (Greek day and month names), and the transaction's date as d/m/Y. */
    public function test_holds_dates_in_the_customers_language(): void
    {
        App::setLocale('el');
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $invoice = $this->invoice();
        $this->pay($invoice, 10);

        $html = $this->html($invoice);
        $this->assertStringContainsString('Τρίτη', $html);
        $this->assertStringContainsString('20/10/2026', $html);
        $this->assertStringNotContainsString('Tuesday', $html);
    }

    /** Customer-controlled text (name, details, lines, notes, transaction id) is escaped: no tags reach dompdf. */
    public function test_holds_customer_text_cannot_inject_html_or_fetch_remote_resources(): void
    {
        $this->setting('notes_client_visible', '1');
        $payload = '<img src="http://198.51.100.7/x.png"><link rel="stylesheet" href="http://198.51.100.7/a.css"><script>alert(1)</script>';
        $invoice = $this->invoice([[$payload . "\nsecond line", 1, 10]]);
        $invoice->snapshot()->updateOrCreate([], ['name' => $payload, 'properties' => ['address' => $payload], 'bill_to' => $payload]);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 1, 'description' => $payload]);
        $this->pay($invoice, 5, ['transaction_id' => $payload]);

        $html = $this->html($invoice);

        $this->assertStringNotContainsString('198.51.100.7/x.png"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringContainsString(e('<img src="http://198.51.100.7/x.png">'), $html);
        $this->assertFalse((bool) config('dompdf.options.enable_remote'), 'dompdf must not fetch remote resources');
        $this->assertFalse((bool) config('dompdf.options.enable_php'));
    }

    private function money(Invoice $invoice, float $amount): string
    {
        return (new Price(['price' => $amount, 'currency' => $invoice->currency], tax: 0))->format($amount);
    }
}

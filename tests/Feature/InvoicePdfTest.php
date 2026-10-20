<?php

namespace Tests\Feature;

use App\Classes\PDF;
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
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The invoice PDF as WHMCS's (owner 2026-10-20): the credit applied apart from the payments, the total after it, the
 * transactions always, the balance always the invoice's remaining; in the customer's language wherever it is made.
 */
class InvoicePdfTest extends TestCase
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

    private function invoice(array $lines = [['Hosting', 1, 100]]): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        foreach ($lines as [$description, $quantity, $price]) {
            $invoice->items()->create(['description' => $description, 'quantity' => $quantity, 'price' => $price]);
        }

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, float $amount, array $extra = []): void
    {
        DB::table('invoice_transactions')->insert($extra + ['invoice_id' => $invoice->id, 'amount' => $amount, 'status' => InvoiceTransactionStatus::Succeeded->value,
            'gateway_id' => $this->gateway->id, 'transaction_id' => 'txn-' . uniqid(), 'is_credit_transaction' => false, 'refunded_amount' => 0, 'credited_amount' => 0,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_credit_payments_and_refunds_add_up_to_the_invoices_remaining(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, 30, ['is_credit_transaction' => true, 'gateway_id' => null, 'transaction_id' => null]);
        $this->pay($invoice, 50, ['refunded_amount' => 10]);
        $this->pay($invoice, 20, ['status' => InvoiceTransactionStatus::Failed->value]); // never counted
        $invoice = $invoice->fresh();

        $statement = $invoice->statement();

        $this->assertSame([30.0, 70.0, 30.0], [$statement['credit'], $statement['total'], $statement['balance']]);
        $this->assertCount(1, $statement['payments'], 'the credit is not a payment, nor is a failed one');
        $this->assertSame($invoice->remaining, $statement['balance']);
    }

    public function test_an_overpayment_turned_into_credit_and_a_debit_note_stay_with_remaining(): void
    {
        $invoice = $this->invoice([['Hosting', 1, 100], ['Setup', 2, 12.5]]);
        $this->pay($invoice, 140, ['credited_amount' => 15]); // 125 due: 15 went back to the account's credit
        $invoice = $invoice->fresh();
        $this->assertSame([0.0, 0.0], [$invoice->statement()['balance'], $invoice->remaining]);

        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 7.25, 'description' => 'Late fee']);
        $invoice = $invoice->fresh();
        $this->assertSame([7.25, 7.25], [$invoice->statement()['balance'], $invoice->remaining]);
    }

    public function test_it_shows_what_whmcs_shows(): void
    {
        $this->setting('bill_to_text', "Example Company OÜ\nExample street 1, Tallinn\nVAT EE000000000");
        $invoice = $this->invoice();
        $this->pay($invoice, 30, ['is_credit_transaction' => true, 'gateway_id' => null, 'transaction_id' => null]);

        $html = view('pdf.invoice', ['invoice' => $invoice->fresh()])->render();

        foreach (['Example Company OÜ', 'VAT EE000000000', __('invoices.invoiced_to'), __('invoices.due_date'), __('invoices.credit_applied'),
            __('invoices.no_transactions'), __('invoices.balance'), mb_strtoupper(__('invoices.statuses.unpaid'))] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString(__('invoices.unit_price'), $html, 'quantities of 1 need no columns');
        $this->assertStringContainsString(__('invoices.unit_price'), view('pdf.invoice', ['invoice' => $this->invoice([['Mailbox', 3, 2]])])->render());
    }

    public function test_one_language_the_customers_wherever_it_is_made(): void
    {
        $this->setting('app_language', 'el');
        $seen = [];
        View::composer('pdf.invoice', function () use (&$seen) {
            $seen[] = App::getLocale();
        });
        App::setLocale('en'); // a staff member or a customer browsing in English

        $pdf = PDF::generateInvoice($this->invoice())->output();

        $this->assertSame(['el'], array_values(array_unique($seen)));
        $this->assertSame('en', App::getLocale(), 'the page language was left changed');
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_every_status_renders_and_a_closed_invoice_owes_nothing(): void
    {
        foreach ([Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED] as $status) {
            $invoice = $this->invoice();
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
            $invoice = $invoice->fresh();

            $this->assertStringStartsWith('%PDF', PDF::generateInvoice($invoice)->output(), $status);
            if (in_array($status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)) {
                $this->assertSame(0.0, $invoice->statement()['balance'], "a {$status} invoice read as owed (review F2 of #264)");
            }
        }
    }

    public function test_the_title_and_dates_agree_and_greek_capitals_carry_no_accents(): void
    {
        $this->setting('invoice_proforma', '1');
        $html = view('pdf.invoice', ['invoice' => $this->invoice()])->render();
        $this->assertStringContainsString(e(__('invoices.proforma_invoice_date')), $html);

        $this->setting('invoice_number_is_id', '1'); // myip: the number is the id, never «proforma» (review F5 of #264)
        $html = view('pdf.invoice', ['invoice' => $this->invoice()])->render();
        $this->assertStringNotContainsString(e(__('invoices.proforma_invoice_date')), $html);

        App::setLocale('el');
        $html = view('pdf.invoice', ['invoice' => $this->invoice()])->render();
        $this->assertStringContainsString('ΑΠΛΗΡΩΤΟ', $html);
        $this->assertStringNotContainsString('ΑΠΛΉΡΩΤΟ', $html);
    }

    public function test_a_long_word_wraps_and_multi_line_details_keep_their_lines(): void
    {
        $invoice = $this->invoice([['https://example.test/' . str_repeat('a', 260), 1, 10]]);

        $html = view('pdf.invoice', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('table-layout: fixed', $html, 'a long word pushes the amounts off the page (review F1 of #264)');
        $this->assertStringContainsString('word-wrap: break-word', $html);
    }
}

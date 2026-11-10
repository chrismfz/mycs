<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Reports\IncomeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The year's income report (informative, owner 2026-11-10; docs/income-planning.md): credit a downgrade or a change of
 * cycle gave is a negative row in the year it was given (sweep 6, S6-TAX-EKDOSI-1), imported credit left out; an invoice
 * open again after a chargeback is simply no longer listed (S6-TAX-EKDOSI-3: nothing is kept to hold a past year still).
 */
class IncomeReportCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive',
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false,
            'settings.system_email_address' => 'staff@example.test']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->update(['country' => 'GR']);
        $this->client = $this->client->fresh();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $net, ?string $reference = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $net, 'reference_type' => $reference]);

        return $invoice->fresh();
    }

    /** A 100.00 + 24% invoice paid by card at $when. */
    private function paid(string $when, string $reference): Invoice
    {
        Carbon::setTestNow($when);
        $invoice = $this->invoice('100.00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 124.00, transactionId: $reference, currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice;
    }

    /** @return array{int, string, string, string} count, net, tax, total */
    private function totals(int $year): array
    {
        $totals = IncomeReport::year('EUR', $year)['totals'];

        return [$totals['count'], $totals['net'], $totals['tax'], $totals['total']];
    }

    /** @return list<array{string, string, string}> each row's day, total and what it is */
    private function rows(int $year): array
    {
        return array_map(fn ($row) => [$row['paid_at']->format('Y-m-d'), $row['total'], $row['paid_with']], IncomeReport::year('EUR', $year)['invoices']);
    }

    // ---- credit given by a downgrade or a change of cycle (S6-TAX-EKDOSI-1) ----------------------------------------

    public function test_credit_given_in_one_year_and_spent_in_the_next_is_taken_off_when_given(): void
    {
        $this->paid('2026-03-10 10:00:00', 'pi_a');
        Carbon::setTestNow('2026-12-30 10:00:00');
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 3100, MoneyTrail::CAUSE_CYCLE_CHANGE, MoneyTrail::SOURCE_SYSTEM);

        Carbon::setTestNow('2027-01-05 10:00:00');
        $b = $this->invoice('25.00');
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($b->id));

        // 2026: the invoice, and the credit as a row of its own, negative, net and tax at the customer's 24%.
        $this->assertSame([2, '75.00', '18.00', '93.00'], $this->totals(2026));
        $this->assertSame([['2026-03-10', '124.00', 'Stripe'], ['2026-12-30', '-31.00', 'Credit given for a change of cycle']], $this->rows(2026));
        $credit = IncomeReport::year('EUR', 2026)['invoices'][1];
        $this->assertSame(['', '-25.00', '-6.00', 'GR'], [$credit['number'], $credit['net'], $credit['tax'], $credit['country']]);
        // 2027: the invoice that credit paid. Both years together: the 124.00 that came in.
        $this->assertSame([1, '25.00', '6.00', '31.00'], $this->totals(2027));
    }

    public function test_credit_given_to_a_customer_without_vat_is_all_net(): void
    {
        $this->client->update(['tax_exempt' => true]);
        Carbon::setTestNow('2026-06-10 10:00:00');
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 3100, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_SYSTEM);

        $this->assertSame([1, '-31.00', '0.00', '-31.00'], $this->totals(2026));
        $this->assertSame('Credit given for a downgrade', $this->rows(2026)[0][2]);
    }

    public function test_other_credit_is_no_row(): void
    {
        Carbon::setTestNow('2026-06-10 10:00:00');
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 3100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        (new CreditLedger)->add((int) $this->client->id, 'USD', 3100, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_SYSTEM);

        $this->assertSame([0, '0.00', '0.00', '0.00'], $this->totals(2026));
    }

    public function test_imported_credit_is_no_row(): void
    {
        Carbon::setTestNow('2024-06-10 10:00:00');
        (new CreditLedger)->add((int) $this->client->id, 'EUR', 3100, MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::SOURCE_IMPORT);

        $this->assertSame([0, '0.00', '0.00', '0.00'], $this->totals(2024));
    }

    // ---- informative: the invoices paid in the year as they stand today (S6-TAX-EKDOSI-3) --------------------------

    public function test_a_chargeback_and_a_new_payment_show_the_invoice_where_it_stands_today(): void
    {
        $invoice = $this->paid('2026-12-20 10:00:00', 'pi_cb');
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));
        $this->assertSame([[0, '0.00', '0.00', '0.00'], [0, '0.00', '0.00', '0.00']], [$this->totals(2026), $this->totals(2027)]);

        Carbon::setTestNow('2027-03-15 10:00:00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 124.00, transactionId: 'pi_again', currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame([[0, '0.00', '0.00', '0.00'], [1, '100.00', '24.00', '124.00']], [$this->totals(2026), $this->totals(2027)], 'once, on its new date');
    }
}

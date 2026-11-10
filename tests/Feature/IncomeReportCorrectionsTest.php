<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceReopening;
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
 * The year's income report, its two corrections (sweep 6, S6-TAX-EKDOSI-1 and -3; docs/income-planning.md): credit a
 * downgrade or a change of cycle gave is taken off in the year it was given; a paid invoice reopened in a later year
 * (a chargeback, a deleted payment) stays in the year it was paid and is taken off in the year it was reopened. A year
 * sent to the accountant never changes.
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

    // ---- a paid invoice open again (S6-TAX-EKDOSI-3) ---------------------------------------------------------------

    public function test_a_chargeback_in_a_later_year_is_a_negative_row_of_that_year(): void
    {
        $invoice = $this->paid('2026-12-20 10:00:00', 'pi_cb');
        $sent = IncomeReport::year('EUR', 2026);

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->assertEquals($sent, IncomeReport::year('EUR', 2026), 'the closed year, row by row');
        $this->assertSame([1, '-100.00', '-24.00', '-124.00'], $this->totals(2027));
        $this->assertSame([['2027-02-10', '-124.00', 'Reopened: chargeback or payment deleted']], $this->rows(2027));
        $this->assertSame((string) $invoice->id, IncomeReport::year('EUR', 2027)['invoices'][0]['number']);
    }

    public function test_a_chargeback_in_the_year_it_was_paid_leaves_no_rows(): void
    {
        $this->paid('2026-03-20 10:00:00', 'pi_cb');
        Carbon::setTestNow('2026-05-10 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));

        $this->assertSame(1, InvoiceReopening::query()->count());
        $this->assertSame([0, '0.00', '0.00', '0.00'], $this->totals(2026));
    }

    public function test_paid_again_counts_in_the_year_it_is_paid_again(): void
    {
        $invoice = $this->paid('2026-12-20 10:00:00', 'pi_cb');
        Carbon::setTestNow('2027-02-10 10:00:00');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur');

        Carbon::setTestNow('2028-01-15 10:00:00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 124.00, transactionId: 'pi_again', currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->assertSame([1, '100.00', '24.00', '124.00'], $this->totals(2026));
        $this->assertSame([1, '-100.00', '-24.00', '-124.00'], $this->totals(2027));
        $this->assertSame([1, '100.00', '24.00', '124.00'], $this->totals(2028));
    }

    public function test_a_dispute_won_counts_from_the_reopening_not_from_the_first_payment(): void
    {
        $invoice = $this->paid('2026-12-20 10:00:00', 'pi_cb');
        Carbon::setTestNow('2027-02-10 10:00:00');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur');

        // Won: the invoice is paid again by its first payment, whose date its paid_at takes again.
        Carbon::setTestNow('2027-04-01 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026', $invoice->fresh()->paid_at->format('Y'));

        $this->assertSame([1, '100.00', '24.00', '124.00'], $this->totals(2026), 'once in 2026, not twice');
        $this->assertSame([2, '0.00', '0.00', '0.00'], $this->totals(2027));
        $this->assertSame([['2027-02-10', '-124.00', 'Reopened: chargeback or payment deleted'], ['2027-04-01', '124.00', 'Stripe']], $this->rows(2027));

        // Disputed again a year later: off in 2028, and 2026 and 2027 as they were.
        Carbon::setTestNow('2028-03-01 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb2', 124.00, 'eur'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([1, '100.00', '24.00', '124.00'], $this->totals(2026));
        $this->assertSame([2, '0.00', '0.00', '0.00'], $this->totals(2027));
        $this->assertSame([1, '-100.00', '-24.00', '-124.00'], $this->totals(2028));
    }

    public function test_a_dispute_lost_in_the_year_it_was_paid_and_won_the_next_year_leaves_the_first_year_as_sent(): void
    {
        $invoice = $this->paid('2026-12-05 10:00:00', 'pi_cb');
        Carbon::setTestNow('2026-12-28 10:00:00');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur');
        $sent = $this->totals(2026);
        $this->assertSame([0, '0.00', '0.00', '0.00'], $sent);

        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_cb', 'dp_cb', 124.00, 'eur'));
        $this->assertSame('2026', $invoice->fresh()->paid_at->format('Y'));

        $this->assertSame($sent, $this->totals(2026));
        $this->assertSame([['2027-02-01', '124.00', 'Stripe']], $this->rows(2027));
    }

    public function test_a_payment_deleted_in_a_later_year_and_the_invoice_changed_since(): void
    {
        $invoice = $this->paid('2026-12-20 10:00:00', 'pi_del');
        $sent = IncomeReport::year('EUR', 2026);

        Carbon::setTestNow('2027-03-05 10:00:00');
        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        // Open and without payments, staff change its line: the closed year keeps what was paid then.
        $invoice->items()->sole()->update(['price' => '50.00']);

        // Its amounts, day and customer as sent; only «paid with» no longer names the gateway of the deleted payment.
        $now = IncomeReport::year('EUR', 2026);
        $this->assertSame('Payment deleted', $now['invoices'][0]['paid_with']);
        $now['invoices'][0]['paid_with'] = $sent['invoices'][0]['paid_with'];
        $this->assertEquals($sent, $now);
        $this->assertSame([1, '-100.00', '-24.00', '-124.00'], $this->totals(2027));

        // Paid again the same year, at its new total.
        Carbon::setTestNow('2027-03-20 10:00:00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 62.00, transactionId: 'pi_new', currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals($sent['totals'], IncomeReport::year('EUR', 2026)['totals']);
        $this->assertSame([2, '-50.00', '-12.00', '-62.00'], $this->totals(2027));
    }

    public function test_a_refund_note_between_the_payment_and_the_reopening_stays_in_its_year(): void
    {
        $invoice = $this->paid('2025-06-01 10:00:00', 'pi_note');
        Carbon::setTestNow('2026-02-01 10:00:00');
        DB::table('adjustment_notes')->insert(['invoice_id' => $invoice->id, 'type' => 'credit', 'status' => 'active', 'amount' => -24.80,
            'description' => 'refund', 'created_at' => now(), 'updated_at' => now()]);
        $before = [$this->totals(2025), $this->totals(2026)];
        $this->assertSame([[1, '100.00', '24.00', '124.00'], [1, '-20.00', '-4.80', '-24.80']], $before);

        // Reopened in 2027 (as the observer records it), still open.
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
        InvoiceReopening::query()->create(['invoice_id' => $invoice->id, 'paid_at' => '2025-06-01 10:00:00', 'reopened_at' => '2027-03-01 10:00:00',
            'net' => '100.00', 'total' => '124.00']);

        $this->assertSame($before, [$this->totals(2025), $this->totals(2026)], 'both closed years as they were sent');
        $this->assertSame([1, '-80.00', '-19.20', '-99.20'], $this->totals(2027), 'what was left of it');
    }

    public function test_a_credit_purchase_reopened_is_never_a_row(): void
    {
        Carbon::setTestNow('2026-12-20 10:00:00');
        $invoice = $this->invoice('50.00', Credit::class);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, transactionId: 'pi_funds', currency: 'eur');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        Carbon::setTestNow('2027-02-10 10:00:00');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_funds', 'dp_funds', 50.00, 'eur');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->assertSame(0, InvoiceReopening::query()->count());
        $this->assertSame([[0, '0.00', '0.00', '0.00'], [0, '0.00', '0.00', '0.00']], [$this->totals(2026), $this->totals(2027)]);
    }
}

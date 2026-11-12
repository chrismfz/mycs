<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * `paid_balance` on a WHMCS invoice whose total came below zero: a «Price Override» recurring coupon took a full month's
 * difference off a pro rata part month (line 1.97, promo −2.44, twice: −0.94). WHMCS marked it Paid with no payment and
 * gave the −0.94 to its customer as credit; the importer journals that as an `invoice_reduced` credit_in on the invoice.
 * Nothing is wrong with the money then. Made-up rows.
 */
class MoneyCheckNegativeInvoicesTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsService(32, '2026-10-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Paid, two part-month lines of 1.97 each with its promo of −2.44: total −0.94; WHMCS gave $credited of it to credit. */
    private function negativeInvoice(int $id, float $credited): void
    {
        $this->whmcsInvoice($id, 'Paid', [31 => 1.97, 32 => 1.97], paid: 0.0);
        foreach ([31, 32] as $service) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate) VALUES ({$id}, {$this->whmcsClientId},
                'PromoHosting', {$service}, 'Promotional Code: EXAMPLE - Price Override', -2.44, 0, '2026-09-10')");
        }
        $this->whmcs->exec('UPDATE tblinvoices SET total = -0.94, credit = ' . -$credited . " WHERE id = {$id}");
    }

    /** @return list<string> */
    private function said(): array
    {
        return array_map(fn (array $finding) => "{$finding['subject']}: {$finding['detail']}", (new MoneyCheck)->run(['paid_balance']));
    }

    public function test_a_negative_invoice_whose_difference_became_credit_is_not_paid_over(): void
    {
        $this->negativeInvoice(601, 0.94);
        $this->importWhmcs();

        $invoice = $this->imported(601);
        $this->assertSame(-0.94, $invoice->total);
        $this->assertSame(0, $invoice->transactions()->count(), 'WHMCS took no payment on it');
        $credit = MoneyEvent::query()->where('invoice_id', 601)->where('cause', MoneyTrail::CAUSE_INVOICE_REDUCED)->sole();
        $this->assertSame([MoneyEventKind::CreditIn, '0.94', null], [$credit->kind, $credit->amount, $credit->invoice_transaction_id]);

        $this->assertSame([], $this->said());
    }

    public function test_a_negative_invoice_with_no_such_credit_is_still_paid_over(): void
    {
        // The difference reached no one: money lost without trace.
        $this->negativeInvoice(602, 0.0);
        $this->importWhmcs();

        $this->assertSame(0, MoneyEvent::query()->where('invoice_id', 602)->where('cause', MoneyTrail::CAUSE_INVOICE_REDUCED)->count());
        $this->assertSame(['invoice #602: paid over 0.94 EUR'], $this->said());
    }

    public function test_a_negative_invoice_whose_credit_is_smaller_reports_the_part_not_covered(): void
    {
        $this->negativeInvoice(603, 0.50);
        $this->importWhmcs();

        $this->assertSame(['invoice #603: paid over 0.44 EUR'], $this->said());
    }

    public function test_a_reversed_credit_covers_nothing(): void
    {
        $this->negativeInvoice(604, 0.94);
        $this->importWhmcs();
        $credit = MoneyEvent::query()->where('invoice_id', 604)->where('cause', MoneyTrail::CAUSE_INVOICE_REDUCED)->sole();
        MoneyEvent::query()->create(['kind' => MoneyEventKind::Reversal, 'amount' => '0.94', 'currency_code' => 'EUR', 'client_id' => $credit->client_id,
            'invoice_id' => 604, 'source' => MoneyTrail::SOURCE_IMPORT, 'reverses_id' => $credit->id, 'occurred_at' => now()]);

        $this->assertSame(['invoice #604: paid over 0.94 EUR'], $this->said());
    }

    public function test_a_positive_invoice_paid_over_is_still_reported_even_with_such_a_credit(): void
    {
        $this->whmcsInvoice(605, 'Paid', [31 => 10.0]);
        $this->importWhmcs();
        // Its line came down by 2.00 after it was paid in full, the 2.00 never became credit.
        DB::table('invoice_items')->where('invoice_id', 605)->update(['price' => 8.0, 'line_total' => 8.0]);
        // An `invoice_reduced` credit on it with no payment does not hide it: only a total below zero is WHMCS's case.
        MoneyEvent::query()->create(['kind' => MoneyEventKind::CreditIn, 'amount' => '2.00', 'currency_code' => 'EUR',
            'client_id' => $this->whmcsClientId, 'invoice_id' => 605, 'source' => MoneyTrail::SOURCE_IMPORT,
            'cause' => MoneyTrail::CAUSE_INVOICE_REDUCED, 'occurred_at' => now()]);

        $this->assertSame(['invoice #605: paid over 2.00 EUR'], $this->said());
    }
}

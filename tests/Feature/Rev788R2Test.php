<?php

namespace Tests\Feature;

use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\DowngradeCredit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Second pass of the adversarial review of #788: only what changed after 657920fd (the fixes of REV-788-1/3/4 and the
 * removal of the discount exclusion). Through the importer's own steps; invented rows, no network.
 */
class Rev788R2Test extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string|null, 1: string|null} */
    private function period(int $invoice): array
    {
        $line = InvoiceItem::query()->where('invoice_id', $invoice)->where('reference_type', Service::class)->whereNull('kind')->sole();

        return [$line->period_start?->toDateString(), $line->period_end?->toDateString()];
    }

    /**
     * REV-788-R2-1 (low). The fix of REV-788-4 asks whether WHMCS changed the package ON OR AFTER THE LINE'S DUE DATE. A
     * renewal paid early is due later than the day it was billed and paid: 20.00 billed 09-11 and paid 09-13 for
     * 09-25 to 10-25, then on 09-18 a downgrade in WHMCS to the 10.00 package (WHMCS credits the difference for the days
     * to the new due date, 10-25, those of this line). The change is before the due date, so the line gets its period and
     * is worth 20.00 for a downgrade here: the difference WHMCS gave back is given again. The day to compare with is the
     * day the line was billed (its invoice's date): a change after that is a change of what the line paid.
     */
    public function test_rev_788_r2_1_a_package_change_in_whmcs_after_a_renewal_was_paid_early_leaves_its_line_without_a_period(): void
    {
        $this->whmcsService(31, '2026-10-25', 10.0);
        $this->whmcsInvoice(431, 'Paid', [31 => 20.0], due: '2026-08-25', date: '2026-08-18');
        $this->whmcsInvoice(531, 'Paid', [31 => 20.0], due: '2026-09-25', date: '2026-09-11');
        $this->whmcs->exec('CREATE TABLE tblupgrades (id INTEGER PRIMARY KEY, relid INT, type TEXT, status TEXT, paid TEXT, date TEXT)');
        $this->whmcs->exec("INSERT INTO tblupgrades (relid, type, status, paid, date) VALUES (31, 'package', 'Completed', 'Y', '2026-09-18')");
        $this->importWhmcs();

        $service = Service::query()->findOrFail(31);
        // The most a downgrade here may give back of the period's money (no limit by the difference, nothing kept).
        $credit = (string) DowngradeCredit::credit($service, CarbonImmutable::today(), BigDecimal::of('100'), BigDecimal::zero());
        $this->assertSame([null, null], $this->period(531), "changed in WHMCS after the line was billed: not what its days were paid (a downgrade here would give back up to {$credit})");
        $this->assertStringContainsString('upgraded or downgraded in WHMCS inside the last paid period: #31', $this->importSaid());
    }

    /**
     * REV-788-R2-2 (low; the accepted «before the discount» at its far end, for the owner to know). With the discount
     * exclusion gone, a line beside a discount of its WHOLE amount (a 100% promotion or client group: 10.00 and −10.00,
     * an invoice of 0.00 that WHMCS closed as paid with no payment) gets its period too. PaidForPeriod::keptShare reads
     * «its lines come to nothing» as «kept whole», so the days left are worth 6.67 of an invoice that never held a cent
     * (before: 0.00, by the service's price). Not 10% over what was paid: credit from nothing.
     */
    public function test_rev_788_r2_2_the_days_left_of_a_period_billed_at_nothing_are_worth_nothing(): void
    {
        $this->whmcsService(31, '2026-10-10', 0.0);
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (480, {$this->whmcsClientId}, '', '2026-09-01', '2026-09-10', '2026-09-01 10:00:00', 'Paid', 0, 0, 0)");
        foreach ([['Hosting', 10], ['PromoHosting', -10]] as [$type, $amount]) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate)
                VALUES (480, {$this->whmcsClientId}, '{$type}', 31, '{$type}', {$amount}, 0, '2026-09-10')");
        }
        $this->importWhmcs();

        $this->assertSame('0.00', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()), 'nothing was paid for these days');
    }
}

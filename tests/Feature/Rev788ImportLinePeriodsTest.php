<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Adversarial review of #788 (WhmcsLinePeriods, InvoiceObserver::givePeriodsUp). Through the importer's own steps;
 * invented rows, no network.
 */
class Rev788ImportLinePeriodsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->productId = $product->product->id;
        $this->whmcs($user->client()->id, $this->productId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->settings();
        $this->artisan('app:cron-job');
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} */
    private function line(int $invoice): array
    {
        $line = InvoiceItem::query()->where('invoice_id', $invoice)->where('reference_type', Service::class)->whereNull('kind')->sole();

        return [$line->period_start?->toDateString(), $line->period_end?->toDateString(), $line->applied_at?->toDateTimeString()];
    }

    /** A WHMCS invoice of service $service: a Hosting line of 10.00 and a discount line of −1.00 beside it (9.00 to pay). */
    private function discountedInvoice(int $id, int $service, string $status, string $due, string $discount = 'GroupDiscount', string $date = '2026-09-01'): void
    {
        $paid = $status === 'Paid' ? date('Y-m-d', strtotime($date . ' +2 days')) . ' 10:00:00' : '0000-00-00 00:00:00';
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->whmcsClientId}, '', '{$date}', '{$due}', '{$paid}', '{$status}', 9, 0, 0)");
        foreach ([['Hosting', $service, 10], [$discount, $discount === 'GroupDiscount' ? 0 : $service, -1]] as [$type, $relid, $amount]) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate)
                VALUES ({$id}, {$this->whmcsClientId}, '{$type}', {$relid}, '{$type}', {$amount}, 0, '{$due}')");
        }
        if ($status === 'Paid') {
            $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->whmcsClientId}, {$id}, 'banktransfer', '{$paid}', 9, 0, 0, 'tr_{$id}', 0)");
        }
    }

    /**
     * REV-788-1 (medium). The «pro rata first order» branch asks only: one paid line, due the day the service was made, a
     * pro rata product, the due date later. It never asks whether the due date IS the end of the first pro rata period
     * (Prorata::firstPeriod). A service of a pro rata product whose later renewals left no «Hosting» line here (invoices
     * deleted in WHMCS: their lines are not imported; a date moved by hand; months given free) has ONE paid line, its
     * order's, and a due date eleven months later: the line (23.00, the days from 2025-11-08 to 2025-12-01) is given
     * 2025-11-08 .. 2026-10-01 and marked applied. From then on that is «the period it is in»: the days left of an
     * upgrade's 10.00 a month are 0.36 instead of 3.67 (11 of 30 days), and its unused days are worth 0.77 instead of
     * 11.00. On a product that is not pro rata the same dates are named (one cycle on is not the due date).
     */
    public function test_rev_788_1_a_pro_rata_first_order_is_written_only_when_the_due_date_is_the_end_of_its_first_period(): void
    {
        $prorata = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20]);
        $prorata->plan->update(['name' => 'Monthly']);
        $this->whmcsProductId = $prorata->product->id;
        // Made 2025-11-08, its order paid 23.00 for 11-08 to 12-01. Due 2026-10-01 now: ten renewals with no line here.
        $this->whmcsService(31, '2026-10-01', 30.0, regdate: '2025-11-08');
        $this->whmcsInvoice(480, 'Paid', [31 => 23.0], due: '2025-11-08', date: '2025-11-08');
        $this->importWhmcs();
        $this->settings();

        $service = Service::query()->findOrFail(31);
        $this->assertSame('2026-09-01', $service->currentPeriodStart()?->toDateString(), 'the period it is in is the month to 10-01, not the 327 days from its order');
        $this->assertSame('3.67', $service->remainingOf('10.00', $service->currentPeriodStart(), now()), 'an upgrade of 10.00 a month, 11 of 30 days left');
        $this->assertSame([null, null, null], $this->line(480), 'not its first pro rata period (that ended 2025-12-01): no period, as any other mismatch');
        $this->assertStringContainsString('#31 (', $this->importSaid(), 'and the service is named');
    }

    /**
     * REV-788-2 (medium). The open renewal beside a discount line is given its period «all the same». From then on it is
     * an open period line as a native one, and a change of the service's plan rebuilds it (Service::refreshOpenPeriods):
     * its price becomes the service's price. But here the price holds the discount (9.00), and WHMCS's own discount line
     * (−1.00) is still beside it: the invoice WHMCS issued for 9.00 asks for 8.00, the discount given twice. Before, the
     * line had no period and a plan change left WHMCS's invoice as it was. (nixpal: every service of the 10% group.)
     */
    public function test_rev_788_2_a_plan_change_does_not_reprice_an_open_whmcs_renewal_that_has_its_discount_on_a_line_beside_it(): void
    {
        $this->markTestIncomplete('Known, owner 2026-11-11: a plan change in the service form, while a WHMCS open renewal with a discount line beside it is '
            . 'unpaid, rebuilds its Hosting line at the service\'s price and leaves the negative line (8.00 asked instead of 9.00). No logic is added for it.');

        $this->whmcsService(31, '2026-09-25', 9.0);
        $this->discountedInvoice(431, 31, 'Paid', '2026-08-25', date: '2026-08-18');
        $this->discountedInvoice(531, 31, 'Unpaid', '2026-09-25');
        $this->importWhmcs();
        $this->settings();
        $service = Service::query()->findOrFail(31);
        $this->assertSame('9.00', number_format((float) $service->price, 2), 'its price holds the discount');
        $this->assertSame('9.00', number_format((float) Invoice::query()->findOrFail(531)->total, 2), 'precondition: WHMCS asks 10.00 − 1.00');

        // Staff put it on another plan of the same cycle, at the same price (the service form).
        $other = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Monthly B',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $other->id, 'price' => 9.00, 'currency_code' => 'EUR']);
        $service->applyStaffChanges(['plan_id' => $other->id]);

        $this->assertSame('9.00', number_format((float) Service::query()->findOrFail(31)->price, 2));
        $this->assertSame('9.00', number_format((float) Invoice::query()->findOrFail(531)->total, 2),
            'the open renewal still asks what the service costs: its discount is not taken off twice');
    }

    /**
     * REV-788-3 (medium). A service with TWO unpaid renewals (WHMCS billed the next month while the first was unpaid),
     * suspended for non-payment. The one due on the due date gets its period; the later one comes with none. The customer
     * pays the later one (the one their last reminder named). Before, a payment took the first period not paid for: the
     * service was renewed and back, and `service_unbilled` told staff of the invoice still unpaid behind it. Now the
     * first period is held by the unpaid line, the payment takes the month after it and «waits»: 10.00 paid, the service
     * still suspended on 2026-09-05, on its way to termination, and nothing tells staff (the money check is empty).
     * WHMCS renewed it on that payment too (its due date moves by one cycle whichever invoice is paid).
     */
    public function test_rev_788_3_a_payment_of_the_later_of_two_open_renewals_still_renews_a_suspended_service(): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (42, {$this->whmcsClientId}, NULL, {$this->whmcsProductId}, '2024-01-01', 'example.test', 1, 10, 'Monthly',
            '2026-09-05', 'Suspended', '', '', '', 'Overdue on Payment', '', '', 0, '0000-00-00')");
        $this->whmcsInvoice(442, 'Paid', [42 => 10.0], due: '2026-08-05', date: '2026-07-29');
        $this->whmcsInvoice(542, 'Unpaid', [42 => 10.0], due: '2026-09-05', date: '2026-08-29');
        $this->whmcsInvoice(642, 'Unpaid', [42 => 10.0], due: '2026-10-05', date: '2026-09-18');
        $this->importWhmcs();
        $this->settings();
        $this->assertSame(Service::STATUS_SUSPENDED, Service::query()->findOrFail(42)->status);

        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(642), null, '10.00', 'tr_642');

        $service = Service::query()->findOrFail(42);
        $told = array_column((new UnbilledServices)->find(), 'service');
        $this->assertTrue($service->expires_at->toDateString() === '2026-10-05' || in_array(42, $told, true),
            "10.00 was paid for service 42: it is renewed (as before, and as WHMCS did), or staff are told. It is {$service->status}, due {$service->expires_at->toDateString()}, and the money check says "
            . json_encode((new MoneyCheck)->run()));
        $this->assertSame([Service::STATUS_ACTIVE, '2026-10-05'], [$service->status, $service->expires_at->toDateString()], 'the payment buys the first month not paid for');
    }

    /** WHMCS's own upgrade of service $id (tblupgrades) on $day, with the line of what it charged, paid, when there was any. */
    private function whmcsUpgrade(int $id, string $day, ?float $charged): void
    {
        $this->whmcs->exec('CREATE TABLE IF NOT EXISTS tblupgrades (id INTEGER PRIMARY KEY, relid INT, type TEXT, status TEXT, paid TEXT)');
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES ({$id}, {$id}, 'package', 'Completed', 'Y')");
        if ($charged === null) {
            return;
        }
        $invoice = 600 + $id;
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice}, {$this->whmcsClientId}, '', '{$day}', '{$day}', '{$day} 10:00:00', 'Paid', {$charged}, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate)
            VALUES ({$invoice}, {$this->whmcsClientId}, 'Upgrade', {$id}, 'Upgrade/Downgrade', {$charged}, 0, '{$day}')");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$invoice}, {$this->whmcsClientId}, {$invoice}, 'banktransfer', '{$day} 10:00:00', {$charged}, 0, 0, 'tr_{$invoice}', 0)");
    }

    /**
     * REV-788-4a (low). A package upgrade made and paid in WHMCS inside the last paid period: 10.00 paid for 09-10 to
     * 10-10, then on 09-15 the upgrade to the 20.00 package, 8.33 paid for the 25 days left. Its «Hosting» line (10.00)
     * matches the dates and gets the period; the upgrade's money is a charge line here, of no period. The days left on
     * 09-20 were paid 6.67 + 6.66: before, the service's price said so (20 of 30 days of 20.00 = 13.33). Now the line
     * says 6.67, and a cycle change «now» or a downgrade gives the customer half of what they paid for those days.
     */
    public function test_rev_788_4a_the_days_left_count_an_upgrade_paid_in_whmcs_inside_the_period(): void
    {
        $this->whmcsService(31, '2026-10-10', 20.0);
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsUpgrade(31, '2026-09-15', 8.33);
        $this->importWhmcs();
        $this->settings();

        $this->assertSame('13.33', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()),
            'the days left as they were paid: 6.67 of the period\'s 10.00 and 6.66 of the upgrade\'s 8.33');
    }

    /**
     * REV-788-4b (medium). The other way: 20.00 paid for 09-10 to 10-10, then on 09-15 a downgrade in WHMCS to the 10.00
     * package (WHMCS credits the difference for the days left to the account, no line). The «Hosting» line (20.00) matches
     * the dates and gets the period, so the days left on 09-20 are worth 13.33 by the line: the difference WHMCS gave
     * back already is given again by a cycle change «now» or a second downgrade here. Before, the service's price said
     * 6.67 (20 of 30 days of 10.00), what the customer still holds.
     */
    public function test_rev_788_4b_the_days_left_do_not_count_what_a_downgrade_in_whmcs_gave_back(): void
    {
        $this->whmcsService(31, '2026-10-10', 10.0);
        $this->whmcsInvoice(480, 'Paid', [31 => 20.0], due: '2026-09-10');
        $this->whmcsUpgrade(31, '2026-09-15', null);
        $this->importWhmcs();
        $this->settings();

        $this->assertSame('6.67', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()),
            '20 of 30 days of the 10.00 package it is on: the rest of the 20.00 came back as credit in WHMCS');
    }
}

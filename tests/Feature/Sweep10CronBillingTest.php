<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 10, cron-billing (adversarial verifier). S10-CRON-BILLING-1: an order still «Pending» and unpaid in WHMCS on the
 * day of the dump (young enough for the import to take it: abandonedOrders refuses one older than 7 days) comes with
 * `expires_at` = WHMCS's nextduedate, the day it was ordered; a native pending order has none. Paid weeks after the
 * cut-over («Cancel unpaid orders after» is 0 by default: never cancelled), its period counts from the order date instead
 * of the day it starts. Through the importer's own steps; invented rows, no network.
 */
class Sweep10CronBillingTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        // The dump: four days after the order (the import takes an unpaid order up to 7 days old).
        Carbon::setTestNow('2026-09-05 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct(); // set up on payment, no server, not pro rata, not an addon
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        // Ordered 09-01, never paid: WHMCS keeps nextduedate = regdate on it, and its order invoice is due that day.
        $this->whmcsService(41, '2026-09-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcsInvoice(601, 'Unpaid', [41 => 10.0], due: '2026-09-01', date: '2026-09-01');
        $this->importWhmcs();
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'settings.cronjob_order_cancel' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payTheOrderOn(string $day): Service
    {
        $service = Service::query()->findOrFail(41);
        $this->assertSame(Service::STATUS_PENDING, $service->status, 'it came pending');
        $this->assertNull($service->activated_at, 'and never started');
        $this->assertFalse($service->hasServerActions(), 'nothing to create: the payment starts it');
        $this->imported(601);

        Carbon::setTestNow($day . ' 10:00:00');
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(601), null, '10.00', 'tr_601');

        $service = $service->fresh();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'paid: it starts');

        return $service;
    }

    public function test_an_imported_pending_order_paid_after_the_cut_over_gets_its_month_from_the_day_it_starts(): void
    {
        $this->markTestIncomplete('known (2026-11-12): an imported Pending order paid late starts from its WHMCS order date; Pending services at the dump are looked at by hand: cutover-runbook');

        $service = $this->payTheOrderOn('2026-11-20');

        // As a native pending order paid that day: one month from today, not a month that ended 50 days ago.
        $this->assertSame('2026-12-20', $service->expires_at?->toDateString(),
            'the customer paid one month on 2026-11-20 and the service starts that day');
        $line = InvoiceItem::query()->where('invoice_id', 601)->sole();
        $this->assertSame(['2026-11-20', '2026-12-20'], [$line->period_start?->toDateString(), $line->period_end?->toDateString()]);
    }

    public function test_it_is_not_suspended_by_the_cron_the_night_after_it_was_paid(): void
    {
        $this->markTestIncomplete('known (2026-11-12): an imported Pending order paid late starts from its WHMCS order date; Pending services at the dump are looked at by hand: cutover-runbook');

        $this->payTheOrderOn('2026-11-20');

        Carbon::setTestNow('2026-11-21 03:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'settings.cronjob_order_cancel' => 0]);
        $this->artisan('app:cron-job');

        $service = Service::query()->findOrFail(41);
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'paid yesterday for a month: still running');
        $this->assertNull($service->dunning_suspended_at);
    }
}

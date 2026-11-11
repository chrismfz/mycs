<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 10, the fix's neighbours (S10-CRON-BILLING-1): only a «Pending» WHMCS service comes without a due date; every
 * other keeps WHMCS's nextduedate. Through the importer's own steps; invented rows, no network.
 */
class Sweep10PendingImportFixTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-05 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_a_pending_service_comes_without_a_due_date(): void
    {
        $this->whmcsService(41, '2026-09-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcsService(42, '2026-09-20', status: 'Active');
        $this->whmcsService(43, '2026-08-20', status: 'Suspended');
        $this->whmcsService(44, '2026-03-01', status: 'Terminated');
        // A status this app does not name is imported pending too, but it had started: its date stays WHMCS's.
        $this->whmcsService(45, '2026-03-01', status: 'Completed');
        $this->importWhmcs();

        $services = Service::query()->orderBy('id')->get()->keyBy('id');
        $this->assertSame([41 => null, 42 => '2026-09-20', 43 => '2026-08-20', 44 => '2026-03-01', 45 => '2026-03-01'],
            $services->map(fn (Service $service) => $service->expires_at?->toDateString())->all());
        $this->assertSame([Service::STATUS_PENDING, null], [$services[41]->status, $services[41]->activated_at], 'as an order made here: not started, no date');
        $this->assertSame(Service::STATUS_PENDING, $services[45]->status);
        $this->assertNotNull($services[45]->activated_at);
    }

    /** With a date it was a renewal for the cron to bill; without one it waits for its order's payment, as one made here. */
    public function test_the_cron_bills_no_renewal_of_an_imported_pending_order_and_leaves_it_pending(): void
    {
        $this->whmcsService(41, '2026-09-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcsInvoice(601, 'Unpaid', [41 => 10.0], due: '2026-09-01', date: '2026-09-01');
        $this->importWhmcs();
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'settings.cronjob_order_cancel' => 0]);

        Carbon::setTestNow('2026-09-06 03:00:00');
        $this->artisan('app:cron-job');

        $service = Service::query()->findOrFail(41);
        $this->assertSame([Service::STATUS_PENDING, null], [$service->status, $service->expires_at]);
        $this->assertSame([601], Invoice::query()->pluck('id')->all(), 'its order invoice alone');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(601)->status);
    }
}

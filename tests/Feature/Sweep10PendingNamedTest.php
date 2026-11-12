<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 10, S10-CRON-BILLING-1 (known): a «Pending» WHMCS service comes as before, with WHMCS's next due date, and the
 * import says which they are, for staff to check each one's due date once it is paid and started (cutover-runbook).
 */
class Sweep10PendingNamedTest extends TestCase
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

    public function test_the_import_names_the_services_still_pending_and_brings_them_as_before(): void
    {
        $this->whmcsService(41, '2026-09-01', regdate: '2026-09-01', status: 'Pending');
        $this->whmcsService(42, '2026-09-20', status: 'Active');
        // A status this app does not name comes pending too, but it had started: not an order waiting.
        $this->whmcsService(45, '2026-03-01', status: 'Completed');
        $this->importWhmcs();

        $this->assertStringContainsString("1 service(s) still «Pending» in WHMCS (#41): each comes with WHMCS's next due date, the day it was ordered. "
            . 'Paid and started after the cut-over, its period counts from that day: check its due date then (cutover-runbook).', $this->importSaid());
        $this->assertSame([41 => '2026-09-01', 42 => '2026-09-20', 45 => '2026-03-01'],
            Service::query()->orderBy('id')->get()->mapWithKeys(fn (Service $service) => [$service->id => $service->expires_at?->toDateString()])->all());
    }

    public function test_nothing_is_said_without_one(): void
    {
        $this->whmcsService(42, '2026-09-20', status: 'Active');
        $this->importWhmcs();

        $this->assertStringNotContainsString('still «Pending»', $this->importSaid());
    }
}

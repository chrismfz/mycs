<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 11, S11-CRON-BILLING-1: a service that ended and is brought back loses its old cancellation request
 * (ServiceObserver). The paths around it stay as they were: a request on a service that never ended is carried out, a
 * service brought back with no request is only brought back. Invented rows, no network.
 */
class BroughtBackCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-10-20 03:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'audit.console' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    private function request(Service $service, string $type = 'end_of_period'): ServiceCancellation
    {
        return ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => $type, 'reason' => 'Leaving']);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function billed(Service $service): int
    {
        return Invoice::query()->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
    }

    public function test_a_request_on_a_service_that_never_ended_is_kept_and_carried_out_by_the_cron(): void
    {
        $service = $this->service('2026-11-05');
        $request = $this->request($service);

        // Staff change it while it runs: suspended and back, a new note. It never ended.
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        $this->assertTrue($request->fresh()?->exists, 'the customer\'s request stays');

        $this->cronOn('2026-10-30');
        $this->assertSame(0, $this->billed($service), 'not billed: it is leaving');
        $this->cronOn('2026-11-05');

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        Queue::assertPushed(TerminateJob::class, 1);
        $this->assertNotNull($request->fresh(), 'and the request carried out stays on the ended service, as before');
    }

    public function test_a_pending_service_set_active_keeps_its_request(): void
    {
        $service = $this->service('2026-11-05', Service::STATUS_PENDING);
        $request = $this->request($service);

        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertNotNull($request->fresh());
    }

    public function test_a_service_brought_back_with_no_request_is_only_brought_back(): void
    {
        $service = $this->service('2026-10-01', Service::STATUS_TERMINATED);
        $audits = DB::table('audits')->where('auditable_type', ServiceCancellation::class)->count();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);
        $this->cronOn('2026-12-14');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(1, $this->billed($service));
        $this->assertSame($audits, DB::table('audits')->where('auditable_type', ServiceCancellation::class)->count());
    }

    public function test_a_service_brought_back_loses_its_old_request_with_an_audit_and_is_billed_its_next_renewal(): void
    {
        foreach ([[Service::STATUS_CANCELLED, Service::STATUS_ACTIVE], [Service::STATUS_TERMINATED, Service::STATUS_SUSPENDED]] as [$ended, $back]) {
            Carbon::setTestNow('2026-11-20 10:00:00');
            $service = $this->service('2026-10-01', $ended);
            $request = $this->request($service);
            $other = $this->request($this->service('2026-10-01', $ended)); // another ended service's: not looked at

            $service->fresh()->applyStaffChanges(['status' => $back, 'expires_at' => '2026-12-20 00:00:00']);

            $this->assertNull($request->fresh(), "{$ended} → {$back}");
            $this->assertNotNull($other->fresh());
            $this->assertTrue(DB::table('audits')->where('auditable_type', ServiceCancellation::class)->where('auditable_id', $request->id)
                ->where('event', 'deleted')->exists(), 'the request\'s delete is in the audit log');
        }

        // The one set active is billed 7 days before its new due date, as any running service.
        $active = Service::query()->where('status', Service::STATUS_ACTIVE)->sole();
        $this->cronOn('2026-12-14');
        $this->assertSame(1, $this->billed($active));
        $this->cronOn('2026-12-20');
        $this->assertSame(Service::STATUS_ACTIVE, $active->fresh()->status);
    }

    /**
     * Review of #811, REV-811-4: an addon's request made with its parent's stays while the parent's stands; once the
     * parent's is gone (the parent was brought back), the addon brought back loses its own as any service.
     */
    public function test_an_addons_request_made_with_its_parents_goes_only_once_the_parents_is_gone(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $parent = $this->service('2026-10-01', Service::STATUS_CANCELLED);
        $addon = $this->service('2026-10-01', Service::STATUS_CANCELLED);
        $addon->forceFill(['parent_service_id' => $parent->id, 'client_id' => $parent->client_id])->saveQuietly();
        $this->request($parent);
        $with = (new ServiceCancellation(['service_id' => $addon->id, 'type' => 'end_of_period', 'reason' => 'Leaving']))->forceFill(['with_parent' => true]);
        $with->save();

        // The addon alone, its parent's request standing: kept.
        $addon->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);
        $this->assertNotNull($with->fresh());

        // The parent is brought back (its request goes), then the addon again.
        $addon->fresh()->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);
        $parent->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);
        $this->assertFalse($parent->cancellation()->exists());
        if ($with->fresh() !== null) {
            $addon->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);
        }
        $this->assertNull($with->fresh(), 'nothing of the first life is left on the addon');
    }
}

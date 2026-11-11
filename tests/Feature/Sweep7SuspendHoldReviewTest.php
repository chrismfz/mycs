<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent and adversarial review of #772 (the hold in place of #770's issue-date rule). REV-772-1 and -2 are fixed
 * (ServiceObserver::updating); REV-772-3 is accepted and its test pins what was accepted. Invented fixtures only. Today is 2026-10-05, Suspend Days 8.
 */
class Sweep7SuspendHoldReviewTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_suspend_enabled' => true,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_terminate_min_suspended_days' => 1, 'settings.credits_auto_use' => false]);
        $this->client = User::factory()->create()->client();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, ?Service $parent = null, array $attributes = []): Service
    {
        $product = $this->createProduct();
        if ($parent !== null) {
            $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        }

        return Service::factory()->create($attributes + [
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent?->id, 'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires, 'currency_code' => 'USD',
            'price' => $parent ? 3.00 : 10.00, 'quantity' => 1,
        ]);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->admin()->create();
    }

    private function staffCommand(Service $service, string $command): void
    {
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => $command, 'sendNotification' => false])->assertHasNoActionErrors();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function hold(Service $service): ?string
    {
        return $service->fresh()->suspend_hold_until?->toDateString();
    }

    /**
     * REV-772-1 (low). The hold only looks at a service coming back from Suspended. One staff bring back from Cancelled
     * (or Terminated) past its due date was not billed while it was ended either: the next run bills its period and
     * suspends it in the same run, the customer never having seen an invoice (S6-CRON-BILLING-1 through another door).
     * #770's rule covered it; taken back, nothing does. Cancelled, due 09-01, set Active by staff on 10-05: the run of
     * 10-06 issues the invoice and must not suspend for it.
     */
    public function test_rev_772_1_a_service_staff_bring_back_from_cancelled_past_due_is_not_billed_and_suspended_in_one_run(): void
    {
        $service = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_CANCELLED]);
        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->cronOn('2026-10-06');

        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->sole();
        $this->assertSame([Invoice::STATUS_UNPAID, '2026-10-06'], [$line->invoice->status, $line->invoice->created_at->toDateString()],
            'precondition: its due period is billed by this run, for the first time');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status,
            'billed and suspended by the same run: its customer had no invoice to pay before (hold: ' . ($this->hold($service) ?? 'none') . ')');
    }

    /**
     * REV-772-2 (low). Staff set the status back to Active and their own «Do Not Suspend Until» in one save of the
     * service's page (two days, say: 10-07). Their date is replaced by today + Suspend Days (10-13) without a word; they
     * have to open the page again and set it a second time. What staff typed in that save is theirs.
     */
    public function test_rev_772_2_a_hold_staff_set_in_the_same_save_as_the_unsuspension_is_kept(): void
    {
        $service = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['status' => Service::STATUS_ACTIVE, 'suspend_hold_until' => '2026-10-07'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame('2026-10-07', $this->hold($service), 'the date staff set in this save, not today + Suspend Days');
    }

    /**
     * REV-772-3 (low), accepted (owner 2026-11-10): staff's «Suspend» on a service the cron suspended takes the cron's
     * mark (Service::placeOnHold), so their later «Unsuspend» of it, still unpaid, gives the hold as for any suspension of
     * theirs. It is staff's own act, twice, and the hold is theirs to clear. Due 09-10, invoice issued 08-27, suspended
     * by the cron on 09-19, «Suspend» by staff on 09-25, «Unsuspend» on 10-20: held through 10-28; cleared, the next run
     * suspends it.
     */
    public function test_rev_772_3_staffs_suspend_then_unsuspend_of_an_unpaid_service_gives_the_hold_and_staff_can_clear_it(): void
    {
        $service = $this->service('2026-09-10');
        $this->billedAhead($service);
        $this->cronOn('2026-09-19');
        $this->assertNotNull($service->fresh()->dunning_suspended_at, 'precondition: suspended for non-payment');
        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->staffCommand($service->fresh(), 'suspend');
        $this->assertNull($service->fresh()->dunning_suspended_at, 'precondition: staff\'s «Suspend» made it theirs');

        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->staffCommand($service->fresh(), 'unsuspend');
        $this->cronOn('2026-10-21');

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->sole()->status, 'precondition: the invoice of 08-27 is still the one to pay');
        $this->assertSame([Service::STATUS_ACTIVE, '2026-10-28'], [$service->fresh()->status, $this->hold($service)], 'accepted: staff\'s own suspension lifted, held');

        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['suspend_hold_until' => null])->call('save')->assertHasNoFormErrors();
        $this->cronOn('2026-10-22');
        $this->assertSame([Service::STATUS_SUSPENDED, null], [$service->fresh()->status, $this->hold($service)], 'staff cleared it: suspended by the next run');
    }

    /** REV-772-2, the other half: a hold staff clear in the same save as the unsuspension stays cleared. */
    public function test_rev_772_2_a_hold_staff_clear_in_the_same_save_as_the_unsuspension_stays_cleared(): void
    {
        $service = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse', 'suspend_hold_until' => '2026-10-06']);

        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'suspend_hold_until' => null]);

        $this->assertSame([Service::STATUS_ACTIVE, null], [$service->fresh()->status, $this->hold($service)]);
    }

    /** REV-772-1, the edges: terminated as cancelled; not past due, none; a pending one's start keeps its own dates. */
    public function test_rev_772_1_terminated_gets_the_hold_and_pending_or_not_due_do_not(): void
    {
        $terminated = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_TERMINATED]);
        $notDue = $this->service('2026-11-01', attributes: ['status' => Service::STATUS_CANCELLED]);
        $pending = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_PENDING, 'activated_at' => '2026-08-01']);
        foreach ([$terminated, $notDue, $pending] as $service) {
            $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        }

        $this->assertSame(['2026-10-13', null, null], [$this->hold($terminated), $this->hold($notDue), $this->hold($pending)]);
    }
}

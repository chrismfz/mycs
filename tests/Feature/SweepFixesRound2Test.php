<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\SuspendJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Round-2 adversarial review of #434 (898a896c): failing tests for what the review found. */
class SweepFixesRound2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailFacade::fake();
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false, 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '00:00',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * R2-1 (Low-Medium). The `$reissued` guard «never past Termination Days» assumes the same run terminates the
     * service. CronJob::terminable() also requires no hold (suspend_hold_until), «minimum days suspended», no later paid
     * period, no payment on its way. A service past Termination Days that staff hold («Do not suspend/terminate until»)
     * so the customer can pay, and whose overdue renewal staff re-issue (a wrong price): not terminated (the hold), and
     * not billed either (the guard). The customer has nothing to pay through the hold and is terminated when it ends.
     */
    public function test_a_reissued_renewal_of_a_held_service_past_termination_days_is_billed_again(): void
    {
        Carbon::setTestNow('2026-10-26 11:00:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'suspended', 'dunning_suspended_at' => '2026-09-04 00:05:00', 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => '2026-09-01', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'suspend_hold_until' => '2026-11-09']); // staff: two more weeks to pay
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-09-01']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 12.00, 'period_start' => '2026-09-01', 'period_end' => '2026-10-01']);
        // Staff: «Cancel Invoice» → «Re-issue» (the price was wrong).
        $invoice->keep_periods_billable = true;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'wrong price']);

        Carbon::setTestNow('2026-10-27 00:05:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: the hold keeps it from termination');
        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(1, $open, 'staff held the service and re-issued its renewal, yet it has nothing to pay through the hold');
    }

    /**
     * R2-2 (Low, regression of 898a896c). `toldOfSuspension()` now answers «told» for any suspension with no mail in
     * the log and dunning_suspended_at null, i.e. every manual hold (placeOnHold clears it). Staff «Suspend server»
     * without «Send Notification» (the customer is not told), then «Unsuspend server» with it: the customer gets «your
     * service was unsuspended» for a suspension never told, and the «No email sent: never told» branch is unreachable
     * for manual suspensions. origin/main and 4b77450e: no mail.
     */
    public function test_unsuspending_a_silent_manual_hold_does_not_mail_unsuspended(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-20', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => false]);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: a silent manual hold');
        $this->assertSame(0, EmailLog::query()->where('related_id', $service->id)->where('template_key', 'server_suspended')->count(), 'precondition: not told');

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => true]);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: unsuspended');
        $this->assertSame(0, EmailLog::query()->where('related_id', $service->id)->where('template_key', 'server_unsuspended')->count(),
            '«unsuspended» mailed for a manual suspension the customer was never told of');
    }

    /**
     * R2-3 (Low; LifecycleSimulationTest seed 4 «always», V-MAIL on service #25). `toldOfSuspension()` reads the latest
     * suspended/unsuspended mail of the service, of any suspension: one told of an EARLIER suspension, then unsuspended
     * by staff without a mail, counts for the current one. Suspended again by the cron, staff unsuspend with «Send
     * Notification» before the worker sent the new «suspended» (F-SIM-1): «unsuspended» goes out for a suspension never
     * told (and the service was already back up for days after the silent unsuspend). Fix: a «suspended» mail at or
     * after the current dunning_suspended_at.
     */
    public function test_a_previous_suspensions_mail_does_not_count_for_the_current_one(): void
    {
        Carbon::setTestNow('2026-10-26 00:05:00');
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-20', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-20', 'period_end' => '2026-11-20']);
        Queue::fake([SuspendJob::class]);
        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended on 26/10');
        Queue::pushed(SuspendJob::class)->sole()->withFakeQueueInteractions()->handle();
        $this->assertSame(1, EmailLog::query()->where('related_id', $service->id)->where('template_key', 'server_suspended')->count(), 'precondition: told on 26/10');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        Carbon::setTestNow('2026-10-26 12:00:00'); // staff: unsuspend, no mail (a promise to pay by phone)
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => false]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: unsuspended silently');

        Queue::fake([SuspendJob::class]);
        Carbon::setTestNow('2026-10-29 00:05:00'); // still unpaid: suspended again, its job not run yet
        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended again on 29/10');

        Carbon::setTestNow('2026-10-29 00:20:00'); // staff, before the worker: unsuspend with «Send Notification»
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => true]);

        $this->assertSame(0, EmailLog::query()->where('related_id', $service->id)->where('template_key', 'server_unsuspended')->count(),
            '«unsuspended» mailed for the 29/10 suspension, never told: the 26/10 mail was counted for it');
    }
}

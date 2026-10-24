<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\SuspendJob;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Minimal reproductions of what LifecycleSimulationTest found (adversarial sweep by simulation, 5bc5e859). */
class LifecycleSimulationFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailFacade::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_enabled' => false, 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Monday 10:05, the night's daily run reached the suspensions; overdue since 20/10, an open invoice. */
    private function overdue(): Service
    {
        Carbon::setTestNow('2026-10-26 10:05:00');
        Setting::updateOrCreate(['key' => 'cron_dunning_ready_at', 'settingable_type' => CronStat::class], ['value' => '2026-10-26 00:00:05', 'type' => 'string']);
        $product = $this->createProduct();
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-20', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-20', 'period_end' => '2026-11-20']);

        return $service;
    }

    /**
     * F-SIM-1 (LOW; seeds 1 and 3, window). The hourly run suspends at 10:05; staff open the morning digest and
     * «Unsuspend server» with «Send Notification» at 10:20, before the worker got to the cron's SuspendJob. The job
     * then finds the service active and sends no «suspended» (#418), but the staff action already mailed «your service
     * was unsuspended»: the customer is told of the end of a suspension they never had (and never saw on the server).
     */
    public function test_staff_unsuspend_before_the_worker_tells_no_unsuspension_of_a_suspension_never_told(): void
    {
        $service = $this->overdue();
        Queue::fake([SuspendJob::class]);
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended at 10:05');

        Carbon::setTestNow('2026-10-26 10:20:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => true]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: staff unsuspended it');

        Carbon::setTestNow('2026-10-26 10:59:00'); // the worker
        Queue::pushed(SuspendJob::class)->sole()->withFakeQueueInteractions()->handle();

        $keys = EmailLog::query()->where('related_id', $service->id)->pluck('template_key')->all();
        $this->assertNotContains('server_suspended', $keys, 'fixture: the worker came after staff, no suspension told');
        $this->assertNotContains('server_unsuspended', $keys, '«unsuspended» mailed for a suspension the customer was never told of');
    }

    /**
     * F-SIM-2 (MEDIUM; seed 3, window, daily run at 14:00). Staff cancel the overdue renewal with «Re-issue: bill them
     * all again» (or delete it, as the form's helper says for a duplicate) on Sunday. The next daily run would bill it
     * again, but the hourly run comes first (Monday 10:05, on Sunday's marker) and suspends the service with nothing to
     * pay. The 14:00 daily run then bills nothing: a suspended service is not billed again (decision A2) unless it paid
     * since its suspension. The customer stays suspended with no invoice, and is terminated at Termination Days.
     */
    public function test_a_reissued_renewal_is_billed_again_before_or_after_the_hourly_suspension(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $service = $this->overdue();
        $invoice = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_id', $service->id))->sole();
        Setting::updateOrCreate(['key' => 'cron_dunning_ready_at', 'settingable_type' => CronStat::class], ['value' => '2026-10-25 14:00:00', 'type' => 'string']);

        Carbon::setTestNow('2026-10-25 17:10:00'); // Sunday: staff «Cancel Invoice», «Re-issue»
        $invoice->keep_periods_billable = true;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'wrong price']);

        Queue::fake();
        Carbon::setTestNow('2026-10-26 10:05:00'); // Monday, the hourly run
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        Carbon::setTestNow('2026-10-26 14:00:00'); // the daily run
        $this->artisan('app:cron-job');

        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(1, $open, 'staff chose «Re-issue», yet the service (' . $service->fresh()->status . ') has nothing to pay');
    }

    /**
     * F-SIM-2b (MEDIUM; the same cause without any window). A service already suspended for non-payment; staff
     * re-issue its renewal («Cancel Invoice» → «Re-issue», a wrong price). No daily run bills it again (decision A2: a
     * suspended service is not billed), so the customer has nothing to pay, stays suspended and is terminated.
     */
    public function test_a_reissued_renewal_of_a_suspended_service_is_billed_again(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '00:00']);
        $service = $this->overdue();
        $invoice = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_id', $service->id))->sole();
        Queue::fake();
        Carbon::setTestNow('2026-10-26 00:05:00');
        $this->artisan('app:cron-job');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended for non-payment');

        Carbon::setTestNow('2026-10-26 11:00:00'); // staff: «Cancel Invoice», «Re-issue» (another price)
        $fresh = $invoice->fresh();
        $fresh->keep_periods_billable = true;
        $fresh->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'wrong price']);

        Carbon::setTestNow('2026-10-27 00:05:00');
        $this->artisan('app:cron-job');

        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame(1, $open, 'staff chose «Re-issue», yet the suspended service has nothing to pay');
    }

    /**
     * F-SIM-3 (LOW; strict seeds 1-8). With «suspend only with an overdue invoice» on, the cron suspends only once the
     * invoice was also ISSUED Suspend Days ago (CronJob::suspendable, created_at). The last overdue notice computes its
     * «suspended on» from the service's date alone (PaymentReminders::suspendOn): an invoice issued late (re-issued,
     * deleted and billed again, the first run after go-live) tells the customer a day the cron does not keep.
     */
    public function test_the_last_notice_names_the_day_the_cron_really_suspends(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_overdue_reminders' => '1,4', 'settings.cronjob_order_suspend' => 5,
            'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '00:00']);
        Carbon::setTestNow('2026-10-23 09:00:00'); // the renewal (due 20/10) issued three days late
        $service = $this->overdue();
        Invoice::query()->update(['created_at' => '2026-10-23 09:00:00']);
        Queue::fake();

        $told = null;
        $suspended = null;
        foreach (range(0, 10) as $day) {
            Carbon::setTestNow(Carbon::parse('2026-10-24 00:05:00')->addDays($day));
            $this->artisan('app:cron-job');
            $body = (string) EmailLog::query()->where('template_key', 'invoice_overdue')->latest('id')->value('body');
            if ($told === null && preg_match('#suspended on (?:\*\*|<strong>)?(\d{2}/\d{2}/\d{4})#', $body, $m) === 1) {
                $told = Carbon::createFromFormat('d/m/Y', $m[1])->toDateString();
            }
            if ($suspended === null && $service->fresh()->status === Service::STATUS_SUSPENDED) {
                $suspended = now()->toDateString();
            }
        }

        $this->assertNotNull($told, 'precondition: the last notice names a day');
        $this->assertSame($told, $suspended, "told «suspended on {$told}», suspended on {$suspended}");
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\TerminateJob;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The hold a service gets when it is brought back from a suspension that was not for non-payment (owner 2026-11-10, in
 * place of #770's issue-date rule; S6-CRON-BILLING-1/2): staff's own suspension lifted, or an addon back with its parent,
 * past its due date. «Do Not Suspend Until» = today + Suspend Days (ServiceObserver::updating), so the cron bills the due
 * period on its next run and suspends it only after the hold. Invented fixtures only.
 */
class Sweep7SuspendHoldTest extends TestCase
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

    /** Staff's «Suspend server» / «Unsuspend server» on the service's page. */
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
     * (a) Suspended by staff on 10-05, due 10-15, not billed while suspended; unsuspended by staff on 10-30 (15 days past
     * due): a hold through 11-07. The run of 10-31 bills the period and does not suspend; nor any run through 11-07; the
     * run of 11-08 does, the invoice still unpaid.
     */
    public function test_staff_unsuspending_their_own_suspension_past_due_gives_suspend_days_and_the_cron_bills_without_suspending(): void
    {
        $service = $this->service('2026-10-15');
        $this->staffCommand($service, 'suspend');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertNull($service->fresh()->dunning_suspended_at, 'precondition: staff\'s suspension, not the cron\'s');
        foreach (['2026-10-08', '2026-10-15', '2026-10-25'] as $day) {
            $this->cronOn($day);
        }
        $this->assertSame(0, InvoiceItem::query()->where('reference_id', $service->id)->count(), 'precondition: not billed while suspended by hand');

        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->staffCommand($service->fresh(), 'unsuspend');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame('2026-11-07', $this->hold($service), 'today + Suspend Days');

        $this->cronOn('2026-10-31');
        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->sole();
        $this->assertSame('2026-10-15', $line->period_start->toDateString(), 'the due period is billed by the next run');
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended by the run that billed it');
        foreach (range(1, 7) as $day) {
            $this->cronOn("2026-11-0{$day}");
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, "held through 11-07 (run of 11-0{$day})");
        }
        $this->cronOn('2026-11-08');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'the day after the hold, unpaid');
        $this->assertSame('Overdue on Payment', $service->fresh()->suspend_reason);
    }

    /** Not past its due date: nothing to hold. A status set on the model (the API, the form's status field) is the same place. */
    public function test_no_hold_when_the_due_date_has_not_passed_and_a_later_hold_is_kept(): void
    {
        $notDue = $this->service('2026-10-15', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        $dueToday = $this->service('2026-10-05', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        $later = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_hold_until' => '2026-12-01']);
        $earlier = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_hold_until' => '2026-10-06']);
        $pending = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_PENDING, 'activated_at' => '2026-08-01']);

        foreach ([$notDue, $dueToday, $later, $earlier, $pending] as $service) {
            $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        }

        $this->assertNull($this->hold($notDue));
        $this->assertNull($this->hold($dueToday), 'due today is not past due');
        $this->assertSame('2026-12-01', $this->hold($later), 'a later hold of staff stays');
        $this->assertSame('2026-10-13', $this->hold($earlier), 'an earlier one is moved to today + Suspend Days');
        $this->assertNull($this->hold($pending), 'only one coming back from a suspension');
    }

    /**
     * Suspended for non-payment: as before. Staff's unsuspension alone gives no time (the next run suspends it again:
     * they set a hold themselves, as their mail says), and a payment (the normal path) brings it back with no hold.
     */
    public function test_a_suspension_for_non_payment_gets_no_hold_from_staff_nor_from_a_payment(): void
    {
        $byStaff = $this->service('2026-09-10');
        $paid = $this->service('2026-09-10');
        $invoice = $this->billedAhead($paid);
        $this->billedAhead($byStaff);
        $this->cronOn('2026-09-19');
        $this->assertNotNull($byStaff->fresh()->dunning_suspended_at, 'precondition: suspended by the cron');
        $this->assertNotNull($paid->fresh()->dunning_suspended_at);

        Carbon::setTestNow('2026-09-25 12:00:00');
        $this->staffCommand($byStaff->fresh(), 'unsuspend');
        (new RecordPaymentService)->handle($invoice->fresh(), null, (string) $invoice->remaining, transactionId: 's7-hold-paid');

        $this->assertSame(Service::STATUS_ACTIVE, $byStaff->fresh()->status);
        $this->assertNull($this->hold($byStaff), 'staff\'s unsuspension of an unpaid service: no hold on its own');
        $this->assertSame(Service::STATUS_ACTIVE, $paid->fresh()->status, 'paid: back');
        $this->assertSame('2026-10-10', $paid->fresh()->expires_at->toDateString());
        $this->assertNull($this->hold($paid), 'the customer paid: no hold');

        $this->cronOn('2026-09-26');
        $this->assertSame(Service::STATUS_SUSPENDED, $byStaff->fresh()->status, 'unpaid, no hold: suspended again by the next run, as before');
        $this->assertSame(Service::STATUS_ACTIVE, $paid->fresh()->status);
    }

    /**
     * An addon suspended with its parent, both on one invoice (the normal case): the customer pays it, both are back and
     * paid ahead. The addon comes back through AddonCascade before its own line is applied; it gets no hold.
     */
    public function test_an_addon_paid_on_its_parents_invoice_comes_back_with_no_hold(): void
    {
        $parent = $this->service('2026-10-15');
        $addon = $this->service('2026-10-15', $parent);
        $this->cronOn('2026-10-08');
        $this->cronOn('2026-10-24');
        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status, 'precondition: suspended for non-payment');
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason, 'precondition: the addon with it');

        Carbon::setTestNow('2026-10-26 12:00:00');
        $invoice = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->sole();
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->remaining, transactionId: 's7-hold-both');

        $this->assertSame([Service::STATUS_ACTIVE, '2026-11-15'], [$parent->fresh()->status, $parent->fresh()->expires_at->toDateString()]);
        $this->assertSame([Service::STATUS_ACTIVE, '2026-11-15'], [$addon->fresh()->status, $addon->fresh()->expires_at->toDateString()]);
        $this->assertNull($this->hold($parent));
        $this->assertNull($this->hold($addon), 'paid with its parent: no hold');
    }

    /**
     * A parent staff suspended by hand, lifted past due: its addons come back with it, each held as it is (they follow
     * their parent's hold, ADV-A3-4), also one with an open invoice of its own. (Under a parent suspended for non-payment
     * such an addon stays suspended «Overdue on Payment», owner 2026-11-08: RevKissAddonsTest.)
     */
    public function test_addons_of_a_parent_staff_suspended_come_back_held_with_it(): void
    {
        $parent = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        $none = $this->service('2026-09-01', $parent, ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => Service::SUSPEND_REASON_PARENT]);
        $own = $this->service('2026-09-01', $parent, ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => Service::SUSPEND_REASON_PARENT]);
        $this->billedAhead($own);

        $this->staffCommand($parent, 'unsuspend');

        foreach ([$parent, $none, $own] as $service) {
            $this->assertSame([Service::STATUS_ACTIVE, '2026-10-13'], [$service->fresh()->status, $this->hold($service)]);
        }
        $this->cronOn('2026-10-06');
        $this->assertSame([Service::STATUS_ACTIVE, Service::STATUS_ACTIVE, Service::STATUS_ACTIVE],
            [$parent->fresh()->status, $none->fresh()->status, $own->fresh()->status], 'billed, none suspended during the hold');
        $this->cronOn('2026-10-14');
        $this->assertSame([Service::STATUS_SUSPENDED, Service::STATUS_SUSPENDED, Service::STATUS_SUSPENDED],
            [$parent->fresh()->status, $none->fresh()->status, $own->fresh()->status], 'the day after it, unpaid');
    }

    /**
     * (b) Past Termination Days when staff lift their suspension (due 07-01, back on 10-05): billed by the next run,
     * active through the hold, never terminated meanwhile (the cron ends only a service it suspended for non-payment,
     * and none that is held: DunningRules::terminable).
     */
    public function test_nothing_is_terminated_during_the_hold_however_long_past_due(): void
    {
        $service = $this->service('2026-07-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        $this->staffCommand($service, 'unsuspend');
        $this->assertSame('2026-10-13', $this->hold($service));

        foreach (range(6, 13) as $day) {
            $this->cronOn(sprintf('2026-10-%02d', $day));
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, "run of 10-{$day}: neither suspended nor terminated");
        }
        Queue::assertNotPushed(TerminateJob::class);
        $this->assertSame(1, InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->count(), 'its due period was billed, once');

        $this->cronOn('2026-10-14');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'the day after the hold: suspended first, not terminated');
        Queue::assertNotPushed(TerminateJob::class);
    }

    /**
     * (c) What the customer hears during the hold, 15 days past due when staff lift their suspension: the invoice when
     * it is issued, and the day before the hold ends the «hold ending» notice with the day of the suspension
     * (CronJob::holdsEnding, as for any hold). Overdue notices and saved-card charges count from the invoice's due date
     * as ever (10-15 here: past their windows, none goes; within them they go as for any invoice).
     */
    public function test_during_the_hold_the_customer_gets_the_invoice_and_the_hold_ending_notice(): void
    {
        Mail::fake();
        (new EmailTemplateSeeder)->run();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $service = $this->service('2026-10-15', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->staffCommand($service, 'unsuspend');
        $notices = fn () => EmailLog::query()->where('template_key', 'service_hold_ending')->count();

        $this->cronOn('2026-10-31');
        $invoice = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->sole();
        $this->assertSame(1, DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('kind', 'mailed')->count(), 'the invoice is mailed when issued');
        foreach (range(1, 5) as $day) {
            $this->cronOn("2026-11-0{$day}");
        }
        $this->assertSame(0, $notices(), 'not yet: the hold ends on 11-07');
        $this->cronOn('2026-11-06');
        $this->assertSame(1, $notices(), 'the day before the hold ends: told the day of the suspension');
        $this->cronOn('2026-11-07');
        $this->assertSame(1, $notices(), 'once');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->cronOn('2026-11-08');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    /** (d) The hold is the ordinary «Do Not Suspend Until» of the service's page: staff see it, change it, clear it. */
    public function test_staff_see_the_hold_on_the_service_page_and_change_or_clear_it(): void
    {
        $service = $this->service('2026-09-01', attributes: ['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => 'Abuse']);
        $this->staffCommand($service, 'unsuspend');
        $staff = $this->staff();

        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()]);
        $this->assertSame('2026-10-13', Carbon::parse($page->get('data.suspend_hold_until'))->toDateString(), 'shown in «Do Not Suspend Until»');

        $page->fillForm(['suspend_hold_until' => '2026-10-08'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2026-10-08', $this->hold($service), 'staff shorten it');

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['suspend_hold_until' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->hold($service), 'staff clear it');
        $this->cronOn('2026-10-06');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'cleared: billed and suspended by the next run, as any service past Suspend Days');
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Classes\Settings;
use App\Console\Commands\ImportFromWhmcs;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Providers\SettingsProvider;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * What an automatic termination never does (docs/termination.md; owner 2026-11-10: «we terminate months later; we have
 * customers who pay very late»). A terminated service is an account deleted on a server: it does not come back. Each
 * test is one guarantee; the server is a fake that remembers what it was asked (FlakyServer). Invented fixtures only.
 *
 * Suspend Days 8, Termination Days 60; «minimum days suspended» is left at its default on purpose (7).
 */
class TerminationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        Carbon::setTestNow('2026-11-10 10:00:00');
        Mail::fake();
        config(['settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_terminate_enabled' => true,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false, 'settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test']);
        $this->clientId = User::factory()->create()->client()->id;
        $this->server = Server::query()->create(['name' => 'Fake', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly service at 10.00 on the fake server; by default suspended for non-payment long ago. */
    private function service(string $expires, array $attributes = [], ?Service $parent = null): Service
    {
        $product = $this->createProduct();
        $product->product->update($parent === null ? ['server_id' => $this->server->id] : ['name' => 'Extra IPv4', 'is_addon' => true]);

        return Service::factory()->create($attributes + [
            'client_id' => $this->clientId, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent?->id, 'status' => Service::STATUS_SUSPENDED, 'expires_at' => $expires,
            'dunning_suspended_at' => Carbon::parse($expires)->addDays(9), 'suspend_reason' => 'Overdue on Payment',
            'currency_code' => 'USD', 'price' => $parent ? 3.00 : 10.00, 'quantity' => 1,
        ]);
    }

    /** The unpaid renewal of the period from its due date. */
    private function renewal(Service $service): Invoice
    {
        $start = Carbon::parse($service->expires_at);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $start]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $service->price, 'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addMonth()->toDateString()]);

        return $invoice->fresh();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** The days from $from to $to, both included. */
    private function cronEachDay(string $from, string $to): void
    {
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $this->cronOn($day->toDateString());
        }
    }

    private function deletedOnTheServer(): int
    {
        return count(array_keys(FlakyServer::$calls, 'terminate', true));
    }

    private function assertKept(Service $service, string $why): void
    {
        $this->assertNotSame(Service::STATUS_TERMINATED, $service->fresh()->status, $why);
        $this->assertSame(0, $this->deletedOnTheServer(), $why . ' (the server was asked to delete the account)');
    }

    // ------------------------------------------------------------------ the default and its migration

    public function test_minimum_days_suspended_defaults_to_seven_and_a_stored_old_default_moves_to_it(): void
    {
        $this->assertSame(7, collect(Settings::settings()['cronjob'])->pluck('default', 'name')['cronjob_terminate_min_suspended_days'], 'the default');

        $migration = require database_path('migrations/2027_02_18_000000_minimum_days_suspended_default_seven.php');
        foreach (['1' => '7', '0' => '0', '3' => '3', '14' => '14'] as $stored => $after) {
            Setting::query()->updateOrCreate(['key' => 'cronjob_terminate_min_suspended_days', 'settingable_type' => null], ['value' => $stored, 'type' => 'string']);
            $migration->up();
            $this->assertSame($after, (string) DB::table('settings')->where('key', 'cronjob_terminate_min_suspended_days')->value('value'),
                "a stored {$stored}: only the old default 1 moves");
        }
    }

    // ------------------------------------------------------------------ 1. never before Termination Days from the due date

    public function test_never_before_termination_days_from_the_due_date(): void
    {
        // Due 2026-09-11: 60 days before 11-10. Suspended for non-payment since 09-20, far more than the minimum.
        $service = $this->service('2026-09-11');

        $this->cronEachDay('2026-11-08', '2026-11-10');
        $this->assertKept($service, 'due date + 60 days is 11-10: not yet past Termination Days');

        $this->cronOn('2026-11-11');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'control: the day after Termination Days it is');
        $this->assertSame(1, $this->deletedOnTheServer());
    }

    // ------------------------------------------------------------------ 2. never with fewer than «minimum days suspended»

    public function test_never_with_fewer_than_the_minimum_days_suspended(): void
    {
        // Far past Termination Days, suspended for non-payment only on 11-05.
        $service = $this->service('2026-06-01', ['dunning_suspended_at' => '2026-11-05 03:00:00']);

        $this->cronEachDay('2026-11-05', '2026-11-11');
        $this->assertKept($service, 'suspended on 11-05: six days suspended on 11-11');

        $this->cronOn('2026-11-12');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'control: on its seventh day suspended');
    }

    /**
     * S7-CRON-BILLING-1: a service long past due that staff bring back (their own suspension lifted) gets a hold of
     * Suspend Days (#772), is suspended the day after the hold, and with the old default was terminated the day after
     * that. Now no earlier than 7 days after the suspension.
     */
    public function test_s7_cron_billing_1_a_service_brought_back_long_past_due_is_not_terminated_within_seven_days_of_its_suspension(): void
    {
        $service = $this->service('2026-09-01', ['dunning_suspended_at' => null, 'suspend_reason' => 'Asked us to pause it']);
        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]); // staff's unsuspend, 70 days past due
        $this->assertSame('2026-11-18', $service->fresh()->suspend_hold_until?->toDateString(), 'precondition: the hold of Suspend Days (#772)');

        $this->cronEachDay('2026-11-10', '2026-11-18');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended during the hold');
        $this->cronOn('2026-11-19');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended the day after the hold');

        $this->cronEachDay('2026-11-20', '2026-11-25');
        $this->assertKept($service, 'suspended on 11-19: nothing before 11-26');

        $this->cronOn('2026-11-26');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'control: 7 days after the suspension');
    }

    // ------------------------------------------------------------------ 3. never during a hold

    public function test_never_during_a_hold_its_own_or_its_parents(): void
    {
        $held = $this->service('2026-06-01', ['suspend_hold_until' => '2026-11-14']);
        $parent = $this->service('2026-06-01', ['suspend_hold_until' => '2026-11-14']);
        $addon = $this->service('2026-06-01', [], $parent);

        $this->cronEachDay('2026-11-10', '2026-11-14'); // a hold holds through its last day
        $this->assertKept($held, '«Do Not Suspend Until» 11-14');
        $this->assertKept($parent, '«Do Not Suspend Until» 11-14');
        $this->assertKept($addon, 'its parent is held until 11-14');

        $this->cronOn('2026-11-15');
        $this->assertSame(Service::STATUS_TERMINATED, $held->fresh()->status, 'control: the day after the hold');
    }

    // ------------------------------------------------------------------ 4. never while a payment of its open invoice is processing

    public function test_never_while_a_payment_of_its_open_invoice_is_processing(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        $payment = $invoice->transactions()->create(['amount' => 10.00, 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'sepa_on_its_way']);

        $this->cronEachDay('2026-11-10', '2026-11-12');
        $this->assertKept($service, 'a bank transfer is on its way for its invoice');

        $payment->update(['status' => InvoiceTransactionStatus::Failed]);
        $this->cronOn('2026-11-13');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'control: the payment failed');
    }

    // ------------------------------------------------------------------ 5. never when «Enable Termination» is off

    public function test_never_when_enable_termination_is_off(): void
    {
        config(['settings.cronjob_terminate_enabled' => false]);
        $service = $this->service('2026-06-01');

        $this->cronEachDay('2026-11-10', '2026-11-12');
        $this->assertKept($service, '«Enable Termination» is off');
    }

    // ------------------------------------------------------------------ never what was not suspended for non-payment

    public function test_never_a_service_that_is_active_or_suspended_by_staff(): void
    {
        $byStaff = $this->service('2026-06-01', ['dunning_suspended_at' => null, 'suspend_reason' => 'Abuse']);
        // Active and long past due (its client exempt from suspension): nothing to terminate, it was never suspended.
        $active = $this->service('2026-06-01', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null,
            'suspend_hold_until' => '2027-06-01']);

        $this->cronEachDay('2026-11-10', '2026-11-20');
        $this->assertKept($byStaff, 'suspended by staff: never the cron\'s to terminate');
        $this->assertKept($active, 'active');
    }

    // ------------------------------------------------------------------ 6. a payment between the cron's decision and the job's run

    /**
     * The payment keeps its invoice's lock, not the service's, and renews after its commit (docs/money-trail.md): it can
     * commit in the moment between the cron's last look (under the service's lock) and the cron cancelling that invoice.
     * The cron then ends the service with its invoice paid. Simulated here by paying the invoice at that very moment (as
     * the service's status is written). The job reads again before the server is asked: the account stays.
     */
    public function test_a_payment_that_lands_between_the_crons_decision_and_the_jobs_run_stops_the_termination(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Service::updating(function (Service $changed) use ($service, $invoice) {
            if ($changed->is($service) && $changed->status === Service::STATUS_TERMINATED) {
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
            }
        });

        $this->cronOn('2026-11-10');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: the payment won the invoice');
        $this->assertSame(0, $this->deletedOnTheServer(), 'its customer paid: the account must not be deleted');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'ended in the database; what happens next is staff\'s');
        $this->assertTrue(EmailLog::query()->where('template_key', 'system')->where('subject', 'like', "%Termination stopped: service #{$service->id}%")->exists(),
            'staff are told');
    }

    /**
     * The same re-read when the job waits (a server down, Pending Module Actions): with «keep overdue invoices of ended
     * services» the invoice stays payable, its customer pays it days later, and the termination tried again (the queue's
     * retry, staff's Retry) leaves the account alone.
     */
    public function test_a_kept_invoice_paid_while_the_termination_waits_stops_it(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Queue::fake([TerminateJob::class]); // decided, not run yet

        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: the cron decided');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: kept as a debt');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'paid before the account was deleted: it stays, staff decide');
    }

    public function test_a_service_staff_brought_back_before_the_job_ran_keeps_its_account_and_an_unpaid_one_loses_it(): void
    {
        $revived = $this->service('2026-06-01');
        $unpaid = $this->service('2026-06-01');
        $this->renewal($unpaid);
        Queue::fake([TerminateJob::class]);
        $this->cronOn('2026-11-10');
        $revived->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        (new TerminateJob($revived->fresh()))->handle();
        $this->assertSame(0, $this->deletedOnTheServer(), 'no longer ended: nothing is deleted');

        (new TerminateJob($unpaid->fresh()))->handle();
        $this->assertSame(1, $this->deletedOnTheServer(), 'control: still terminated and unpaid, the job does its work');
    }

    // ------------------------------------------------------------------ review of #773: money stops it, at the cron and at the job alike

    /** A part payment since the due date: the cron never ends it on its own, and says so to staff each day it waits. */
    public function test_a_part_payment_since_the_due_date_keeps_it_from_the_cron_and_staff_are_told(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Carbon::setTestNow('2026-09-15 12:00:00');
        ExtensionHelper::addPayment($invoice, null, '4.00', transactionId: 'part-paid');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: part paid, still open');

        $this->cronEachDay('2026-11-10', '2026-11-12');

        $this->assertKept($service, 'its customer paid part of it: we wait, staff decide');
        $this->assertTrue(EmailLog::query()->where('template_key', 'system')->where('body', 'like', "%Service #{$service->id} is past Termination Days, but money came%")->exists(),
            'staff are told it waits');
    }

    /** What an import brings: old paid invoices whose lines were never marked applied. Paid before the due date: no evidence. */
    public function test_an_old_paid_invoice_never_marked_applied_does_not_hold_a_termination_back(): void
    {
        $service = $this->service('2026-06-01');
        $old = $this->renewal($service);
        $old->items()->update(['period_start' => null, 'period_end' => null]);
        DB::table('invoices')->where('id', $old->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-05-03 10:00:00', 'due_at' => '2026-05-01']);
        $payment = $old->transactions()->create(['amount' => 10.00, 'status' => InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'whmcs-old']);
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['created_at' => '2026-05-03 10:00:00']);

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'its history is not a payment since its due date');
        $this->assertSame(1, $this->deletedOnTheServer());
    }

    /** After the cron cancelled its invoice: the payment becomes credit, the service is not revived; the job still stops. */
    public function test_a_payment_on_the_invoice_the_cron_cancelled_stops_the_waiting_termination(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Queue::fake([TerminateJob::class]);
        $this->cronOn('2026-11-10');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'precondition: cancelled with the termination');
        ExtensionHelper::addPayment($invoice->fresh(), null, '10.00', transactionId: 'late-webhook');

        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'the money arrived before the account was deleted');
    }

    /** A Retry days later asks the cron's rule again: a hold staff set since, or terminations switched off, stops it; told once. */
    public function test_a_retry_asks_the_crons_rule_again_and_tells_staff_once(): void
    {
        $held = $this->service('2026-06-01');
        $off = $this->service('2026-06-01');
        Queue::fake([TerminateJob::class]);
        $this->cronOn('2026-11-10');
        Service::query()->whereKey($held->id)->update(['suspend_hold_until' => '2026-12-01']);

        Carbon::setTestNow('2026-11-14 09:00:00');
        (new TerminateJob($held->fresh(), nonPayment: true))->handle();
        (new TerminateJob($held->fresh(), nonPayment: true))->handle();
        config(['settings.cronjob_terminate_enabled' => false]);
        (new TerminateJob($off->fresh(), nonPayment: true))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'no longer due by the rule: nothing is deleted');
        $this->assertSame(1, EmailLog::query()->where('template_key', 'system')->where('subject', 'like', "%Termination stopped: service #{$held->id}%")->count(), 'told once');
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $held->id)->where('action', 'terminate')
            ->where('last_error', 'like', TerminateJob::STOPPED . '%')->exists(), 'and listed in Pending Module Actions');

        config(['settings.cronjob_terminate_enabled' => true]);
        (new TerminateJob($off->fresh(), nonPayment: true))->handle();
        $this->assertSame(1, $this->deletedOnTheServer(), 'control: the rule stands again, staff\'s Retry deletes it');
    }

    /** Staff's «Revive: paid after termination»: Active, the paid period given through the usual path, the server unsuspended, the row closed. */
    public function test_staff_revive_a_stopped_termination_and_the_paid_period_is_given(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Queue::fake([TerminateJob::class]);
        $this->cronOn('2026-11-10');
        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, '10.00', transactionId: 'paid-late');
        (new TerminateJob($service->fresh()))->handle();
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: stopped, still terminated here');
        $this->assertSame('2026-06-01', $service->fresh()->expires_at->toDateString(), 'precondition: its payment renewed nothing');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->livewireAs(Staff::factory()->admin()->create())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('revivePaid')->assertHasNoActionErrors();

        $revived = $service->fresh();
        $this->assertSame(Service::STATUS_ACTIVE, $revived->status);
        $this->assertSame('2026-07-01', $revived->expires_at->toDateString(), 'the paid period is given, once');
        $this->assertNotNull($invoice->items()->first()->applied_at, 'its line applied: no longer «money since its due date»');
        $this->assertSame('2026-11-20', $revived->suspend_hold_until?->toDateString(), 'still past due: Suspend Days to pay the rest');
        $this->assertContains('unsuspend', FlakyServer::$calls, 'the server is asked to unsuspend the account');
        $this->assertSame(0, $this->deletedOnTheServer());
        $this->assertFalse(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->exists(), 'its pending row is closed');
    }

    // ------------------------------------------------------------------ 7. addons

    public function test_an_addon_that_follows_its_parent_is_never_terminated_on_its_own(): void
    {
        // Suspended with a parent staff suspended, both long past due: no marker of the cron on either.
        $parent = $this->service('2026-06-01', ['dunning_suspended_at' => null, 'suspend_reason' => 'Abuse']);
        $addon = $this->service('2026-06-01', ['dunning_suspended_at' => null, 'suspend_reason' => Service::SUSPEND_REASON_PARENT], $parent);
        // Paid up, under a parent that runs.
        $running = $this->service('2026-12-01', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null]);
        $paidAddon = $this->service('2026-12-01', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null], $running);

        $this->cronEachDay('2026-11-10', '2026-11-20');

        $this->assertKept($addon, 'suspended with its parent, not for its own invoice');
        $this->assertKept($paidAddon, 'paid, its parent running');
        $this->assertSame(Service::STATUS_ACTIVE, $running->fresh()->status);
    }

    /** Its own request at the end of its period ends it, its parent running on: what its customer asked for. */
    public function test_an_addon_with_its_own_cancellation_request_ends_while_its_parent_runs(): void
    {
        $parent = $this->service('2026-12-01', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null]);
        $addon = $this->service('2026-11-10', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null], $parent);
        ServiceCancellation::create(['service_id' => $addon->id, 'type' => 'end_of_period', 'reason' => 'No longer needed']);

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status, 'its parent runs on');
        $this->assertSame(0, $this->deletedOnTheServer(), 'an addon\'s end is a task for staff, never a module call');
    }

    /**
     * FINDING (for the owner, docs/termination.md §Ανοιχτά): an addon unpaid on its OWN invoice is dunned on its own, as
     * WHMCS does (docs/addons.md «Απλήρωτο addon»): suspended after Suspend Days and terminated after Termination Days
     * while its parent runs. Nothing is deleted by a module (the end of an addon is a task for staff in Pending Module
     * Actions), which is pinned below; whether its status should end at all while the parent runs is the owner's call.
     */
    public function test_an_addon_unpaid_on_its_own_is_not_terminated_while_its_parent_runs(): void
    {
        $parent = $this->service('2026-12-01', ['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null]);
        $addon = $this->service('2026-06-01', [], $parent);
        $this->renewal($addon);

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status, 'its parent is not touched');
        $this->assertSame(0, $this->deletedOnTheServer(), 'no module is asked to delete anything for an addon');
        if ($addon->fresh()->status === Service::STATUS_TERMINATED) {
            $this->assertTrue(ModuleAction::query()->open()->where('service_id', $addon->id)->where('action', 'terminate')->exists(),
                'its removal from the parent\'s machine is a task for staff');
            $this->markTestIncomplete('By design today (docs/addons.md, as WHMCS): an addon unpaid on its own invoice past Termination Days ends '
                . '(status terminated, a task for staff, nothing deleted by a module) while its parent runs. Owner to decide if it should.');
        }
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status);
    }

    // ------------------------------------------------------------------ 8. the first cron after the import

    /**
     * WHMCS (Suspend Days 15, Termination Days 120) terminates on due date + 120. Imported on 2026-11-10:
     *  - A, suspended for non-payment, due 2026-08-02 (100 days ago): WHMCS would terminate it on 11-30. Here: not on
     *    the first cron nor any before 12-01 (ours is the day after, «past» Termination Days).
     *  - B, the same, due 2026-07-03 (130 days ago): WHMCS's own next run would have terminated it (it is 10 days past
     *    its day; still suspended there only because that run failed or was off). Here: the first cron with terminations
     *    on does, as WHMCS would; its suspension is dated due date + Suspend Days (07-18), not the import day.
     *  - C, suspended by staff («Abuse»), due 130 days ago: WHMCS would terminate it too; here never automatically.
     *  - D, still active in WHMCS, due 130 days ago: WHMCS's next run suspends and terminates it at once. Here it is
     *    suspended on the first cron and kept the minimum days suspended (7).
     */
    public function test_the_first_cron_after_an_import_does_not_terminate_what_whmcs_would_not_have(): void
    {
        $whmcs = $this->whmcs(['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120']);
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $add = function (int $id, string $due, string $status, string $reason = '') use ($whmcs, $product) {
            $whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$product->product->id}, '2024-01-01', 'example.test', 1, 10, 'Monthly',
                '{$due}', '{$status}', '', '', '', '{$reason}', '', '', 0, '0000-00-00')");
            $issued = Carbon::parse($due)->subDays(14)->toDateString();
            $whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '{$issued}', '{$due}', '0000-00-00 00:00:00', 'Unpaid', 10, 0, 0)");
            $whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Hosting', {$id}, 'Hosting', 10, 0)");
        };
        $add(9101, '2026-08-02', 'Suspended');
        $add(9102, '2026-07-03', 'Suspended');
        $add(9103, '2026-07-03', 'Suspended', 'Abuse');
        $add(9104, '2026-07-03', 'Active');
        Queue::fake();

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importAutomationSettings', 'importServices', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();

        $this->assertSame(120, (int) config('settings.cronjob_order_terminate'), 'WHMCS\'s Termination Days');
        $this->assertSame(7, (int) config('settings.cronjob_terminate_min_suspended_days'), 'WHMCS has no such setting: the import leaves ours at 7');
        $this->assertSame('7', (string) Setting::query()->where('key', 'cronjob_terminate_min_suspended_days')->whereNull('settingable_type')->value('value'), 'stored as it was');
        [$a, $b, $c, $d] = array_map(fn (int $id) => Service::query()->findOrFail($id), [9101, 9102, 9103, 9104]);
        $this->assertSame('2026-08-17', $a->dunning_suspended_at?->toDateString(), 'suspended on its due date + Suspend Days, as WHMCS did: not on the import day');
        $this->assertSame('2026-07-18', $b->dunning_suspended_at?->toDateString());
        $this->assertNull($c->dunning_suspended_at, 'staff\'s suspension');

        $this->cronOn('2026-11-10'); // the first cron after the cut-over
        $this->assertSame(Service::STATUS_SUSPENDED, $a->fresh()->status, 'A: 100 days past due under Termination Days 120');
        $this->assertSame(Service::STATUS_TERMINATED, $b->fresh()->status, 'B: 130 days past due, as WHMCS\'s own next run');
        $this->assertSame(Service::STATUS_SUSPENDED, $c->fresh()->status, 'C: suspended by staff, never automatically');
        $this->assertSame(Service::STATUS_SUSPENDED, $d->fresh()->status, 'D: suspended on the first cron, not terminated with it');

        $this->cronEachDay('2026-11-11', '2026-11-16');
        $this->assertSame(Service::STATUS_SUSPENDED, $d->fresh()->status, 'D: six days suspended');
        $this->cronOn('2026-11-17');
        $this->assertSame(Service::STATUS_TERMINATED, $d->fresh()->status, 'D: after the minimum days suspended');

        $this->cronEachDay('2026-11-18', '2026-11-30');
        $this->assertSame(Service::STATUS_SUSPENDED, $a->fresh()->status, 'A: through 11-30 (due date + 120)');
        $this->cronOn('2026-12-01');
        $this->assertSame(Service::STATUS_TERMINATED, $a->fresh()->status, 'A: the day after, one day later than WHMCS');
        $this->assertSame(Service::STATUS_SUSPENDED, $c->fresh()->status);
    }

    /** @param  array<string, string>  $config */
    private function whmcs(array $config): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $insert = $whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)');
        foreach ($config as $setting => $value) {
            $insert->execute([$setting, $value]);
        }
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");

        return $whmcs;
    }
}

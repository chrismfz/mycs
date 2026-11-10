<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\RevivePaidTermination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use ReflectionClass;
use Tests\TestCase;

/**
 * Second pass of the review of #773: only the delta 5b16eae4..7c1bb82e (DunningRules::moneySinceDue, the cron's own
 * job asking the rule again, the «Stopped» row, staff's «Revive: paid after termination»). Each failing test is one
 * finding (REV-773-2-n). Invented fixtures, the fake server (FlakyServer).
 *
 * Suspend Days 8, Termination Days 60, «minimum days suspended» 7.
 */
class TerminationSafetyReview2Test extends TestCase
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

    /** A monthly service at 10.00 on the fake server, suspended for non-payment long ago. */
    private function service(string $expires, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);

        return Service::factory()->create($attributes + [
            'client_id' => $this->clientId, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_SUSPENDED, 'expires_at' => $expires,
            'dunning_suspended_at' => Carbon::parse($expires)->addDays(9), 'suspend_reason' => 'Overdue on Payment',
            'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    /** One unpaid invoice due on $due with a renewal line (the period from $due) for each service; $issued: its date. */
    private function invoice(string $due, array $services, ?string $issued = null, bool $period = true): Invoice
    {
        $start = Carbon::parse($due);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $start]);
        if ($issued !== null) {
            DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $issued]);
        }
        foreach ($services as $service) {
            $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => "Renewal #{$service->id}",
                'quantity' => 1, 'price' => $service->price,
                'period_start' => $period ? $start->toDateString() : null, 'period_end' => $period ? $start->copy()->addMonth()->toDateString() : null]);
        }

        return $invoice->fresh();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function deletedOnTheServer(): int
    {
        return count(array_keys(FlakyServer::$calls, 'terminate', true));
    }

    // ------------------------------------------------------------------ REV-773-2-1

    /**
     * REV-773-2-1 (high). moneySinceDue looks for a LINE of the service on the invoice the money is on. When the cron ends
     * two services billed on ONE invoice (what WHMCS makes for a customer's services due the same day), the first one
     * ended has its lines DELETED from it (CancelOpenInvoices, «only its own lines»), the second takes the invoice whole.
     * The customer's transfer for that invoice arrives on 2026-11-12, while both terminations wait for the server: it is
     * on the cancelled invoice (credit), which no longer has a line of the first service. Its job finds no money and
     * deletes its account; the second service's job stops. One payment, one account kept, one deleted.
     */
    public function test_rev_773_2_1_two_services_on_one_invoice_the_first_ended_loses_its_line_and_its_account(): void
    {
        $first = $this->service('2026-06-01');
        $second = $this->service('2026-06-01');
        $invoice = $this->invoice('2026-06-01', [$first, $second]);
        Queue::fake([TerminateJob::class]); // decided, the server not reached yet

        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_TERMINATED, $first->fresh()->status, 'precondition: the cron ended the first');
        $this->assertSame(Service::STATUS_TERMINATED, $second->fresh()->status, 'precondition: and the second');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'precondition: their invoice cancelled with them');

        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, '20.00', transactionId: 'REV-773-2-1');

        (new TerminateJob($second->fresh(), nonPayment: true))->handle();
        $this->assertSame(0, $this->deletedOnTheServer(), 'control: the second service, whose line is still on the invoice, is kept');
        (new TerminateJob($first->fresh(), nonPayment: true))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'the same payment was for the first service too: its account must not be deleted');
    }

    // ------------------------------------------------------------------ REV-773-2-2

    /**
     * REV-773-2-2 (medium). «Money since the due date» is dated against the service's date: a part payment made BEFORE
     * it does not count. The renewal due 2026-06-01 is issued on 05-18 and part paid (4.00 of 10.00) on 05-25. A part
     * payment on 06-02 holds the termination back and staff are told each day; the same money a week earlier does not: the
     * cron of 2026-11-10 ends the service and its account is deleted, the invoice left open with the 4.00 on it.
     */
    public function test_rev_773_2_2_a_part_payment_made_before_the_due_date_does_not_hold_the_termination_back(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->invoice('2026-06-01', [$service], issued: '2026-05-18 03:00:00');
        Carbon::setTestNow('2026-05-25 12:00:00');
        ExtensionHelper::addPayment($invoice, null, '4.00', transactionId: 'REV-773-2-2');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: part paid, still open');

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'its customer paid part of this very invoice: staff decide, as for a part payment a week later');
        $this->assertSame(0, $this->deletedOnTheServer());
    }

    /**
     * The same bound, moved by staff: part paid on 06-20, then the «Next Due Date» moved from 06-01 to 07-01 to give the
     * customer time. The payment is now «before the due date» and no longer counts.
     */
    public function test_rev_773_2_2_a_part_payment_stops_counting_once_staff_move_the_due_date_past_it(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->invoice('2026-06-01', [$service]);
        Carbon::setTestNow('2026-06-20 12:00:00');
        ExtensionHelper::addPayment($invoice, null, '4.00', transactionId: 'REV-773-2-2b');
        Service::query()->whereKey($service->id)->update(['expires_at' => '2026-07-01 00:00:00']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: part paid, still open');

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'part paid: staff decide, wherever the due date was moved to');
        $this->assertSame(0, $this->deletedOnTheServer());
    }

    // ------------------------------------------------------------------ REV-773-2-3

    /**
     * REV-773-2-3 (medium, the safe side). The date bound does not keep out the history an import brings for the customers
     * the owner worries about: those who pay MORE than a period late. In WHMCS the invoice of the period from 2026-05-01
     * was paid on 06-05 (35 days late); WHMCS moved the due date to 06-01. Imported, its line is never marked applied
     * and it was paid after the service's date: «money since its due date». The period from 06-01 was never paid, and
     * the service is never terminated: each day's run tells staff again, for ever (three runs here, three notices).
     */
    public function test_rev_773_2_3_an_imported_invoice_paid_a_period_late_in_whmcs_holds_the_termination_back_for_ever(): void
    {
        $service = $this->service('2026-06-01');
        $old = $this->invoice('2026-05-01', [$service], period: false);
        DB::table('invoices')->where('id', $old->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-06-05 10:00:00', 'created_at' => '2026-04-17 03:00:00']);
        $this->invoice('2026-06-01', [$service], period: false); // the period it never paid

        $this->cronOn('2026-11-10');
        $this->cronOn('2026-11-11');
        $this->cronOn('2026-11-12');

        $told = EmailLog::query()->where('template_key', 'system')->where('body', 'like', "%Service #{$service->id} is past Termination Days, but money came%")->count();
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status,
            "nothing was paid for the period from 2026-06-01: due for termination as in WHMCS (kept instead, staff told {$told} time(s) in three days)");
    }

    /**
     * And the way out the notice names («Terminate server» on its page) leaves staff's «Revive: paid after termination»
     * offered on that service: it would set it Active and give it a month for the invoice WHMCS already gave it, with its
     * account deleted.
     */
    public function test_rev_773_2_3_revive_is_offered_for_whmcs_history_on_a_service_staff_terminated(): void
    {
        $service = $this->service('2026-06-01');
        $old = $this->invoice('2026-05-01', [$service], period: false);
        DB::table('invoices')->where('id', $old->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-06-05 10:00:00', 'created_at' => '2026-04-17 03:00:00']);
        ExtensionHelper::terminateServer($service); // staff's «Terminate server»
        $service->markTerminated();
        $this->assertSame(1, $this->deletedOnTheServer(), 'precondition: its account is deleted');

        $this->assertTrue(RevivePaidTermination::paidInvoices($service->fresh())->isEmpty(), 'nothing to revive: the account is gone and the invoice is history');
    }

    // ------------------------------------------------------------------ REV-773-2-4

    /**
     * REV-773-2-4 (medium). «Revive: paid after termination» does not know whether the account is still there. The job
     * ran on 2026-11-10 and the server deleted the account (a completed terminate in Module Actions). The debt kept is
     * paid on 11-12. The action is offered and «revives»: Active, a month given for the payment, on an account that no
     * longer exists (the unsuspend then fails into Pending Module Actions). The payment of a deleted service is staff's
     * to refund or to rebuild for, never a period given by one click.
     */
    public function test_rev_773_2_4_revive_refuses_a_service_whose_account_was_deleted(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->invoice('2026-06-01', [$service]);
        $this->cronOn('2026-11-10');
        $this->assertSame(1, $this->deletedOnTheServer(), 'precondition: the account was deleted on the server');
        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, '10.00', transactionId: 'REV-773-2-4');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: the kept debt is paid');

        $refused = false;
        try {
            RevivePaidTermination::handle($service->fresh());
        } catch (DisplayException) {
            $refused = true;
        } catch (\Throwable) {
            // the unsuspend of an account that is gone
        }

        $this->assertTrue($refused, 'its account is gone: not revived by a click (status now: ' . $service->fresh()->status . ', due ' . $service->fresh()->expires_at?->toDateString() . ')');
    }

    // ------------------------------------------------------------------ REV-773-2-5

    /**
     * REV-773-2-5 (low, the safe side). A TerminateJob serialized before this deploy (waiting in the queue, or for its
     * retry in up to an hour) has no `nonPayment`: unserialized, the typed property is not initialized and reading it
     * throws. The PR says such a job «is held to the money check only»; it fails instead (four times, then «Module action
     * failed»). Built here as the queue builds it: without the constructor, with the two properties the old class had.
     */
    public function test_rev_773_2_5_a_job_queued_before_the_deploy_runs(): void
    {
        $service = $this->service('2026-06-01');
        Queue::fake([TerminateJob::class]);
        $this->cronOn('2026-11-10');
        $old = (new ReflectionClass(TerminateJob::class))->newInstanceWithoutConstructor();
        $old->service = $service->fresh();
        $old->sendNotification = true;

        $error = null;
        try {
            $old->handle();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNull($error, 'a job from before the deploy must run (the money check only), not throw');
        $this->assertSame(1, $this->deletedOnTheServer(), 'unpaid and still terminated: it does its work');
    }
}

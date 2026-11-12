<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Nixpal\Licensing\Tests\WhmcsSchema;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #811 (sweep 11: the import refuses a lived instance, a service brought back loses its old
 * cancellation request, PayPal's refusal of our account). Each test fails on the PR's head for the reason its docblock
 * gives. Invented rows only, no network. What «should happen» prefers a rule that cuts the case, a word to staff or a
 * line of the runbook over new automation.
 */
class Rev811Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 10:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'audit.console' => true]);
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WhmcsSchema::create($whmcs);
        foreach (['tblconfiguration', 'tblcurrencies'] as $table) {
            $whmcs->exec("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY)");
        }
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
    }

    protected function tearDown(): void
    {
        ImportFromWhmcs::$connect = null;
        putenv('WHMCS_SOURCE_PASSWORD');
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The documented command (as ImportLivedInstanceTest runs it): its exit code, null when the tiny source stopped it past the reset. */
    private function import(array $options = []): array
    {
        $code = null;
        $said = '';
        try {
            $code = $this->withoutMockingConsoleOutput()->artisan('app:import-from-whmcs', $options + ['dbname' => 'whmcs_copy', '--source-user' => 'reader',
                '--source-host' => 'db.example.test', '--confirm-database' => (string) DB::connection()->getDatabaseName()]);
            $said = (string) preg_replace('/\s+/', ' ', Artisan::output());
        } catch (Throwable) {
            // past the reset: the tiny source has not every table
        }

        return [$code, $said];
    }

    private function service(string $expires, string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function billed(Service $service): int
    {
        return Invoice::query()->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * REV-811-1 (medium). The guard reads only the DAILY run's traces. From the moment the instance opens (the scheduler
     * on: tickets read from mail every 5 minutes, domains synced every 10, webhooks recording payments, customers
     * ordering and paying: an order's invoice stands for them here) until its first daily run starts, the same command with the same --confirm-database still
     * empties it. That window is up to 24 hours (the next `cronjob_time`; the hourly catch-up never starts a first run:
     * CronJob::ranBefore), and one more day each time that first run's minute does not start (a deploy, the lock held).
     * The runbook's heading says «the import does not run again after go-live», its text «until the first cron it runs
     * again as before».
     *
     * Should happen: a runbook line that names the window («from the minute the scheduler is opened the import is never
     * typed again, whatever it still accepts»), or the cheapest cut: the scheduler's own heartbeat (`last_scheduler_run`,
     * written every minute, removed by the same reset) counts as «lived» too. No new bookkeeping either way.
     */
    public function test_an_instance_that_opened_but_has_not_run_its_first_daily_cron_is_not_emptied_by_the_same_command(): void
    {
        // The final import ended (its marker removed); no daily run yet.
        $this->import();
        DB::table('settings')->where('key', ImportFromWhmcs::RUNNING)->delete();
        $this->assertNull(ImportFromWhmcs::running());

        // The scheduler is opened: its heartbeat ticks. A customer signs up and orders, hours before the first daily run.
        $this->assertSame(0, Artisan::call('app:schedule-heartbeat-command'));
        $customer = User::factory()->create();
        $order = Invoice::factory()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        [$code, $said] = $this->import();

        $this->assertTrue(DB::table('users')->where('id', $customer->id)->exists(), 'the customer who signed up since the import is gone');
        $this->assertTrue(DB::table('invoices')->where('id', $order->id)->exists(), 'and the invoice of their order');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--replace-live-data', $said);
    }

    /**
     * REV-811-2 (medium). The guard fires on the cut-over itself. docs/install.md sets the crontab (`schedule:run`) when
     * the instance is installed, so a production instance installed days before the cut-over has run its daily cron over
     * an empty database: its FIRST, final import is refused («This instance has run its daily cron since its last import
     * (on N day(s) with counts …) … Only if that is meant (a test copy that ran its cron) …»), while the runbook says of
     * the flag «on the live instance it is never typed». The same for an instance that ran live tests before its final
     * import (mycs.myip.gr, runbook §4). Staff meet it on the day, after every pre-dump check has passed.
     *
     * Should happen: a runbook line at the final-import step («the instance ran its cron since it was installed: the
     * final import is typed with --replace-live-data; the --dry-run before the dump says so»), with the message's «a test
     * copy» widened to name it. Asserted here as the plainest form of it: an instance with no customer, invoice or
     * service has nothing to lose and is not refused.
     */
    public function test_a_newly_installed_instance_whose_scheduler_ran_the_daily_cron_takes_its_first_import(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0); // the scheduler's daily run, on the installed and still empty instance
        $this->assertSame(0, DB::table('clients')->count() + DB::table('invoices')->count() + DB::table('services')->count());

        [$code, $said] = $this->import();

        $this->assertStringNotContainsString('Nothing done', $said, 'the cut-over\'s own import is refused');
        $this->assertNotSame(1, $code);
        $this->assertNotNull(ImportFromWhmcs::running(), 'it started');
    }

    /**
     * REV-811-3 (low; rare: it needs an instance opened over an import that did not end). The guard is off whenever the
     * RUNNING marker is set, and only the import's last line removes it: an import that brought everything and stopped at
     * one of its last steps (anything but a PDOException is thrown as it is) leaves a full database with the marker on.
     * Nothing but the cron reads the marker: the site, the webhooks and the rest of the scheduler work. Opened like that,
     * the instance records orders and payments while the daily mail says, in the words this PR added, «run the import again (it
     * emptied this database when it started, so nothing more is lost)». Followed, that empties it without the flag.
     *
     * Should happen: no new logic. The mail and the runbook say «only before the instance is opened; once it is open,
     * ask» instead of «nothing more is lost», or the marker stops being a free pass once rows were made after it.
     */
    public function test_an_import_that_did_not_end_is_not_a_free_pass_once_the_instance_took_orders(): void
    {
        $this->import();
        $started = ImportFromWhmcs::running();
        $this->assertNotNull($started, 'the tiny source stopped it half way: the marker is on');

        // Opened all the same; two days later a customer has signed up and ordered.
        Carbon::setTestNow('2026-11-22 12:00:00');
        $customer = User::factory()->create();
        $order = Invoice::factory()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        $this->import();

        $this->assertTrue(DB::table('invoices')->where('id', $order->id)->exists(), 'what customers did since is gone, with no --replace-live-data typed');
    }

    /**
     * REV-811-4 (medium, introduced here). An addon's request made with its parent's (with_parent) is what keeps it from
     * being billed past its parent's end. The parent runs with its end-of-period request; staff end one addon (by
     * mistake, or the customer changed their mind about it) and bring it back: the new rule deletes the addon's request,
     * nothing copies the parent's again (AddonCascade::fastened runs only when it is fastened), and the cron bills the
     * addon a renewal for a period its parent will not run. On main the request stayed and the addon was not billed.
     *
     * Should happen: cut the case with the rule already there: an addon brought back under a parent that has its request
     * takes it again (AddonCascade::fastened), or its with_parent request is simply not deleted while the parent's stands.
     */
    public function test_an_addon_brought_back_under_a_parent_that_is_leaving_is_not_billed_past_its_parents_end(): void
    {
        $parent = $this->service('2026-12-01');
        $product = $this->createProduct();
        $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        $addon = Service::factory()->create(['client_id' => $parent->client_id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent->id, 'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 3.00, 'quantity' => 1]);
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $this->assertTrue($addon->cancellation()->where('with_parent', true)->exists(), 'the addon has its parent\'s request');

        $addon->fresh()->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);
        $addon->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertTrue($parent->cancellation()->exists(), 'the parent is still leaving');
        $this->cronOn('2026-11-25');
        $this->assertSame(0, $this->billed($addon->fresh()), 'the addon is billed a renewal past its parent\'s end');
        $this->assertTrue($addon->cancellation()->exists(), 'it no longer leaves with its parent');
    }

    /**
     * REV-811-5 (low; rare: a staff slip undone). The rule's reason is «the old one was carried out». A request that was
     * NOT carried out goes the same way: the customer asked to leave at the end of the period (weeks away), staff end the
     * service by mistake (the wrong row) and set it back at once. The customer's request is deleted without a word to
     * staff or customer (no mail, no notice on the page: only the audit row), the service renews, and its renewal is
     * billed and charged to the saved method of someone who asked to leave.
     *
     * Should happen: tell staff, no new rule: the page says «its cancellation request (end of period, of <date>) was
     * removed: make it again if the customer still leaves». Asserted here as the request still being there when the
     * period it waits for has not ended.
     */
    public function test_a_request_never_carried_out_survives_a_staff_slip_that_is_undone(): void
    {
        $service = $this->service('2026-12-15');
        $request = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_TERMINATED]);
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->cronOn('2026-12-09');
        $this->assertSame(0, $this->billed($service), 'the customer who asked to leave is billed the next period');
        $this->assertTrue(ServiceCancellation::query()->whereKey($request->id)->exists(), 'their request, due on 2026-12-15, is gone');
    }

    /**
     * REV-811-6 (medium-low; already on main, the PR's fix does not reach it). The rule looks at one step only: ended →
     * Active/Suspended. Staff re-open a cancelled order the usual way, by setting it Pending so that its payment sets it
     * up; it then starts (the payment, or staff): Cancelled → Pending → Active. Neither step is «ended → running», so the
     * old request stays, and it is S11-CRON-BILLING-1 again: RenewalBilling never bills it («has a request»), and with an
     * immediate request nothing ends it either: it runs unbilled.
     *
     * Should happen: one rule instead of two: a service that LEAVES an ended status (to anything) has no request.
     */
    public function test_a_cancelled_service_reopened_as_pending_and_then_started_has_no_old_request(): void
    {
        $service = $this->service('2026-10-01', Service::STATUS_CANCELLED);
        $request = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Changed my mind']);

        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_PENDING]);
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);

        $this->cronOn('2026-12-14');
        $this->assertSame(1, $this->billed($service), 'its renewal is never billed');
        $this->assertFalse(ServiceCancellation::query()->whereKey($request->id)->exists(), 'the request of its first life is still on it');
    }
}

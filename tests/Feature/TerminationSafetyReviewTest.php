<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
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
 * Independent and adversarial review of #773 (termination safety). Each failing test is one finding (REV-773-n) of the
 * review's table; the passing ones pin what was checked and found sound, and the mass-termination count the owner asked
 * to see before choosing a brake. Invented fixtures, a fake server (FlakyServer) that remembers what it was asked.
 *
 * Suspend Days 8, Termination Days 60, «minimum days suspended» at its default (7).
 */
class TerminationSafetyReviewTest extends TestCase
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

    /**
     * The unpaid renewal of the period from its due date. $period false: a line as the WHMCS import brings a «Hosting»
     * line (ImportFromWhmcs::lineReference: «no kind, its period given when paid»): no period on it.
     */
    private function renewal(Service $service, bool $period = true, ?string $start = null): Invoice
    {
        $start = Carbon::parse($start ?? $service->expires_at);
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $start]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $service->price,
            'period_start' => $period ? $start->toDateString() : null, 'period_end' => $period ? $start->copy()->addMonth()->toDateString() : null]);

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

    private function stoppedMails(Service $service): int
    {
        return EmailLog::query()->where('template_key', 'system')->where('subject', 'like', "%Termination stopped: service #{$service->id}%")->count();
    }

    // ------------------------------------------------------------------ REV-773-1

    /**
     * REV-773-1 (high). The job's «was it paid?» reads `invoice_items.period_start >= services.expires_at`. A renewal the
     * WHMCS import brought has NO period on its line (it is given when the payment is applied), and the payment of an
     * ENDED service is never applied (RenewServiceService: «Payment for an ended service: not revived»), so the line
     * keeps no period and the job sees nothing paid. Every termination of the first Termination Days after the cut-over
     * (120 on myip, 60 on nixpal) is about such an invoice.
     *
     * Due 2026-06-01, imported invoice unpaid, «keep overdue invoices of ended services» on. The cron of 2026-11-10 ends
     * the service; the server is down, the job waits. Its customer pays the kept invoice in full on 2026-11-12 (the real
     * payment path). The termination is tried again on 2026-11-13.
     */
    public function test_rev_773_1_an_imported_invoice_paid_while_the_termination_waits_does_not_stop_it(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service, period: false);
        Queue::fake([TerminateJob::class]); // decided, the server not reached yet

        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: the cron decided');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: kept as a debt, payable');

        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'REV-773-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid in full');

        Carbon::setTestNow('2026-11-13 09:00:00');
        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'its customer paid the invoice in full the day before: the account must not be deleted');
        $this->assertSame(1, $this->stoppedMails($service), 'staff are told the termination stopped');
    }

    /** The same line in the PR's own race (the payment commits as the cron writes the status): the author's test, an imported line. */
    public function test_rev_773_1_an_imported_invoice_paid_as_the_cron_decides_does_not_stop_it(): void
    {
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service, period: false);
        Service::updating(function (Service $changed) use ($service, $invoice) {
            if ($changed->is($service) && $changed->status === Service::STATUS_TERMINATED) {
                DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
            }
        });

        $this->cronOn('2026-11-10');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: the payment won the invoice');
        $this->assertSame(0, $this->deletedOnTheServer(), 'its customer paid: the account must not be deleted');
    }

    /**
     * REV-773-1 through the importer itself, so the shape of the line is the import's and not this test's: imported on
     * 2026-11-10 (Termination Days 120), service 9102 suspended for non-payment, due 2026-07-03, its WHMCS invoice unpaid.
     * The first cron ends it (as WHMCS's next run would); the invoice is kept as a debt; paid in full two days later while
     * the termination waits for the server.
     */
    public function test_rev_773_1_through_the_importer_the_paid_whmcs_invoice_does_not_stop_the_waiting_termination(): void
    {
        $this->import(function (\Closure $add) {
            $add(9102, '2026-07-03', 'Suspended', 'Monthly');
        });
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = Service::query()->findOrFail(9102);
        $service->product->update(['server_id' => $this->server->id]);
        $invoice = Invoice::query()->findOrFail(9102);
        $this->assertNull($invoice->items()->firstOrFail()->period_start, 'precondition: an imported «Hosting» line has no period until its payment is applied');
        Queue::fake([TerminateJob::class]);

        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: 130 days past due, ended by the first cron');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: kept as a debt');

        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'REV-773-1-import');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid in full');

        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'paid in full before the account was deleted: it must stay');
    }

    // ------------------------------------------------------------------ REV-773-2

    /**
     * REV-773-2 (high). The same test misses a paid invoice whose period starts BEFORE the service's date. Staff gave the
     * customer two weeks by moving the «Next Due Date» from 2026-06-01 to 2026-06-15 (the WHMCS habit); the invoice of the
     * period from 06-01 stays the one to pay. Kept as a debt, paid in full while the job waits: `period_start` 06-01 is
     * not >= `expires_at` 06-15, so the job deletes the account of a customer who paid.
     */
    public function test_rev_773_2_a_paid_invoice_of_a_period_before_a_moved_due_date_does_not_stop_it(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-15');
        $invoice = $this->renewal($service, start: '2026-06-01');
        Queue::fake([TerminateJob::class]);

        $this->cronOn('2026-11-10');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: the cron decided');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: kept as a debt');

        Carbon::setTestNow('2026-11-12 09:00:00');
        ExtensionHelper::addPayment($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'REV-773-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid in full');

        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'its customer paid its one open invoice in full: the account must not be deleted');
    }

    // ------------------------------------------------------------------ REV-773-3

    /**
     * REV-773-3 (medium). The cron's step never terminates while a payment of an open invoice of the service is
     * processing (a bank transfer on its way, PAY-9). The job's re-read does not look: with the invoice kept as a debt, a
     * transfer started while the job waits for the server, and the Retry of the termination deletes the account with the
     * money on its way.
     */
    public function test_rev_773_3_a_payment_processing_on_the_kept_invoice_does_not_stop_the_waiting_termination(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        Queue::fake([TerminateJob::class]);

        $this->cronOn('2026-11-10');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: kept as a debt');
        $invoice->transactions()->create(['amount' => 10.00, 'status' => InvoiceTransactionStatus::Processing, 'transaction_id' => 'sepa_on_its_way']);

        (new TerminateJob($service->fresh()))->handle();

        $this->assertSame(0, $this->deletedOnTheServer(), 'a bank transfer for its invoice is on its way: the cron would wait, the job must too');
    }

    // ------------------------------------------------------------------ REV-773-4

    /**
     * REV-773-4 (medium). Suspension is never for a service on a one-time or free plan (DunningRules::suspendable: «no
     * period to pay»), and WHMCS's own termination skips «One Time» and «Free Account» whatever their date. terminable()
     * has no such exception. Imported on 2026-11-10 (Suspend Days 15, Termination Days 120): a «One Time» service still on
     * the date of its order (2026-07-03), suspended in WHMCS with no reason written and its order's invoice unpaid, gets
     * the cron's mark (due date + Suspend Days) and is terminated by the first cron. WHMCS never would: against the PR's
     * «the first cron after an import does not terminate what WHMCS would not».
     */
    public function test_rev_773_4_the_first_cron_terminates_an_imported_one_time_service_whmcs_never_would(): void
    {
        $this->import(function (\Closure $add) {
            $add(9201, '2026-07-03', 'Suspended', 'One Time');
        });

        $service = Service::query()->findOrFail(9201);
        $this->assertSame('one-time', $service->plan?->type, 'precondition: on its one-time plan');
        $this->assertSame('2026-07-03', $service->expires_at?->toDateString(), 'precondition: its WHMCS date kept');
        $this->assertNotNull($service->dunning_suspended_at, 'precondition: the import took it for a suspension for non-payment');

        $this->cronOn('2026-11-10'); // the first cron after the cut-over

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'a one-time plan has no period to pay: never dunned (as suspendable, as WHMCS)');
    }

    /** The same rule on a service of this instance: suspendable() exempts the plan, terminable() does not. */
    public function test_rev_773_4_a_one_time_service_carrying_the_mark_is_terminated(): void
    {
        $service = $this->service('2026-06-01');
        DB::table('plans')->where('id', $service->plan_id)->update(['type' => 'one-time']);

        $this->cronOn('2026-11-10');

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'a one-time plan has no period to pay: never dunned');
        $this->assertSame(0, $this->deletedOnTheServer());
    }

    // ------------------------------------------------------------------ REV-773-5

    /**
     * REV-773-5 (low). The «Termination stopped» mail is sent on every run of the job: each Retry from Pending Module
     * Actions (the row of the failed attempts stays open, the stop does not close it) mails staff again.
     */
    public function test_rev_773_5_the_stopped_termination_is_told_once_and_its_pending_row_does_not_stay_as_a_failure(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service('2026-06-01');
        $invoice = $this->renewal($service);
        FlakyServer::$failures = 1; // the server is down for the first attempt
        try {
            $this->cronOn('2026-11-10');
        } catch (\Throwable) {
            // the sync queue throws the job's failure
        }
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'precondition: the cron decided');
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->exists(),
            'precondition: its failed attempt is in Pending Module Actions');
        ExtensionHelper::addPayment($invoice->fresh(), null, (string) $invoice->fresh()->total, transactionId: 'REV-773-5');

        $row = ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->firstOrFail();
        $row->lifecycleJob()->handle(); // staff's Retry
        $row->lifecycleJob()->handle(); // and again, the row still listed

        $this->assertSame(0, $this->deletedOnTheServer() - 1, 'control: nothing deleted beyond the failed first attempt');
        $this->assertSame(1, $this->stoppedMails($service), 'told once, not on every Retry');
    }

    // ------------------------------------------------------------------ mass termination: what happens today (no brake)

    /**
     * NOT a defect of the PR: the count the owner asked for before choosing a brake (docs/termination.md «Φρένο»). Thirty
     * services suspended for non-payment whose Termination Days passed while the cron was down (or: the batch of the first
     * night after the cut-over). ONE run ends all thirty and asks the server to delete all thirty accounts in that same
     * run; nothing is said to staff before it, and after it one mail with a count («Cron Job: services 30 terminated»).
     */
    public function test_mass_termination_one_run_deletes_every_account_due_and_staff_hear_of_it_only_after(): void
    {
        $services = collect(range(1, 30))->map(fn () => $this->service('2026-06-01'));
        $before = EmailLog::query()->where('template_key', 'system')->count();

        $this->cronOn('2026-11-10');

        $this->assertSame(30, Service::query()->whereKey($services->pluck('id'))->where('status', Service::STATUS_TERMINATED)->count(), 'all thirty ended in one run');
        $this->assertSame(30, $this->deletedOnTheServer(), 'and all thirty accounts deleted on the server in that run: no limit, no approval');
        $after = EmailLog::query()->where('template_key', 'system')->where('id', '>', 0)->pluck('subject');
        $this->assertSame($before + 1, $after->count(), 'one mail to staff, after the fact');
        $this->assertStringContainsString('30 terminated', (string) $after->last());
    }

    // ------------------------------------------------------------------ the import, as the PR's own test builds it

    /**
     * WHMCS with Suspend Days 15 and Termination Days 120, imported on 2026-11-10: each service added with its own unpaid
     * invoice (same id), issued 14 days before its due date.
     *
     * @param  \Closure(\Closure(int, string, string, string): void): void  $rows
     * @return array{0: object}
     */
    private function import(\Closure $rows): array
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
        foreach (['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120'] as $setting => $value) {
            $whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)')->execute([$setting, $value]);
        }
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");

        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => Product::class, 'name' => 'One-Time', 'type' => 'one-time']);
        $rows(function (int $id, string $due, string $status, string $cycle) use ($whmcs, $product) {
            $whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$product->product->id}, '{$due}', 'example.test', 1, 10, '{$cycle}',
                '{$due}', '{$status}', '', '', '', '', '', '', 0, '0000-00-00')");
            $issued = Carbon::parse($due)->subDays(14)->toDateString();
            $whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '{$issued}', '{$due}', '0000-00-00 00:00:00', 'Unpaid', 10, 0, 0)");
            $whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->clientId}, 'Hosting', {$id}, 'Hosting', 10, 0)");
        });

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importAutomationSettings', 'importServices', 'importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();

        return [$product];
    }
}

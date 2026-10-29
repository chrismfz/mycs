<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of #518: after an import, does the first cron run suspend or terminate what WHMCS would (and only
 * that)? A WHMCS copy built here (no real data): tblconfiguration with the automation, tblhosting with the services, then
 * the import's steps, the settings read again as the next process does, and the daily cron run.
 */
class ImportAutomationSettingsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const AUTOMATION = ['AutoSuspension' => 'on', 'AutoSuspensionDays' => '15', 'AutoTermination' => 'on', 'AutoTerminationDays' => '120',
        'CreateInvoiceDaysBefore' => '14', 'DailyCronExecutionHour' => '10', 'CloseInactiveTickets' => '48'];

    private int $clientId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->clientId = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']); // the importer finds the plan by WHMCS's cycle
        $this->productId = $product->product->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The import's automation and services steps, then the settings as the next process reads them.
     *
     * @param  array<string, string>  $config
     * @param  list<array<string, mixed>>  $services
     */
    private function import(array $config, array $services): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $insert = $whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)');
        foreach ($config as $setting => $value) {
            $insert->execute([$setting, $value]);
        }
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
        $hosting = $whmcs->prepare('INSERT INTO tblhosting VALUES (:id, :userid, NULL, :packageid, :regdate, :domain, 1, 10, :billingcycle, :nextduedate,
            :domainstatus, \'\', \'\', \'\', :suspendreason, \'\', \'\', :overideautosuspend, :overidesuspenduntil)');
        foreach ($services as $service) {
            $hosting->execute(array_merge(['userid' => $this->clientId, 'packageid' => $this->productId, 'regdate' => '2024-01-01', 'domain' => 'example.test',
                'billingcycle' => 'Monthly', 'suspendreason' => '', 'overideautosuspend' => 0, 'overidesuspenduntil' => '0000-00-00'], $service));
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importAutomationSettings'))->invoke($command);
        (new ReflectionMethod($command, 'importServices'))->invoke($command);
        SettingsProvider::reload(); // the cron runs in its own process, after handle()'s flushCache
    }

    private function serviceStatus(int $id): string
    {
        return Service::query()->findOrFail($id)->status;
    }

    /**
     * ADV-518-1. WHMCS's «Override Auto-Suspend» (tblhosting.overideautosuspend, until overidesuspenduntil, or for good
     * with no date) keeps a service from its cron's suspension and termination: 170 «suspend until» and 36 «never
     * suspend» on myip (docs/whmcs-usage.md). The importer does not bring it (no suspend_hold_until), so the first cron run
     * after the cut-over suspends every one of them past Suspend Days, where WHMCS would not have.
     */
    public function test_adv_518_1_a_whmcs_suspend_override_is_not_suspended_by_the_first_cron_run(): void
    {
        $this->import(self::AUTOMATION, [
            // Due 50 days ago (Suspend Days 15), held by staff in WHMCS until the end of the year.
            ['id' => 7001, 'nextduedate' => '2026-10-01', 'domainstatus' => 'Active', 'overideautosuspend' => 1, 'overidesuspenduntil' => '2026-12-31'],
            // «Never suspend»: the override with no date.
            ['id' => 7002, 'nextduedate' => '2026-10-01', 'domainstatus' => 'Active', 'overideautosuspend' => 1, 'overidesuspenduntil' => '0000-00-00'],
        ]);

        $this->artisan('app:cron-job');

        $this->assertSame('active', $this->serviceStatus(7001), 'WHMCS: «do not suspend until 2026-12-31»');
        $this->assertSame('active', $this->serviceStatus(7002), 'WHMCS: never suspended (override with no date)');
    }

    /**
     * ADV-518-2. A service WHMCS suspended for non-payment, due 142 days ago with Termination Days 120: WHMCS's next run
     * terminates it. Imported, its dunning_suspended_at is the import's own time (now()), so «minimum days suspended» (1)
     * keeps it from the first cron run on the cut-over day: one day more than WHMCS, the import date standing in for a
     * suspension that is months old.
     */
    public function test_adv_518_2_a_service_whmcs_terminates_today_is_terminated_by_the_first_cron_run(): void
    {
        $this->import(self::AUTOMATION, [
            ['id' => 7003, 'nextduedate' => '2026-07-01', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment'],
        ]);

        $this->artisan('app:cron-job');

        $this->assertSame('terminated', $this->serviceStatus(7003), 'WHMCS terminates it on its run today (due 142 days ago, 120 set)');
    }

    /**
     * Guard (passes): the imported 120 days reach the cron in the next process; a service suspended for non-payment due
     * 100 days ago stays suspended (MyCS's default, 60, would end it).
     */
    public function test_guard_the_imported_termination_days_reach_the_cron(): void
    {
        $this->import(self::AUTOMATION, [
            ['id' => 7004, 'nextduedate' => '2026-08-12', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment'],
        ]);
        Service::query()->whereKey(7004)->update(['dunning_suspended_at' => '2026-08-27 10:00:00']);
        $this->assertSame(120, (int) config('settings.cronjob_order_terminate'));

        $this->artisan('app:cron-job');

        $this->assertSame('suspended', $this->serviceStatus(7004), '100 days past due, 120 set');
    }

    /** Guard (passes): WHMCS's Termination off («» stored) is off for the cron: '0' read as false, not as a string. */
    public function test_guard_termination_off_is_off_for_the_cron(): void
    {
        $this->import(['AutoTermination' => ''] + self::AUTOMATION, [
            ['id' => 7005, 'nextduedate' => '2026-03-01', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment'],
        ]);
        Service::query()->whereKey(7005)->update(['dunning_suspended_at' => '2026-03-20 10:00:00']);
        $this->assertFalse(config('settings.cronjob_terminate_enabled'));

        $this->artisan('app:cron-job');

        $this->assertSame('suspended', $this->serviceStatus(7005));
    }
}

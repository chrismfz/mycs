<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Service;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Invoice\PaymentReminders;
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
 * Adversarial review of #518, round 2: the fixes of round 1 (the «Override Auto-Suspend» as suspend_hold_until, 9999-12-31
 * for «never»; the suspension marker at due date + Suspend Days) and what they introduced, against what MyCS reads. A WHMCS
 * copy built here (no real data), the import's steps, the settings read again as the next process does, then the cron.
 */
class ImportAutomationSettingsAdversarialR2Test extends TestCase
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
     * @param  array<string, string>  $config
     * @param  list<array<string, mixed>>  $services
     */
    private function import(array $config, array $services = []): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend TEXT, overidesuspenduntil TEXT)');
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
                'billingcycle' => 'Monthly', 'suspendreason' => '', 'overideautosuspend' => '0', 'overidesuspenduntil' => '0000-00-00'], $service));
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importAutomationSettings'))->invoke($command);
        (new ReflectionMethod($command, 'importServices'))->invoke($command);
        SettingsProvider::reload(); // the cron runs in its own process, after handle()'s flushCache
    }

    private function service(int $id): Service
    {
        return Service::query()->findOrFail($id);
    }

    /**
     * ADV-518-R2-1. WHMCS's «Close Inactive Tickets» is in hours; the importer checks it with the days' cap (3,650) before
     * turning it into days. A company that closes tickets after a year (8,760 hours, 365 days) or anything past 152 days
     * keeps MyCS's own 5 days: the first cron run closes every answered ticket silent for 5 days, where WHMCS waited a year.
     */
    public function test_adv_518_r2_1_close_inactive_tickets_in_hours_is_capped_as_if_it_were_days(): void
    {
        $this->import(['CloseInactiveTickets' => '8760'] + self::AUTOMATION);

        $this->assertSame(365, (int) config('settings.cronjob_close_ticket'), 'WHMCS: 8,760 hours = 365 days; MyCS keeps its own 5');
    }

    /**
     * ADV-518-R2-2. The overdue reminder days take up to 3,650 here, but PaymentReminders reads at most MAX_DAYS (365) and
     * drops the rest without a word: a WHMCS «Third Overdue Reminder» of 400 days is imported as «1,3,400» and never sent
     * (and one of 1,000 or more is a value the Settings page itself refuses, \d{1,3}, so staff cannot save the page).
     */
    public function test_adv_518_r2_2_an_overdue_reminder_day_the_cron_ignores_is_imported_silently(): void
    {
        $this->import(['SendFirstOverdueInvoiceReminder' => '1', 'SendSecondOverdueInvoiceReminder' => '3',
            'SendThirdOverdueInvoiceReminder' => '400'] + self::AUTOMATION);

        $stored = array_map('intval', explode(',', (string) config('settings.cronjob_overdue_reminders')));
        $this->assertSame($stored, PaymentReminders::overdueDays(), 'every imported reminder day is one the cron sends');
    }

    /**
     * Guard (passes): «never» (9999-12-31) on a service WHMCS suspended for non-payment long past Termination Days: the
     * cron neither terminates it (SQLite's date comparison on the far date) nor does staff's unsuspension shorten the hold.
     */
    public function test_guard_never_hold_on_a_dunning_suspended_service_survives_the_cron_and_an_unsuspension(): void
    {
        $this->import(self::AUTOMATION, [
            ['id' => 8001, 'nextduedate' => '2026-01-01', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment',
                'overideautosuspend' => 'on', 'overidesuspenduntil' => ''],
        ]);
        $this->assertSame(ImportFromWhmcs::NEVER_SUSPEND, $this->service(8001)->suspend_hold_until->toDateString());
        $this->assertNotNull($this->service(8001)->dunning_suspended_at);

        $this->artisan('app:cron-job');
        $this->assertSame('suspended', $this->service(8001)->status, 'held for good: never terminated');

        $service = $this->service(8001);
        $service->update(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame(ImportFromWhmcs::NEVER_SUSPEND, $this->service(8001)->suspend_hold_until->toDateString());
        $this->assertFalse($this->service(8001)->stillOverdue(Carbon::parse('2026-01-01')));
    }

    /** Guard (passes): an override whose date is past (expired in WHMCS) holds nothing: the cron suspends as WHMCS would. */
    public function test_guard_an_expired_override_does_not_hold(): void
    {
        $this->import(self::AUTOMATION, [
            ['id' => 8002, 'nextduedate' => '2026-10-01', 'domainstatus' => 'Active', 'overideautosuspend' => '1', 'overidesuspenduntil' => '2026-11-01'],
        ]);
        $this->assertSame('2026-11-01', $this->service(8002)->suspend_hold_until->toDateString());

        $this->artisan('app:cron-job');

        $this->assertSame('suspended', $this->service(8002)->status);
    }

    /** Guard (passes): the marker is never in the future (due date ahead, or none): «now». */
    public function test_guard_the_marker_of_a_future_or_missing_due_date_is_now(): void
    {
        $this->import(self::AUTOMATION, [
            ['id' => 8003, 'nextduedate' => '2026-12-15', 'domainstatus' => 'Suspended', 'suspendreason' => 'overdue on payment '],
            ['id' => 8004, 'nextduedate' => '0000-00-00', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment'],
            ['id' => 8005, 'nextduedate' => '2026-11-01', 'domainstatus' => 'Suspended', 'suspendreason' => 'Overdue on Payment'],
        ]);

        $this->assertSame('2026-11-20 10:00:00', $this->service(8003)->dunning_suspended_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 10:00:00', $this->service(8004)->dunning_suspended_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16', $this->service(8005)->dunning_suspended_at->toDateString(), 'due + 15 Suspend Days');
    }
}

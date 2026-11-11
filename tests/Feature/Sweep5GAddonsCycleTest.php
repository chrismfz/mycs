<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Services\Import\WhmcsAddonImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use MyCS\BillingPeriod\BillingPeriod;
use Tests\TestCase;

/**
 * Sweep 5, adversarial verification of «g-addons-cycle» (the importer's «addon off its service's billing day» guard of
 * #757). Each test fails on main as reviewed (8d57c819). Fake data only.
 */
class Sweep5GAddonsCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $hosting  @param  list<string>  $addons */
    private function addonsApart(array $hosting, array $addons): array
    {
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');
        foreach ($hosting as $row) {
            $whmcs->exec("INSERT INTO tblhosting VALUES {$row}");
        }
        foreach ($addons as $row) {
            $whmcs->exec("INSERT INTO tblhostingaddons VALUES {$row}");
        }
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        return (new \ReflectionMethod($command, 'addonsApart'))->invoke($command);
    }

    /**
     * G-ADDONS-CYCLE-2, as it stands since 2026-11-10 (sweep 7 S7-LATEST-FIXES-1). A yearly addon of a monthly service on
     * the service's day of the month was accepted by the day check (the shape «Add addon» once left); since an addon has
     * its service's cycle (2026-11-09) and its date, it is refused twice: another cycle, and another date (ahead of its
     * service, no whole number of its periods behind it).
     */
    public function test_g_addons_cycle_2_a_yearly_addon_of_a_monthly_service_on_its_day_is_refused_now(): void
    {
        $this->assertNull(WhmcsAddonImport::periodsBehind('Annually', '2027-03-15', '2026-12-15'), 'ahead of its service');
        $this->assertSame([50], $this->addonsApart(
            ["(2, 'Active', 'Monthly', '2026-12-15')"],
            ["(50, 2, 'Active', 'Annually', '2027-03-15')"],
        ));
    }

    /**
     * G-ADDONS-CYCLE-3 (a). A live addon of a terminated service: the addon import ends it with its parent, but the guard,
     * blind to the service's status, compares it with the frozen due date of the ended service and stops the import.
     */
    public function test_g_addons_cycle_3_a_live_addon_of_a_terminated_service_does_not_stop_the_import(): void
    {
        $this->assertSame([], $this->addonsApart(
            ["(1700, 'Terminated', 'Annually', '2025-03-01')"],
            ["(180, 1700, 'Active', 'Annually', '2027-05-18')"],
        ), 'an addon imported terminated with its service has no billing day to share');
    }

    /**
     * G-ADDONS-CYCLE-5. A 31st anchor: the service's date 2027-02-28 (February's 31st), its monthly addon due 2027-01-31, a
     * month before: on the service's billing day (31), so the day check accepts it (the importer once counted back from
     * the 28th and refused it). Since 2026-11-10 (sweep 7 S7-LATEST-FIXES-1) the addon's date is its service's; the clamped
     * day is still what tells an addon whole periods behind its service (its own renewal unpaid): one month here.
     */
    public function test_g_addons_cycle_5_a_monthly_addon_on_a_31st_anchor_is_on_its_services_day(): void
    {
        $due = CarbonImmutable::parse('2027-02-28');
        $this->assertSame('2027-01-31', BillingPeriod::start($due, 'month', 1, 31)->toDateString(),
            'on the 31st, a month before 28 February is 31 January');
        $this->assertSame(1, WhmcsAddonImport::periodsBehind('Monthly', '2027-01-31', '2027-02-28'),
            'refused: counted back from the 28th, never the 31st');
    }

    /**
     * G-ADDONS-CYCLE-3 (b), fixer's rule: a recurring addon of a live service with no next due date (a free one) is refused,
     * as MyCS cannot give an addon to such a parent either; a live service's addon on another day is still refused.
     */
    public function test_g_addons_cycle_3_an_addon_of_a_live_service_without_a_due_date_is_refused(): void
    {
        $this->assertSame([181, 182], $this->addonsApart(
            ["(1701, 'Active', 'Free Account', '0000-00-00')", "(1702, 'Pending', 'Monthly', '2026-12-15')"],
            ["(181, 1701, 'Active', 'Monthly', '2026-12-01')", "(182, 1702, 'Active', 'Annually', '2027-03-14')"],
        ));
    }
}

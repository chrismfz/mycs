<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sweep 5, independent review of the «g-addons-cycle» fixes. Fake data only.
 */
class Rev5GAddonsCycleTest extends TestCase
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
     * REV5-G-ADDONS-CYCLE-1. The guard now skips every service outside WhmcsAddonImport::LIVE, as if it were ended. But a
     * WHMCS «Completed» service is imported pending here (ImportFromWhmcs::serviceStatus: an unnamed status is pending),
     * so its live addon is imported live, not ended with it (WhmcsAddonImport ends it only under a terminated or cancelled
     * parent). An addon of it off the service's day therefore gets into MyCS unchecked: the shape the guard exists to stop.
     * Before the fix the guard checked it.
     */
    public function test_rev5_an_addon_of_a_completed_service_imported_live_is_still_checked(): void
    {
        $this->assertSame('pending', ImportFromWhmcs::serviceStatus('Completed'), 'a Completed service is imported live here');
        $this->assertSame([190], $this->addonsApart(
            ["(1800, 'Completed', 'Monthly', '2026-12-15')"],
            ["(190, 1800, 'Active', 'Monthly', '2026-12-03')"],
        ), 'a live addon under a service imported live, on another day, passes the guard');
    }
}

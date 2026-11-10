<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Services\Import\WhmcsAddonImport;
use Illuminate\Support\Carbon;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Sweep 7, S7-LATEST-FIXES-1 (owner 2026-11-10): the import refuses a live recurring addon not due on its service's
 * date. The first test is the adversarial verifier's (adv/sweep7-latest-fixes, Sweep7LatestFixesTest), taken as it was
 * but for the helper's name; the others are the fix's. Fake data only.
 */
class Sweep7AddonDatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function whmcs(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');

        return $whmcs;
    }

    private function invoices(PDO $whmcs): void
    {
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, status TEXT)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, relid INT, duedate TEXT)');
    }

    /** @return list<int> */
    private function check(PDO $whmcs, string $method): array
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        return (new ReflectionMethod($command, $method))->invoke($command);
    }

    /**
     * S7-LATEST-FIXES-1. Since 2026-11-09 an addon has its parent's cycle and its parent's due date (docs/addons.md). The
     * import's guard still compares the day of the month only (WhmcsAddonImport::onServiceDay, from when a monthly addon
     * of a yearly service was allowed). With equal cycles longer than a month it lets through dates that are neither the
     * parent's nor a whole cycle from it: a yearly addon due 2027-06-15 under a yearly service due 2027-03-15 passes both
     * guards and is imported with its own date. It then never renews on its parent's invoice, and ChangeCycle refuses
     * every change of the parent's cycle («Addon #N is due on another date … pay its open invoice first», none open).
     */
    public function test_s7_latest_fixes_1_a_yearly_addon_three_months_off_its_yearly_service_is_refused_at_import(): void
    {
        $whmcs = $this->whmcs();
        $whmcs->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Annually', '2027-03-15')");
        // #70: the parent's own date (fine). #71: three months later, same cycle. #72: a quarterly pair a month apart.
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (70, 7, 'Active', 'Annually', '2027-03-15')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (71, 7, 'Active', 'Annually', '2027-06-15')");
        $whmcs->exec("INSERT INTO tblhosting VALUES (8, 'Active', 'Quarterly', '2027-01-10')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (72, 8, 'Active', 'Quarterly', '2027-02-10')");

        $this->assertSame([], $this->check($whmcs, 'addonsOnAnotherCycle'), 'all three are on their service\'s cycle');
        $this->assertSame(0, WhmcsAddonImport::periodsBehind('Annually', '2027-03-15', '2027-03-15'));
        $this->assertSame([71, 72], $this->check($whmcs, 'addonsApart'),
            'a yearly addon due 2027-06-15 of a yearly service due 2027-03-15 (and a quarterly one a month off its quarterly'
            . ' service) is not on its parent\'s due date nor a whole cycle from it: the import ran on it');
    }

    /**
     * The one shape taken as it is: an addon whole periods behind its paid service because its own renewal is unpaid, told
     * by its unpaid «Addon» line due on the addon's own next due date (WHMCS moves that date on only when it is paid).
     * Without that line the same dates are a misdated addon: refused, as one ahead of its service always is.
     */
    public function test_an_addon_whole_periods_behind_its_service_is_taken_only_with_its_own_renewal_unpaid(): void
    {
        $whmcs = $this->whmcs();
        $this->invoices($whmcs);
        $whmcs->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Monthly', '2026-12-28'), (8, 'Active', 'Annually', '2027-10-31')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES
            (80, 7, 'Suspended', 'Monthly', '2026-10-28'), -- two months behind, its renewal due 2026-10-28 unpaid: overdue
            (81, 7, 'Active', 'Monthly', '2026-11-28'),    -- a month behind, nothing unpaid: misdated
            (82, 7, 'Active', 'Monthly', '2026-11-28'),    -- a month behind, its unpaid line is of another period
            (83, 7, 'Active', 'Monthly', '2026-11-28'),    -- a month behind, its renewal of that period paid
            (84, 7, 'Active', 'Monthly', '2027-01-28'),    -- a month AHEAD, its service's renewal not unpaid: refused
            (85, 8, 'Active', 'Annually', '2025-10-31'),   -- two years behind, in Collections: overdue
            (86, 8, 'Active', 'Annually', '2026-07-31'),   -- three months behind, unpaid: no whole period, refused
            (87, 7, 'Active', 'Monthly', '2026-11-28'),    -- a month behind, its renewal cancelled: refused
            (88, 7, 'Active', 'Monthly', '2026-12-28')     -- its service's date");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, 'Unpaid'), (2, 'Paid'), (3, 'Collections'), (4, 'Cancelled')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (10, 1, 'Addon', 80, '2026-10-28'), (11, 1, 'Addon', 82, '2026-09-28'),
            (12, 2, 'Addon', 83, '2026-11-28'), (13, 1, 'Addon', 84, '2027-01-28'), (14, 3, 'Addon', 85, '2025-10-31'),
            (15, 1, 'Addon', 86, '2026-07-31'), (16, 4, 'Addon', 87, '2026-11-28'), (17, 1, 'Hosting', 81, '2026-11-28')");

        $this->assertSame([81, 82, 83, 84, 86, 87], $this->check($whmcs, 'addonsApart'));
        $this->assertSame(2, WhmcsAddonImport::periodsBehind('Monthly', '2026-10-28', '2026-12-28'));
        $this->assertSame(2, WhmcsAddonImport::periodsBehind('Annually', '2025-10-31', '2027-10-31'));
        $this->assertNull(WhmcsAddonImport::periodsBehind('Annually', '2026-07-31', '2027-10-31'));
        $this->assertNull(WhmcsAddonImport::periodsBehind('Monthly', '2027-01-28', '2026-12-28'), 'ahead');
        $this->assertNull(WhmcsAddonImport::periodsBehind('Monthly', '2026-11-27', '2026-12-28'), 'another day');
        $this->assertNull(WhmcsAddonImport::periodsBehind('Monthly', '2026-11-28', '0000-00-00'), 'a service with no date');
        $this->assertNull(WhmcsAddonImport::periodsBehind('Weekly', '2026-12-28', '2026-12-28'), 'a cycle not known here');
    }

    /**
     * The mirror (review of #775, REV-775-4): the addon whole periods AHEAD of its service because the service's own
     * renewal is unpaid (an unpaid «Hosting» line due on the service's next due date). Without it, or a part of a period
     * ahead, refused.
     */
    public function test_an_addon_whole_periods_ahead_of_its_service_is_taken_only_with_the_services_own_renewal_unpaid(): void
    {
        $whmcs = $this->whmcs();
        $this->invoices($whmcs);
        $whmcs->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Monthly', '2026-10-28'), (8, 'Active', 'Monthly', '2026-10-28'),
            (9, 'Suspended', 'Annually', '2026-10-31'), (10, 'Active', 'Monthly', '2026-10-28')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES
            (90, 7, 'Active', 'Monthly', '2026-11-28'),   -- a month ahead, its service's renewal due 2026-10-28 unpaid
            (91, 8, 'Active', 'Monthly', '2026-11-28'),   -- a month ahead, its service's renewal paid: misdated
            (92, 9, 'Active', 'Annually', '2027-10-31'),  -- a year ahead, its service in Collections
            (93, 9, 'Active', 'Annually', '2027-01-31'),  -- three months ahead of a yearly service: no whole period
            (94, 10, 'Active', 'Monthly', '2026-11-28')   -- a month ahead, the service's unpaid line is of another period");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (1, 'Unpaid'), (2, 'Paid'), (3, 'Collections')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (10, 1, 'Hosting', 7, '2026-10-28'), (11, 2, 'Hosting', 8, '2026-10-28'),
            (12, 3, 'Hosting', 9, '2026-10-31'), (13, 1, 'Hosting', 10, '2026-09-28'), (14, 1, 'Addon', 7, '2026-10-28')");

        $this->assertSame([91, 93, 94], $this->check($whmcs, 'addonsApart'));
    }

    /** A source without the invoice tables (an old WHMCS): nothing says «unpaid», so every addon behind is refused. */
    public function test_without_invoices_an_addon_behind_its_service_is_refused(): void
    {
        $whmcs = $this->whmcs();
        $whmcs->exec("INSERT INTO tblhosting VALUES (7, 'Active', 'Monthly', '2026-12-28')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (80, 7, 'Active', 'Monthly', '2026-11-28'), (81, 7, 'Active', 'Monthly', '2026-12-28')");

        $this->assertSame([80], $this->check($whmcs, 'addonsApart'));
    }
}

<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Independent review of the C-FIRST-RUN-1 rewrite (sweep 4, owner's decision 2026-11-08): the importer refuses to run
 * while an old unpaid WHMCS invoice orders something (ImportFromWhmcs::abandonedOrders).
 */
class Rev2FirstRunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * REV2-FR-1. «Collections» comes unpaid (invoiceStatus: anything but Draft/Paid/Cancelled/Refunded; ADV-531-1), with
     * no processing payment (only «Payment Pending» gets one), so credit pays it on the first cron as an «Unpaid» one.
     * The importer only warns about Collections (WG-18). An old order invoice in Collections must refuse the import too.
     */
    public function test_rev2_fr_1_an_old_order_invoice_in_collections_refuses_the_import(): void
    {
        $whmcs = $this->whmcs();
        $old = now()->subDays(30)->toDateString();
        $whmcs->exec("INSERT INTO tblinvoices VALUES (9901, 1, '{$old}', '{$old}', 'Collections')");
        $whmcs->exec("INSERT INTO tbldomains VALUES (1, 'Pending')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (99011, 9901, 'DomainRegister', 1)");

        $this->assertSame([9901], $this->abandonedOrders($whmcs), 'imported unpaid, credit pays it and registers the name');
    }

    /**
     * REV2-FR-2. A pending WHMCS product addon is imported as a pending service (WhmcsAddonImport::LIVE), its «Addon»
     * line on it; credit paying an old one sets it up, as a Pending service's Hosting line would. The removed
     * CreditAutoApply rule covered it (any pending service on the invoice); the refusal does not.
     */
    public function test_rev2_fr_2_an_old_order_of_a_pending_addon_refuses_the_import(): void
    {
        $whmcs = $this->whmcs();
        $old = now()->subDays(30)->toDateString();
        $whmcs->exec("INSERT INTO tblhosting VALUES (501, 'Active')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (601, 501, 'Pending')");
        $whmcs->exec("INSERT INTO tblinvoices VALUES (9902, 1, '{$old}', '{$old}', 'Unpaid')");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (99021, 9902, 'Addon', 601)");

        $this->assertSame([9902], $this->abandonedOrders($whmcs), 'a pending addon ordered a month ago, set up by credit');
    }

    private function whmcs(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)',
            'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
            'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)',
            'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, domainstatus TEXT)',
            'CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, status TEXT)',
            'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, date TEXT, duedate TEXT, status TEXT)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, type TEXT, relid INT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        return $pdo;
    }

    /** @return list<int> */
    private function abandonedOrders(PDO $whmcs): array
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        return (new ReflectionMethod($command, 'abandonedOrders'))->invoke($command);
    }
}

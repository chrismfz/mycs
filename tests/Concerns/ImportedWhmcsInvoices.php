<?php

namespace Tests\Concerns;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Providers\SettingsProvider;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Invoices as the WHMCS importer brings them, through its own steps (importServices, importInvoices, importInvoiceItems,
 * importPayments, journalMoney): a «Hosting» line points at its service with no period, and a paid one is never marked
 * applied. The shape most of MyCS's invoices have on the day of the cut-over. No customer data: made-up rows.
 */
trait ImportedWhmcsInvoices
{
    private PDO $whmcs;

    private int $whmcsClientId;

    private int $whmcsProductId;

    /** @param  int  $clientId  the MyCS account the WHMCS client is (ids are kept); $productId a monthly product's */
    private function whmcs(int $clientId, int $productId, string $currency = 'EUR'): void
    {
        $this->whmcsClientId = $clientId;
        $this->whmcsProductId = $productId;
        DB::table('currencies')->insertOrIgnore(['code' => $currency, 'name' => $currency, 'suffix' => $currency, 'format' => '1.000,00']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, '{$currency}')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
    }

    /** An active monthly WHMCS service, next due on $due. */
    private function whmcsService(int $id, string $due): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->whmcsClientId}, NULL, {$this->whmcsProductId}, '2024-01-01', 'example.test', 1, 10, 'Monthly',
            '{$due}', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
    }

    /**
     * A WHMCS invoice of «Hosting» lines ([service id => amount]); Paid, with its bank transfer of $paid (its total when
     * null), or Unpaid with a part payment when $paid is given.
     *
     * @param  array<int, float>  $lines
     */
    private function whmcsInvoice(int $id, string $status, array $lines, string $due = '2026-09-10', ?float $paid = null): void
    {
        $total = array_sum($lines);
        $datepaid = $status === 'Paid' ? '2026-09-03 10:00:00' : '0000-00-00 00:00:00';
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->whmcsClientId}, '', '2026-09-01', '{$due}', '{$datepaid}', '{$status}', {$total}, 0, 0)");
        foreach ($lines as $service => $amount) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed) VALUES ({$id}, {$this->whmcsClientId}, 'Hosting',
                {$service}, 'Hosting (example.test)', {$amount}, 0)");
        }
        $money = $paid ?? ($status === 'Paid' ? $total : 0.0);
        if ($money > 0) {
            $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->whmcsClientId}, {$id}, 'banktransfer', '2026-09-03 10:00:00', {$money}, 0, 0, 'tr_{$id}', 0)");
        }
    }

    /** The importer's own steps, in its order. */
    private function importWhmcs(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        foreach (['importServices', 'importInvoices', 'importInvoiceItems', 'importPayments', 'journalMoney'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();
    }

    /** The imported invoice, after checking it has the importer's shape: service lines with no period, none applied. */
    private function imported(int $id): Invoice
    {
        $invoice = Invoice::query()->with('items')->findOrFail($id);
        foreach ($invoice->items->where('reference_type', Service::class) as $line) {
            $this->assertNull($line->period_start, 'an imported Hosting line carries no period');
            $this->assertNull($line->applied_at, 'an imported line is never marked applied');
        }

        return $invoice;
    }
}

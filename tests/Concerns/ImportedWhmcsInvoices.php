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
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Invoices as the WHMCS importer brings them, through its own steps (importServices, importInvoices, importInvoiceItems,
 * importPayments, journalMoney): a «Hosting» line points at its service. Each line carries WHMCS's own due date (the
 * start of the period it bills; here its invoice's), so the importer gives the last paid line and the open renewal of
 * a live service their period when the dates are one cycle apart (WhmcsLinePeriods), and leaves every other line with
 * no period, never applied. The shape MyCS's invoices have on the day of the cut-over. No customer data: made-up rows.
 */
trait ImportedWhmcsInvoices
{
    private PDO $whmcs;

    private int $whmcsClientId;

    private int $whmcsProductId;

    /** What the importer's steps said on the last importWhmcs(). */
    private ?BufferedOutput $whmcsOutput = null;

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
            amount REAL, taxed INT, duedate TEXT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblcredit (id INTEGER PRIMARY KEY, clientid INT, date TEXT, description TEXT, amount REAL, relid INT DEFAULT 0)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, '{$currency}')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
    }

    /** An active monthly WHMCS service, next due on $due. */
    private function whmcsService(int $id, string $due, float $amount = 10.0, string $regdate = '2024-01-01', string $status = 'Active'): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->whmcsClientId}, NULL, {$this->whmcsProductId}, '{$regdate}', 'example.test', 1, {$amount}, 'Monthly',
            '{$due}', '{$status}', '', '', '', '', '', '', 0, '0000-00-00')");
    }

    /**
     * A WHMCS invoice of «Hosting» lines ([service id => amount]); Paid, with its bank transfer of $paid (its total when
     * null), or Unpaid with a part payment when $paid is given. Each line is due with its invoice ($due: the start of the
     * period it bills, as WHMCS keeps it on the line), unless $lineDue says another day (null: none, '0000-00-00').
     *
     * @param  array<int, float>  $lines
     */
    private function whmcsInvoice(int $id, string $status, array $lines, string $due = '2026-09-10', ?float $paid = null, string $date = '2026-09-01',
        string|false|null $lineDue = false): void
    {
        $total = array_sum($lines);
        $paidOn = date('Y-m-d', strtotime($date . ' +2 days')) . ' 10:00:00';
        $datepaid = $status === 'Paid' ? $paidOn : '0000-00-00 00:00:00';
        $lineDue = $lineDue === false ? $due : ($lineDue ?? '0000-00-00');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->whmcsClientId}, '', '{$date}', '{$due}', '{$datepaid}', '{$status}', {$total}, 0, 0)");
        foreach ($lines as $service => $amount) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate) VALUES ({$id}, {$this->whmcsClientId}, 'Hosting',
                {$service}, 'Hosting (example.test)', {$amount}, 0, '{$lineDue}')");
        }
        $money = $paid ?? ($status === 'Paid' ? $total : 0.0);
        if ($money > 0) {
            $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->whmcsClientId}, {$id}, 'banktransfer', '{$paidOn}', {$money}, 0, 0, 'tr_{$id}', 0)");
        }
    }

    /** The importer's own steps, in its order. */
    private function importWhmcs(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->whmcsOutput = new BufferedOutput));
        foreach (['importServices', 'importInvoices', 'importInvoiceItems', 'importPayments', 'journalMoney'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        SettingsProvider::reload();
    }

    /** What the last import said, on one line. */
    private function importSaid(): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->whmcsOutput?->fetch());
    }

    /**
     * The imported invoice, after checking it has the importer's shape: a service line with no period is never applied,
     * one with a period is applied exactly when its invoice came paid (WhmcsLinePeriods).
     */
    private function imported(int $id): Invoice
    {
        $invoice = Invoice::query()->with('items')->findOrFail($id);
        foreach ($invoice->items->where('reference_type', Service::class) as $line) {
            $this->assertSame($line->period_start === null, $line->period_end === null, 'a period has both its ends');
            $this->assertSame($line->period_start !== null && $invoice->status === Invoice::STATUS_PAID, $line->applied_at !== null,
                'an imported line is applied only with its period, on an invoice that came paid');
        }

        return $invoice;
    }

    /** The same invoice, which must have come as before: its service lines with no period, none applied. */
    private function importedWithoutPeriod(int $id): Invoice
    {
        $invoice = $this->imported($id);
        foreach ($invoice->items->where('reference_type', Service::class) as $line) {
            $this->assertNull($line->period_start, 'this line was to come with no period');
        }

        return $invoice;
    }
}

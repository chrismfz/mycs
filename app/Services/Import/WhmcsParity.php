<?php

namespace App\Services\Import;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * The numbers that must agree after an import (docs/importer.md, «Parity»), from both sides, on the same rules: WHMCS's
 * copy (read only) and this instance. Amounts in cents. An invoice's amount is what was billed before any credit (WHMCS's
 * subtotal + taxes; its own «total» is after the credit), the credit applied and the money paid each counted on their
 * own; Mass Pay parents are no invoices here (their money is on their children), so they are left out on WHMCS's side.
 */
final class WhmcsParity
{
    public function __construct(private readonly PDO $whmcs) {}

    /**
     * @return list<array{metric: string, whmcs: int|string, mycs: int|string, same: bool}>
     */
    public function rows(): array
    {
        $rows = [];
        $add = function (string $metric, int $whmcs, int $mycs) use (&$rows) {
            $rows[] = ['metric' => $metric, 'whmcs' => $whmcs, 'mycs' => $mycs, 'same' => $whmcs === $mycs];
        };

        $add('Customers', $this->count('SELECT COUNT(*) FROM tblclients'), DB::table('clients')->count());

        foreach (['Active' => 'active', 'Suspended' => 'suspended', 'Terminated' => 'terminated', 'Cancelled' => 'cancelled', 'Pending' => 'pending'] as $whmcs => $mycs) {
            $add("Services {$mycs}", $this->count('SELECT COUNT(*) FROM tblhosting WHERE domainstatus = ?', [$whmcs]),
                DB::table('services')->where('status', $mycs)->count());
        }

        // Mass Pay parents: invoices whose every line pays another invoice of the same customer (WhmcsMassPay).
        $notParent = "NOT EXISTS (SELECT 1 FROM tblinvoiceitems x WHERE x.invoiceid = i.id AND x.type = 'Invoice')";
        $mycsInvoices = $this->mycsInvoices();
        foreach (['Paid' => 'paid', 'Unpaid' => 'unpaid', 'Cancelled' => 'cancelled', 'Refunded' => 'refunded', 'Collections' => 'collections'] as $whmcs => $mycs) {
            $source = $this->row("SELECT COUNT(*) n, COALESCE(SUM(ROUND((i.subtotal + i.tax + i.tax2) * 100)), 0) amount FROM tblinvoices i WHERE i.status = ? AND {$notParent}", [$whmcs]);
            $here = $mycsInvoices[$mycs] ?? ['n' => 0, 'amount' => 0];
            if ($source['n'] === 0 && $here['n'] === 0) {
                continue;
            }
            $add("Invoices {$mycs}", $source['n'], $here['n']);
            $add("Invoices {$mycs}, amount", $source['amount'], $here['amount']);
        }

        $money = DB::table('invoice_transactions')->where('is_credit_transaction', false)
            ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) amount, COALESCE(SUM(ROUND(refunded_amount * 100)), 0) refunded, COALESCE(SUM(ROUND(COALESCE(fee, 0) * 100)), 0) fee')->first();
        $add('Money in', $this->count('SELECT COALESCE(SUM(ROUND(amountin * 100)), 0) FROM tblaccounts'), (int) $money->amount);
        $add('Money out (refunds)', $this->count('SELECT COALESCE(SUM(ROUND(amountout * 100)), 0) FROM tblaccounts'), (int) $money->refunded);
        $add('Gateway fees', $this->count('SELECT COALESCE(SUM(ROUND(fees * 100)), 0) FROM tblaccounts'), (int) $money->fee);
        $add('Credit applied to invoices', $this->count('SELECT COALESCE(SUM(ROUND(credit * 100)), 0) FROM tblinvoices'),
            (int) DB::table('invoice_transactions')->where('is_credit_transaction', true)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        $add('Customers with credit', $this->count('SELECT COUNT(*) FROM tblclients WHERE credit > 0'), DB::table('credits')->where('amount', '>', 0)->count());
        $add('Credit balance', $this->count('SELECT COALESCE(SUM(ROUND(credit * 100)), 0) FROM tblclients'),
            (int) DB::table('credits')->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        $add('Tickets', $this->count('SELECT COUNT(*) FROM tbltickets'), Schema::hasTable('tickets') ? DB::table('tickets')->count() : 0);
        $add('Domains', $this->count('SELECT COUNT(*) FROM tbldomains'), Schema::hasTable('domains') ? DB::table('domains')->count() : 0);

        return $rows;
    }

    /** @return array<string, array{n: int, amount: int}> by status: how many, and their totals in cents (as the app sums them) */
    private function mycsInvoices(): array
    {
        $out = [];
        Invoice::query()->with(['items', 'adjustmentNotes'])->orderBy('id')->chunk(500, function ($invoices) use (&$out) {
            foreach ($invoices as $invoice) {
                $status = (string) $invoice->status;
                $out[$status] ??= ['n' => 0, 'amount' => 0];
                $out[$status]['n']++;
                $out[$status]['amount'] += (int) round($invoice->total * 100);
            }
        });

        return $out;
    }

    /** @param list<mixed> $bindings */
    private function count(string $sql, array $bindings = []): int
    {
        $statement = $this->whmcs->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{n: int, amount: int}
     */
    private function row(string $sql, array $bindings = []): array
    {
        $statement = $this->whmcs->prepare($sql);
        $statement->execute($bindings);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['n' => (int) ($row['n'] ?? 0), 'amount' => (int) ($row['amount'] ?? 0)];
    }
}

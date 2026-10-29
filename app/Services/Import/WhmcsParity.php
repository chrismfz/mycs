<?php

namespace App\Services\Import;

use App\Console\Commands\ImportFromWhmcs;
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

        // Mass Pay parents, by the importer's own rule (WhmcsMassPay::PARENTS_SQL): no invoices here. Each WHMCS status
        // mapped as the importer maps it; every status found on either side is counted (review of #521: a fixed list
        // let a Draft that never came pass as «no difference»).
        $parents = array_map('intval', $this->whmcs->query(WhmcsMassPay::PARENTS_SQL)->fetchAll(PDO::FETCH_COLUMN));
        $notParent = $parents === [] ? '1 = 1' : 'i.id NOT IN (' . implode(',', $parents) . ')';
        $source = [];
        foreach ($this->whmcs->query("SELECT i.status, COUNT(*) n, COALESCE(SUM(ROUND((i.subtotal + i.tax + i.tax2) * 100)), 0) amount FROM tblinvoices i WHERE {$notParent} GROUP BY i.status")
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = ImportFromWhmcs::invoiceStatus((string) $row['status']);
            $source[$status] ??= ['n' => 0, 'amount' => 0];
            $source[$status]['n'] += (int) $row['n'];
            $source[$status]['amount'] += (int) $row['amount'];
        }
        $here = $this->mycsInvoices();
        $statuses = array_unique([...array_keys($source), ...array_keys($here)]);
        sort($statuses);
        foreach ($statuses as $status) {
            $add("Invoices {$status}", $source[$status]['n'] ?? 0, $here[$status]['n'] ?? 0);
            $add("Invoices {$status}, amount", $source[$status]['amount'] ?? 0, $here[$status]['amount'] ?? 0);
        }

        // Payments that went through only (after the cut-over a failed or pending attempt is no money).
        $money = DB::table('invoice_transactions')->where('is_credit_transaction', false)->where('status', 'succeeded')
            ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) amount, COALESCE(SUM(ROUND(refunded_amount * 100)), 0) refunded, COALESCE(SUM(ROUND(COALESCE(fee, 0) * 100)), 0) fee')->first();
        $add('Money in', $this->count('SELECT COALESCE(SUM(ROUND(amountin * 100)), 0) FROM tblaccounts'), (int) $money->amount);
        $add('Money out (refunds)', $this->count('SELECT COALESCE(SUM(ROUND(amountout * 100)), 0) FROM tblaccounts'), (int) $money->refunded);
        $add('Gateway fees', $this->count('SELECT COALESCE(SUM(ROUND(fees * 100)), 0) FROM tblaccounts'), (int) $money->fee);
        // The customers' account credit, on both sides: a Mass Pay child's «credit» in WHMCS is its parent's money (here a
        // payment, or an allocation of one), so children are left out there, allocations of a payment here (review of #521).
        $children = $parents === [] ? [] : array_map('intval', $this->whmcs->query("SELECT DISTINCT relid FROM tblinvoiceitems WHERE type = 'Invoice' AND invoiceid IN ("
            . implode(',', $parents) . ')')->fetchAll(PDO::FETCH_COLUMN));
        $add('Credit applied to invoices', $this->count('SELECT COALESCE(SUM(ROUND(credit * 100)), 0) FROM tblinvoices'
            . ($children === [] ? '' : ' WHERE id NOT IN (' . implode(',', $children) . ')')),
            (int) DB::table('invoice_transactions')->where('is_credit_transaction', true)->whereNull('source_transaction_id')->where('status', 'succeeded')
                ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        $add('Customers with credit', $this->count('SELECT COUNT(*) FROM tblclients WHERE credit > 0'), DB::table('credits')->where('amount', '>', 0)->count());
        $add('Credit balance', $this->count('SELECT COALESCE(SUM(ROUND(credit * 100)), 0) FROM tblclients'),
            (int) DB::table('credits')->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        // A ticket merged into another is that one's here (WhmcsTicketImport::merges): only the ones not merged count.
        $merged = $this->hasColumn('tbltickets', 'merged_ticket_id') ? ' WHERE NOT (merged_ticket_id > 0 AND merged_ticket_id <> id)' : '';
        $add('Tickets', $this->count('SELECT COUNT(*) FROM tbltickets' . $merged), Schema::hasTable('tickets') ? DB::table('tickets')->count() : 0);
        $add('Domains', $this->count('SELECT COUNT(*) FROM tbldomains'), Schema::hasTable('domains') ? DB::table('domains')->count() : 0);

        return $rows;
    }

    /**
     * What the numbers do not say on their own: amounts are summed across currencies (review of #521), so with more than one
     * a shortfall in one could hide behind a surplus in another.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $currencies = (int) $this->whmcs->query('SELECT COUNT(DISTINCT c.currency) FROM tblinvoices i JOIN tblclients c ON c.id = i.userid')->fetchColumn();

        return $currencies > 1 ? ["The invoices are in {$currencies} currencies: their amounts are summed together here."] : [];
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

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $this->whmcs->query("SELECT {$column} FROM {$table} LIMIT 1");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param list<mixed> $bindings */
    private function count(string $sql, array $bindings = []): int
    {
        $statement = $this->whmcs->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }
}

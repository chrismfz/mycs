<?php

namespace App\Services\Import;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Domain;
use App\Models\Invoice;
use App\Services\Money\MoneyTrail;
use App\Services\Tax\EuVat;
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
        // Without a country the app knows (EuVat::iso, as the importer reads it): #524 stops their orders until they set one.
        // Only the customers the importer brings (one with an owner login, ImportFromWhmcs::importUsers).
        $this->hasColumn('tblclients', 'country') && $add('Customers without a country', count(array_filter($this->whmcs->query('SELECT c.country FROM tblclients c'
            . ($this->hasColumn('tblusers_clients', 'owner') ? ' WHERE EXISTS (SELECT 1 FROM tblusers_clients uc JOIN tblusers u ON u.id = uc.auth_user_id'
                . ' WHERE uc.client_id = c.id AND uc.owner = 1)' : ''))->fetchAll(PDO::FETCH_COLUMN),
            fn ($country) => EuVat::iso($country) === null)),
            DB::table('clients')->whereNotExists(fn ($contact) => $contact->from('contacts')->whereColumn('contacts.primary_of_client_id', 'clients.id')
                ->whereNotNull('contacts.country')->where('contacts.country', '!=', ''))->count());

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
        // WHMCS's money out includes its refunds to credit: here a payment's credited part (refund_to_credit, as refundToCredit).
        $toCredit = (int) DB::table('money_events')->where('source', MoneyTrail::SOURCE_IMPORT)->where('cause', MoneyTrail::CAUSE_REFUND_TO_CREDIT)
            ->whereNotNull('invoice_transaction_id')->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a');
        $add('Money out (refunds)', $this->count('SELECT COALESCE(SUM(ROUND(amountout * 100)), 0) FROM tblaccounts'), (int) $money->refunded + $toCredit);
        $add('Gateway fees', $this->count('SELECT COALESCE(SUM(ROUND(fees * 100)), 0) FROM tblaccounts'), (int) $money->fee);
        // The customers' account credit applied to invoices, on both sides. A Mass Pay child's «credit» in WHMCS is its
        // parent's money and its customer's own credit; here the parent's payment lands on the child as a payment (the
        // part the child took of it) and as allocations, the customer's credit as credit payments (WhmcsMoneyJournal).
        $parentPayments = $parents === [] ? [] : array_map('intval', $this->whmcs->query('SELECT id FROM tblaccounts WHERE invoiceid IN ('
            . implode(',', $parents) . ')')->fetchAll(PDO::FETCH_COLUMN));
        $onChildren = $parentPayments === [] ? 0 : (int) DB::table('invoice_transactions')->whereIn('id', $parentPayments)->where('is_credit_transaction', false)
            ->selectRaw('COALESCE(SUM(ROUND(amount * 100) - ROUND(credited_amount * 100)), 0) a')->value('a');
        // As planned, before later refunds moved its credited part: a refund to credit raised it, a cash refund of credit
        // («Removal of Credit», credit_out overpayment) lowered it (round 3 of #523).
        $moved = fn (string $kind, string $cause) => $parentPayments === [] ? 0 : (int) DB::table('money_events')->where('source', MoneyTrail::SOURCE_IMPORT)
            ->whereIn('invoice_transaction_id', $parentPayments)->where('kind', $kind)->where('cause', $cause)
            ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a');
        $onChildren += $moved('credit_in', MoneyTrail::CAUSE_REFUND_TO_CREDIT) - $moved('credit_out', MoneyTrail::CAUSE_OVERPAYMENT);
        $add('Credit applied to invoices', $this->count("SELECT COALESCE(SUM(ROUND(i.credit * 100)), 0) FROM tblinvoices i WHERE i.credit > 0 AND {$notParent}"),
            (int) DB::table('invoice_transactions')->where('is_credit_transaction', true)->where('status', 'succeeded')
                ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a') + $onChildren);
        // A negative invoice gave its customer credit (WHMCS: a negative «credit» on it).
        $add('Credit from negative invoices', -$this->count("SELECT COALESCE(SUM(ROUND(i.credit * 100)), 0) FROM tblinvoices i WHERE i.credit < 0 AND {$notParent}"),
            (int) DB::table('money_events')->where('source', MoneyTrail::SOURCE_IMPORT)->where('cause', MoneyTrail::CAUSE_INVOICE_REDUCED)
                ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        $add('Customers with credit', $this->count('SELECT COUNT(*) FROM tblclients WHERE credit > 0'), DB::table('credits')->where('amount', '>', 0)->count());
        $add('Credit balance', $this->count('SELECT COALESCE(SUM(ROUND(credit * 100)), 0) FROM tblclients'),
            (int) DB::table('credits')->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) a')->value('a'));

        // A ticket merged into another is that one's here (WhmcsTicketImport::merges): only the ones not merged count.
        $merged = $this->hasColumn('tbltickets', 'merged_ticket_id') ? ' WHERE NOT (merged_ticket_id > 0 AND merged_ticket_id <> id)' : '';
        $add('Tickets', $this->count('SELECT COUNT(*) FROM tbltickets' . $merged), Schema::hasTable('tickets') ? DB::table('tickets')->count() : 0);
        $add('Domains', $this->count('SELECT COUNT(*) FROM tbldomains'), Schema::hasTable('domains') ? DB::table('domains')->count() : 0);
        // D6: the live ones by status, and the invoice lines of a domain now on it (a domain's line whose domain is here).
        if (Schema::hasTable('domains') && $this->hasColumn('tbldomains', 'status') && $this->hasColumn('tbldomains', 'recurringamount')) {
            $add('Domains active', $this->count("SELECT COUNT(*) FROM tbldomains WHERE status = 'Active'"), DB::table('domains')->where('status', 'active')->count());
            $add('Domains, recurring amount', $this->count("SELECT COALESCE(SUM(ROUND(recurringamount * 100)), 0) FROM tbldomains WHERE status = 'Active'"),
                (int) DB::table('domains')->where('status', 'active')->selectRaw('COALESCE(SUM(ROUND(recurring_amount * 100)), 0) a')->value('a'));
            $add('Domain invoice lines', $this->count("SELECT COUNT(*) FROM tblinvoiceitems ii JOIN tbldomains d ON d.id = ii.relid JOIN tblinvoices i ON i.id = ii.invoiceid
                WHERE ii.type LIKE 'Domain%' AND {$notParent}"),
                DB::table('invoice_items')->where('reference_type', Domain::class)->count());
        }

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

        $notes = $currencies > 1 ? ["The invoices are in {$currencies} currencies: their amounts are summed together here."] : [];
        // Money the importer leaves out, by its own rule (ImportFromWhmcs::importPayments, importRefunds): payments on no
        // invoice here (none in WHMCS, or one not imported), and money out on no payment here. «Money in» and «Money out»
        // are short by them.
        $here = array_fill_keys(DB::table('invoices')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $parents = array_fill_keys(array_map('intval', $this->whmcs->query(WhmcsMassPay::PARENTS_SQL)->fetchAll(PDO::FETCH_COLUMN)), true);
        $payments = array_fill_keys(DB::table('invoice_transactions')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $in = ['n' => 0, 'cents' => 0];
        $out = ['n' => 0, 'cents' => 0];
        foreach ($this->whmcs->query('SELECT * FROM tblaccounts WHERE amountin > 0 OR amountout > 0')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((float) $row['amountin'] > 0 && !isset($here[(int) $row['invoiceid']]) && !isset($parents[(int) $row['invoiceid']])) {
                $in['n']++;
                $in['cents'] += WhmcsMassPay::cents($row['amountin']);
            }
            if ((float) $row['amountout'] > 0 && !isset($payments[(int) ($row['refundid'] ?? 0)])) {
                $out['n']++;
                $out['cents'] += WhmcsMassPay::cents($row['amountout']);
            }
        }
        if ($in['n'] > 0) {
            $notes[] = "{$in['n']} WHMCS payment(s) on no invoice here, " . number_format($in['cents'] / 100, 2, '.', '')
                . ' in all, are not imported: «Money in» is short by them.';
        }
        if ($out['n'] > 0) {
            $notes[] = "{$out['n']} WHMCS money out on no payment here, " . number_format($out['cents'] / 100, 2, '.', '')
                . ' in all, is not imported: «Money out» is short by it.';
        }

        return $notes;
    }

    /** @return array<string, array{n: int, amount: int}> by status: how many, and what their lines bill, in cents */
    private function mycsInvoices(): array
    {
        $out = [];
        Invoice::query()->with('items')->orderBy('id')->chunk(500, function ($invoices) use (&$out) {
            foreach ($invoices as $invoice) {
                $status = (string) $invoice->status;
                $out[$status] ??= ['n' => 0, 'amount' => 0];
                $out[$status]['n']++;
                // What it billed (its lines), as WHMCS's subtotal + taxes: a refund's note here is no part of it.
                $out[$status]['amount'] += (int) $invoice->items->sum(fn ($item) => (int) round((float) $item->line_total * 100));
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

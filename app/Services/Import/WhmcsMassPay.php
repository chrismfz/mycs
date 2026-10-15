<?php

namespace App\Services\Import;

/**
 * A WHMCS Mass Pay parent as MyCS keeps it (PR F3, decision 7): no container invoice. WHMCS makes a parent invoice whose
 * lines (type «Invoice») are the children; the payment lands on the parent and each child is then paid from credit
 * («Mass Invoice Payment Credit for Invoice #parent» in, «Credit Applied to Invoice #child» out; read-only check of both
 * companies, 2026-10-15). Here, as for a Mass Pay made in MyCS (F1/F2), the payment lands on the child due first and what
 * that one cannot take pays the others as allocations of the payment (source_transaction_id). The part the parent took
 * from the customer's account credit pays the rest as credit transactions. The parent itself is not imported as an
 * invoice: its value is the children's, and the Ekdosi bridge never sees it (only its children). It stays as a paid
 * payment group that keeps its WHMCS id, so the number a customer quotes still finds it.
 *
 * Pure: amounts in cents, no database. The importer reads the rows and writes the plan.
 */
final class WhmcsMassPay
{
    /**
     * @param  array{id: int, total: int, credit: int}  $parent  total and the part paid from account credit, in cents
     * @param  list<array{child: int, amount: int}>  $lines  the parent's «Invoice» lines: each child and its share
     * @param  array<int, array{status: string, duedate: ?string, credit: int}>  $children  by id: status (WHMCS), due date,
     *                                                                                      credit applied to it (cents)
     * @param  list<array{id: int, amount: int, date: ?string}>  $payments  the parent's tblaccounts rows that brought money
     * @return array{
     *     payments: list<array{id: int, invoice_id: int, credited: int}>,
     *     allocations: list<array{invoice_id: int, amount: int, source_id: ?int, date: ?string}>,
     *     group: array{invoice_id: int, total: int, transaction_id: ?int, invoices: list<array{invoice_id: int, amount: int}>},
     *     warnings: list<string>
     * }
     */
    public static function plan(array $parent, array $lines, array $children, array $payments): array
    {
        $warnings = [];

        // Each child's share: its line, what WHMCS applied to it at most (a child paid in part before, or not paid).
        $shares = [];
        foreach ($lines as $line) {
            $child = $children[$line['child']] ?? null;
            if ($child === null) {
                $warnings[] = sprintf('Mass Pay #%d: child invoice #%d is missing.', $parent['id'], $line['child']);

                continue;
            }
            $share = $child['status'] === 'Paid' ? max(0, min($line['amount'], $child['credit'])) : 0;
            if ($share < $line['amount']) {
                $warnings[] = sprintf('Mass Pay #%d: child invoice #%d took %s of its %s line from it.', $parent['id'], $line['child'],
                    self::show($share), self::show($line['amount']));
            }
            $shares[$line['child']] = ($shares[$line['child']] ?? 0) + $share;
        }
        if (array_sum(array_column($lines, 'amount')) !== $parent['total']) {
            $warnings[] = sprintf('Mass Pay #%d: its total %s is not the sum of its lines %s.', $parent['id'],
                self::show($parent['total']), self::show(array_sum(array_column($lines, 'amount'))));
        }

        // The order MyCS pays a group in: due date, then id (F1/F2).
        $order = array_keys($shares);
        usort($order, fn (int $a, int $b) => [$children[$a]['duedate'] ?? '9999-12-31', $a] <=> [$children[$b]['duedate'] ?? '9999-12-31', $b]);
        $left = $shares;

        $plannedPayments = [];
        $allocations = [];
        usort($payments, fn (array $a, array $b) => [$a['date'] ?? '', $a['id']] <=> [$b['date'] ?? '', $b['id']]);
        foreach ($payments as $payment) {
            // On the first child with something left (the one due first when the payment is the only one).
            $target = $order[0] ?? null;
            foreach ($order as $id) {
                if ($left[$id] > 0) {
                    $target = $id;

                    break;
                }
            }
            if ($target === null) {
                $warnings[] = sprintf('Mass Pay #%d: payment %d has no child invoice to land on.', $parent['id'], $payment['id']);

                continue;
            }
            $direct = min($left[$target], $payment['amount']);
            $left[$target] -= $direct;
            $budget = $payment['amount'] - $direct;
            $plannedPayments[] = ['id' => $payment['id'], 'invoice_id' => $target, 'credited' => $budget];
            foreach ($order as $id) {
                if ($budget <= 0) {
                    break;
                }
                $take = min($left[$id], $budget);
                if ($take > 0) {
                    $allocations[] = ['invoice_id' => $id, 'amount' => $take, 'source_id' => $payment['id'], 'date' => $payment['date']];
                    $left[$id] -= $take;
                    $budget -= $take;
                }
            }
            if ($budget > 0) {
                $warnings[] = sprintf('Mass Pay #%d: %s of payment %d paid no child invoice (account credit).', $parent['id'],
                    self::show($budget), $payment['id']);
            }
        }

        // What the parent took from the customer's account credit pays what is left.
        $credit = max(0, $parent['credit']);
        $date = $payments === [] ? null : $payments[count($payments) - 1]['date'];
        foreach ($order as $id) {
            $take = min($left[$id], $credit);
            if ($take > 0) {
                $allocations[] = ['invoice_id' => $id, 'amount' => $take, 'source_id' => null, 'date' => $date];
                $left[$id] -= $take;
                $credit -= $take;
            }
        }
        foreach ($order as $id) {
            if ($left[$id] > 0) {
                $warnings[] = sprintf('Mass Pay #%d: %s of child invoice #%d is not covered by its payments or credit.', $parent['id'],
                    self::show($left[$id]), $id);
            }
        }

        return [
            'payments' => $plannedPayments,
            'allocations' => $allocations,
            'group' => [
                'invoice_id' => $order[0] ?? 0,
                'total' => $parent['total'],
                'transaction_id' => $plannedPayments[0]['id'] ?? null,
                'invoices' => array_map(fn (int $id) => ['invoice_id' => $id, 'amount' => $shares[$id]], $order),
            ],
            'warnings' => $warnings,
        ];
    }

    /** A WHMCS decimal amount (string or number) in cents. */
    public static function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
    }

    private static function show(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

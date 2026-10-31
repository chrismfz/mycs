<?php

namespace App\Services\Import;

use PDO;
use PDOException;

/**
 * A simulated stretch (`bin/simulate`: its ledger) against what WHMCS really did over the same stretch, read from two copies
 * of its database: one taken before the stretch (the one the simulation started from) and one after (ROADMAP 9β, 3). A group
 * is a kind of thing the cron does; in each, what both did, what only WHMCS did, what only the simulation did:
 *
 * - **Invoices made:** by client, due date and amount (the id is shown, not matched: a different grouping gives other ids).
 * - **Payments:** by invoice and amount.
 * - **Services:** a status change (suspended, unsuspended, terminated…) and a renewal (the due date moved), by service.
 * - **Mails:** by kind (an invoice, a payment confirmation, a reminder, an overdue notice, a suspension…), as a count; the mails
 *   WHMCS sends for tickets and orders are not the cron's and are not compared.
 *
 * What a customer or staff did by hand in WHMCS between the copies is in «only WHMCS» (a payment by bank transfer, a service
 * suspended by staff): the reader tells it from the cron's, and the activity log says who. Read only on both.
 */
final class WhmcsReplayCompare
{
    /** WHMCS's subject => the kind of mail (the cron's only). */
    private const WHMCS_MAILS = [
        'customer invoice' => 'invoice made', 'invoice generated' => 'invoice made',
        'invoice payment confirmation' => 'payment confirmation', 'credit card payment confirmation' => 'payment confirmation',
        'invoice payment reminder' => 'payment reminder',
        'first invoice overdue notice' => 'overdue notice', 'second invoice overdue notice' => 'overdue notice', 'third invoice overdue notice' => 'overdue notice',
        'invoice payment failed' => 'payment failed', 'service suspension notification' => 'service suspended',
        'service unsuspension notification' => 'service unsuspended', 'service termination notification' => 'service terminated',
        'credit card expiring soon' => 'card expiring', 'domain renewal notice' => 'domain notice',
    ];

    /** This app's template key => the kind. */
    private const MYCS_MAILS = [
        'new_invoice_created' => 'invoice made', 'invoice_paid' => 'payment confirmation', 'invoice_payment_reminder' => 'payment reminder',
        'invoice_overdue' => 'overdue notice', 'invoice_payment_failed' => 'payment failed', 'server_suspended' => 'service suspended',
        'server_unsuspended' => 'service unsuspended', 'server_terminated' => 'service terminated', 'card_expiring' => 'card expiring',
        'domain_renewal_notice' => 'domain notice',
    ];

    /**
     * @param  list<array{day?: string, event: string, ref: string, detail: string}>  $ledger
     */
    public function __construct(private readonly PDO $before, private readonly PDO $after, private readonly array $ledger) {}

    /**
     * @return array<string, array{whmcs: int, mycs: int, both: int, only_whmcs: list<string>, only_mycs: list<string>}>
     */
    public function run(): array
    {
        return [
            'invoices made' => $this->groups($this->whmcsInvoices(), $this->mycsInvoices()),
            'payments' => $this->groups($this->whmcsPayments(), $this->mycsPayments()),
            'services: status' => $this->groups($this->whmcsServices('status'), $this->mycsServices('service status')),
            'services: renewed' => $this->groups($this->whmcsServices('renewed'), $this->mycsServices('service renewed')),
            'mails' => $this->counts($this->whmcsMails(), $this->mycsMails()),
        ];
    }

    /** @return list<string> */
    private function whmcsInvoices(): array
    {
        $from = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblinvoices');
        $out = [];
        foreach ($this->rows($this->after, "SELECT id, userid, duedate, subtotal + tax + tax2 AS total FROM tblinvoices WHERE id > {$from} ORDER BY id") as $i) {
            $out[] = "client #{$i['userid']}, total " . number_format((float) $i['total'], 2, '.', '') . ', due ' . substr((string) $i['duedate'], 0, 10) . " (WHMCS #{$i['id']})";
        }

        return $out;
    }

    /** @return list<string> */
    private function mycsInvoices(): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === 'invoice created') {
                $out[] = $line['detail'] . ' (here ' . str_replace('invoice ', '', $line['ref']) . ')';
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function whmcsPayments(): array
    {
        $from = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblaccounts');
        $out = [];
        foreach ($this->rows($this->after, "SELECT invoiceid, amountin FROM tblaccounts WHERE id > {$from} AND amountin > 0 ORDER BY id") as $p) {
            $out[] = "invoice #{$p['invoiceid']}, " . number_format((float) $p['amountin'], 2, '.', '');
        }

        return $out;
    }

    /** @return list<string> */
    private function mycsPayments(): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === 'payment' && preg_match('/^paid ([\d.]+) \(succeeded\)/', $line['detail'], $m) === 1) {
                $out[] = "{$line['ref']}, {$m[1]}";
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function whmcsServices(string $kind): array
    {
        $before = [];
        foreach ($this->rows($this->before, 'SELECT id, domainstatus, nextduedate FROM tblhosting') as $s) {
            $before[(int) $s['id']] = $s;
        }
        $out = [];
        foreach ($this->rows($this->after, 'SELECT id, domainstatus, nextduedate FROM tblhosting ORDER BY id') as $s) {
            $was = $before[(int) $s['id']] ?? null;
            if ($was === null) {
                continue;
            }
            if ($kind === 'status' && $was['domainstatus'] !== $s['domainstatus']) {
                $out[] = "service #{$s['id']}, " . strtolower($was['domainstatus']) . ' → ' . strtolower($s['domainstatus']);
            }
            if ($kind === 'renewed' && substr((string) $was['nextduedate'], 0, 10) !== substr((string) $s['nextduedate'], 0, 10)) {
                $out[] = "service #{$s['id']}, " . substr((string) $was['nextduedate'], 0, 10) . ' → ' . substr((string) $s['nextduedate'], 0, 10);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function mycsServices(string $event): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === $event) {
                $out[] = $line['ref'] . ', ' . $line['detail'];
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    private function whmcsMails(): array
    {
        $since = (string) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblemails');
        $out = [];
        foreach ($this->rows($this->after, "SELECT subject FROM tblemails WHERE id > {$since}") as $m) {
            $kind = self::WHMCS_MAILS[strtolower(trim((string) $m['subject']))] ?? null;
            if ($kind !== null) {
                $out[$kind] = ($out[$kind] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    private function mycsMails(): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === 'mail' && preg_match('/^(\S+) \(/', $line['detail'], $m) === 1 && isset(self::MYCS_MAILS[$m[1]])) {
                $kind = self::MYCS_MAILS[$m[1]];
                $out[$kind] = ($out[$kind] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $whmcs
     * @param  list<string>  $mycs
     * @return array{whmcs: int, mycs: int, both: int, only_whmcs: list<string>, only_mycs: list<string>}
     */
    private function groups(array $whmcs, array $mycs): array
    {
        // Each item once; the ids in brackets are not part of what is matched.
        $key = fn (string $item) => (string) preg_replace('/ \((?:WHMCS|here) [^)]*\)$/', '', $item);
        $mine = [];
        foreach ($mycs as $item) {
            $mine[$key($item)][] = $item;
        }
        $onlyWhmcs = [];
        $both = 0;
        foreach ($whmcs as $item) {
            if (!empty($mine[$key($item)])) {
                array_pop($mine[$key($item)]);
                $both++;
            } else {
                $onlyWhmcs[] = $item;
            }
        }

        return ['whmcs' => count($whmcs), 'mycs' => count($mycs), 'both' => $both, 'only_whmcs' => $onlyWhmcs, 'only_mycs' => array_merge([], ...array_values($mine))];
    }

    /**
     * @param  array<string, int>  $whmcs
     * @param  array<string, int>  $mycs
     * @return array{whmcs: int, mycs: int, both: int, only_whmcs: list<string>, only_mycs: list<string>}
     */
    private function counts(array $whmcs, array $mycs): array
    {
        $both = 0;
        $onlyWhmcs = [];
        $onlyMycs = [];
        foreach (array_unique([...array_keys($whmcs), ...array_keys($mycs)]) as $kind) {
            $a = $whmcs[$kind] ?? 0;
            $b = $mycs[$kind] ?? 0;
            $both += min($a, $b);
            if ($a > $b) {
                $onlyWhmcs[] = ($a - $b) . '× ' . $kind;
            } elseif ($b > $a) {
                $onlyMycs[] = ($b - $a) . '× ' . $kind;
            }
        }

        return ['whmcs' => array_sum($whmcs), 'mycs' => array_sum($mycs), 'both' => $both, 'only_whmcs' => $onlyWhmcs, 'only_mycs' => $onlyMycs];
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        try {
            return $pdo->query($sql)->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql): array
    {
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }
    }
}

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
 * - **Invoice status:** an invoice that became paid, cancelled, refunded… (a status change of the stretch, net).
 * - **Payments:** by invoice and amount, with credit applied as its own kind; a new invoice is named by its content, not its id.
 * - **Card charges attempted:** an invoice WHMCS tried a saved method on (`last_capture_attempt`), and one the simulation did.
 * - **Services, domains:** a change of status; a renewal (the due date, or the expiry, moved), by item; net over the stretch.
 * - **Mails:** by kind **and client** (an invoice, a payment confirmation, a reminder, an overdue notice, a suspension, a domain
 *   notice…); a mail of no kind known is listed as «other: …», never dropped. Only what WHMCS sends for tickets and accounts is
 *   left out.
 *
 * Not compared: refunds (`amountout`), the gateway of a payment, the amount of a changed price. What a customer or staff did by
 * hand in WHMCS between the copies is in «only WHMCS» (a payment by bank transfer, a service suspended by staff): the reader tells
 * it from the cron's, and the activity log says who. A table or column that cannot be read is said (`notes()`), never read as
 * «WHMCS did nothing». Read only on both.
 */
final class WhmcsReplayCompare
{
    /** WHMCS's subject => the kind of mail (the cron's). */
    private const WHMCS_MAILS = [
        'customer invoice' => 'invoice made', 'invoice generated' => 'invoice made',
        'invoice payment confirmation' => 'payment confirmation', 'credit card payment confirmation' => 'payment confirmation',
        'invoice payment reminder' => 'payment reminder', 'credit card payment due' => 'card payment due',
        'first invoice overdue notice' => 'overdue notice', 'second invoice overdue notice' => 'overdue notice', 'third invoice overdue notice' => 'overdue notice',
        'invoice payment failed' => 'payment failed', 'credit card payment failed' => 'payment failed',
        'service suspension notification' => 'service suspended', 'service unsuspension notification' => 'service unsuspended',
        'service termination notification' => 'service terminated', 'credit card expiring soon' => 'card expiring',
        'invoice refund confirmation' => 'refunded', 'invoice modified' => 'invoice modified',
    ];

    /** This app's template key => the kind. */
    private const MYCS_MAILS = [
        'new_invoice_created' => 'invoice made', 'invoice_paid' => 'payment confirmation', 'invoice_payment_reminder' => 'payment reminder',
        'card_payment_due' => 'card payment due', 'invoice_overdue' => 'overdue notice', 'invoice_payment_failed' => 'payment failed',
        'server_suspended' => 'service suspended', 'server_unsuspended' => 'service unsuspended', 'server_terminated' => 'service terminated',
        'card_expiring' => 'card expiring', 'invoice_refunded' => 'refunded', 'invoice_modified' => 'invoice modified',
        'new_server_created' => 'service welcome',
    ];

    /** Mails of tickets, accounts and orders: not the cron's, on either side. */
    private const WHMCS_IGNORED = '/^(\[ticket id|re:|order confirmation|new order|ticket |support ticket|password reset|email address verification|new login|account |welcome)/i';

    private const MYCS_IGNORED = '/^(new_ticket|guest_ticket|ticket_|email_verification|password_reset|new_login|client_invite|new_order_created|announcement|whmcs_history)/';

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param  list<array{day?: string, event: string, ref: string, detail: string}>  $ledger
     */
    public function __construct(private readonly PDO $before, private readonly PDO $after, private readonly array $ledger) {}

    /** What could not be compared, and why (a table or column the copies lack). @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array<string, array{whmcs: int, mycs: int, both: int, only_whmcs: list<string>, only_mycs: list<string>}>
     */
    public function run(): array
    {
        $created = $this->createdInvoices();

        return [
            'invoices made' => $this->groups($this->whmcsInvoices(), $this->mycsInvoices()),
            'invoice status' => $this->groups($this->whmcsInvoiceStatus(), $this->net('invoice status', fn (array $l) => $l['ref'])),
            'payments' => $this->groups($this->whmcsPayments(), $this->mycsPayments($created)),
            'card charges' => $this->groups($this->whmcsCharges(), $this->mycsCharges($created)),
            'services: status' => $this->groups($this->whmcsChanges('tblhosting', 'domainstatus', 'domainstatus', fn (array $r) => "service #{$r['id']}"), $this->net('service status', fn (array $l) => $l['ref'])),
            'services: renewed' => $this->groups($this->whmcsChanges('tblhosting', 'nextduedate', null, fn (array $r) => "service #{$r['id']}"), $this->net('service renewed', fn (array $l) => $l['ref'])),
            'domains: status' => $this->groups($this->whmcsChanges('tbldomains', 'status', 'status', fn (array $r) => "domain {$r['domain']}"), $this->net('domain status', fn (array $l) => $l['ref'])),
            'domains: renewed' => $this->groups($this->whmcsChanges('tbldomains', 'expirydate', null, fn (array $r) => "domain {$r['domain']}"), $this->net('domain renewed', fn (array $l) => $l['ref'])),
            'mails' => $this->counts($this->whmcsMails(), $this->mycsMails()),
        ];
    }

    // ---- invoices ---------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function whmcsInvoices(): array
    {
        $from = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblinvoices', 'tblinvoices');
        $out = [];
        foreach ($this->rows($this->after, "SELECT id, userid, duedate, subtotal + tax + tax2 AS total FROM tblinvoices WHERE id > {$from} ORDER BY id", 'invoices made') as $i) {
            $out[] = $this->invoiceContent((int) $i['userid'], (string) $i['duedate'], (float) $i['total']) . " (WHMCS #{$i['id']})";
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

    /** An invoice made in the stretch, by what it is: «client #9, total 3.60, due 2026-11-08». */
    private function invoiceContent(int $client, string $due, float $total): string
    {
        return "client #{$client}, total " . number_format($total, 2, '.', '') . ', due ' . substr($due, 0, 10);
    }

    /** @return array<string, string> the invoices the simulation made: «invoice #105» => its content */
    private function createdInvoices(): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === 'invoice created') {
                $out[$line['ref']] = $line['detail'];
            }
        }

        return $out;
    }

    /** @return list<string> an invoice whose status changed over the stretch */
    private function whmcsInvoiceStatus(): array
    {
        $was = [];
        foreach ($this->rows($this->before, 'SELECT id, status FROM tblinvoices', 'invoice status') as $i) {
            $was[(int) $i['id']] = strtolower((string) $i['status']);
        }
        $out = [];
        foreach ($this->rows($this->after, 'SELECT id, status FROM tblinvoices ORDER BY id', 'invoice status') as $i) {
            $now = strtolower((string) $i['status']);
            if (isset($was[(int) $i['id']]) && $was[(int) $i['id']] !== $now) {
                $out[] = "invoice #{$i['id']}, {$was[(int) $i['id']]} → {$now}";
            }
        }

        return $out;
    }

    // ---- payments and charges ---------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function whmcsPayments(): array
    {
        $first = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblinvoices', 'tblinvoices');
        $contents = $this->whmcsNewInvoices($first);
        $from = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblaccounts', 'tblaccounts');
        $out = [];
        foreach ($this->rows($this->after, "SELECT invoiceid, amountin FROM tblaccounts WHERE id > {$from} AND amountin > 0 ORDER BY id", 'payments') as $p) {
            $out[] = ($contents[(int) $p['invoiceid']] ?? "invoice #{$p['invoiceid']}") . ', ' . number_format((float) $p['amountin'], 2, '.', '');
        }
        // Credit applied: WHMCS writes it on the invoice (`credit`), not as a payment.
        $creditBefore = [];
        foreach ($this->rows($this->before, 'SELECT id, credit FROM tblinvoices', 'credit applied') as $i) {
            $creditBefore[(int) $i['id']] = (float) $i['credit'];
        }
        foreach ($this->rows($this->after, 'SELECT id, credit FROM tblinvoices WHERE credit > 0 ORDER BY id', 'credit applied') as $i) {
            $delta = (float) $i['credit'] - ($creditBefore[(int) $i['id']] ?? 0.0);
            if ($delta > 0.004) {
                $out[] = ($contents[(int) $i['id']] ?? "invoice #{$i['id']}") . ', ' . number_format($delta, 2, '.', '') . ' from credit';
            }
        }

        return $out;
    }

    /** @return array<int, string> a WHMCS invoice made in the stretch => its content */
    private function whmcsNewInvoices(int $first): array
    {
        $out = [];
        foreach ($this->rows($this->after, "SELECT id, userid, duedate, subtotal + tax + tax2 AS total FROM tblinvoices WHERE id > {$first}", 'invoices made') as $i) {
            $out[(int) $i['id']] = $this->invoiceContent((int) $i['userid'], (string) $i['duedate'], (float) $i['total']);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $created
     * @return list<string>
     */
    private function mycsPayments(array $created): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] === 'payment' && preg_match('/^paid ([\d.]+) \(succeeded\)( from credit)?$/', $line['detail'], $m) === 1) {
                $out[] = ($created[$line['ref']] ?? $line['ref']) . ', ' . $m[1] . ($m[2] ?? '');
            }
        }

        return $out;
    }

    /** @return list<string> an invoice WHMCS tried a saved method on over the stretch */
    private function whmcsCharges(): array
    {
        $first = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblinvoices', 'tblinvoices');
        $contents = $this->whmcsNewInvoices($first);
        $was = [];
        foreach ($this->rows($this->before, 'SELECT id, last_capture_attempt FROM tblinvoices', 'card charges') as $i) {
            $was[(int) $i['id']] = (string) $i['last_capture_attempt'];
        }
        $out = [];
        foreach ($this->rows($this->after, "SELECT id, last_capture_attempt FROM tblinvoices WHERE last_capture_attempt NOT LIKE '0000%' ORDER BY id", 'card charges') as $i) {
            if (($was[(int) $i['id']] ?? '') !== (string) $i['last_capture_attempt']) {
                $out[] = $contents[(int) $i['id']] ?? "invoice #{$i['id']}";
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $created
     * @return list<string>
     */
    private function mycsCharges(array $created): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if (in_array($line['event'], ['card charged', 'card declined'], true)) {
                $out[] = $created[$line['ref']] ?? $line['ref'];
            }
        }

        return array_values(array_unique($out));
    }

    // ---- services and domains ---------------------------------------------------------------------------------------------

    /**
     * What changed in a table of WHMCS between the copies, per item: a column that is a status, or a date.
     *
     * @param  \Closure(array<string, mixed>): string  $name
     * @return list<string>
     */
    private function whmcsChanges(string $table, string $column, ?string $statusColumn, \Closure $name): array
    {
        $select = $table === 'tbldomains' ? "id, domain, {$column}" : "id, {$column}";
        $was = [];
        foreach ($this->rows($this->before, "SELECT {$select} FROM {$table}", $table) as $r) {
            $was[(int) $r['id']] = $r;
        }
        $out = [];
        foreach ($this->rows($this->after, "SELECT {$select} FROM {$table} ORDER BY id", $table) as $r) {
            $before = $was[(int) $r['id']] ?? null;
            if ($before === null) {
                continue;
            }
            $a = $statusColumn !== null ? strtolower(str_replace(' ', '_', (string) $before[$column])) : substr((string) $before[$column], 0, 10);
            $b = $statusColumn !== null ? strtolower(str_replace(' ', '_', (string) $r[$column])) : substr((string) $r[$column], 0, 10);
            if ($a !== $b) {
                $out[] = $name($r) . ", {$a} → {$b}";
            }
        }

        return $out;
    }

    /**
     * The simulation's events of a kind, **net over the stretch**: an item that went active → suspended on day 1 and back on day 3
     * did nothing between the copies, as WHMCS's two copies can show only that.
     *
     * @param  \Closure(array<string, string>): string  $ref
     * @return list<string>
     */
    private function net(string $event, \Closure $ref): array
    {
        $first = [];
        $last = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] !== $event || preg_match('/^(.*) → (.*)$/u', $line['detail'], $m) !== 1) {
                continue;
            }
            $item = $ref($line);
            $first[$item] ??= $m[1];
            $last[$item] = $m[2];
        }
        $out = [];
        foreach ($first as $item => $from) {
            if ($from !== $last[$item]) {
                $out[] = "{$item}, {$from} → {$last[$item]}";
            }
        }

        return $out;
    }

    // ---- mails ------------------------------------------------------------------------------------------------------------

    /** @return array<string, int> «kind|client» => count */
    private function whmcsMails(): array
    {
        $since = (int) $this->scalar($this->before, 'SELECT COALESCE(MAX(id), 0) FROM tblemails', 'tblemails');
        // The products' own welcome emails (their subjects are the templates').
        $welcome = [];
        foreach ($this->rows($this->after, "SELECT subject FROM tblemailtemplates WHERE type = 'product'", null) as $t) {
            $welcome[strtolower(trim((string) $t['subject']))] = true;
        }
        $out = [];
        foreach ($this->rows($this->after, "SELECT userid, subject FROM tblemails WHERE id > {$since}", 'mails') as $m) {
            $subject = strtolower(trim((string) $m['subject']));
            if (preg_match(self::WHMCS_IGNORED, $subject) === 1) {
                continue;
            }
            $kind = self::WHMCS_MAILS[$subject] ?? (isset($welcome[$subject]) ? 'service welcome' : 'other: ' . trim((string) $m['subject']));
            $key = $kind . '|client #' . (int) $m['userid'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function mycsMails(): array
    {
        $out = [];
        foreach ($this->ledger as $line) {
            if ($line['event'] !== 'mail' || preg_match('/^(\S+) \(/', $line['detail'], $m) !== 1 || preg_match(self::MYCS_IGNORED, $m[1]) === 1) {
                continue;
            }
            $key = (self::MYCS_MAILS[$m[1]] ?? 'other: ' . $m[1]) . '|' . $line['ref'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    // ---- matching ---------------------------------------------------------------------------------------------------------

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
        foreach (array_unique([...array_keys($whmcs), ...array_keys($mycs)]) as $key) {
            [$kind, $client] = explode('|', $key, 2);
            $a = $whmcs[$key] ?? 0;
            $b = $mycs[$key] ?? 0;
            $both += min($a, $b);
            if ($a > $b) {
                $onlyWhmcs[] = "{$client}: " . ($a - $b) . "× {$kind}";
            } elseif ($b > $a) {
                $onlyMycs[] = "{$client}: " . ($b - $a) . "× {$kind}";
            }
        }

        return ['whmcs' => array_sum($whmcs), 'mycs' => array_sum($mycs), 'both' => $both, 'only_whmcs' => $onlyWhmcs, 'only_mycs' => $onlyMycs];
    }

    // ---- reading ----------------------------------------------------------------------------------------------------------

    private function scalar(PDO $pdo, string $sql, ?string $what): mixed
    {
        try {
            return $pdo->query($sql)->fetchColumn();
        } catch (PDOException) {
            $this->unreadable($what);

            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql, ?string $what): array
    {
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $this->unreadable($what);

            return [];
        }
    }

    /** A table or column the copies lack is a group not compared, said once: not «WHMCS did nothing». */
    private function unreadable(?string $what): void
    {
        if ($what !== null && !in_array("{$what}: not readable in the copies (a table or column is missing): not compared.", $this->notes, true)) {
            $this->notes[] = "{$what}: not readable in the copies (a table or column is missing): not compared.";
        }
    }
}

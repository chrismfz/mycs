<?php

namespace App\Services\Import;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Parity record by record (docs/importer.md, «Parity»; predeploy review 2026-11-02: totals that agree can hide two errors
 * that cancel out). Each account, invoice, service and domain of WHMCS's copy against the one of the same id here: its
 * money per account in its own currency, an invoice's status, amount and due date, a service's status, price, due date
 * and where it lives (its server, its username, its domain: a suspension of the wrong account is the worst a cut-over can
 * do), a domain's registrar, expiry and amount. Read only on both. Amounts in cents.
 */
final class WhmcsRecordParity
{
    /** @var array<string, array{checked: int, count: int, differ: list<string>}> */
    private array $result = [];

    public function __construct(private readonly PDO $whmcs, private readonly int $examples = 20) {}

    /** @return array<string, array{checked: int, count: int, differ: list<string>}> per check: how many compared, how many differ, examples */
    public function run(): array
    {
        $this->accounts();
        $this->invoices();
        $this->services();
        $this->domains();

        return $this->result;
    }

    private function accounts(): void
    {
        $parents = $this->parents();
        $notParent = $parents === [] ? '1 = 1' : 'i.id NOT IN (' . implode(',', $parents) . ')';
        $billed = $this->pairs("SELECT i.userid, SUM(ROUND((i.subtotal + i.tax + i.tax2) * 100)) FROM tblinvoices i WHERE {$notParent} GROUP BY i.userid");
        $in = $this->pairs('SELECT userid, SUM(ROUND(amountin * 100)) FROM tblaccounts GROUP BY userid');
        $out = $this->pairs('SELECT userid, SUM(ROUND(amountout * 100)) FROM tblaccounts GROUP BY userid');
        $currencies = $this->pairs('SELECT c.id, cur.code FROM tblclients c LEFT JOIN tblcurrencies cur ON cur.id = c.currency');

        $hereBilled = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->groupBy('invoices.client_id')->selectRaw('invoices.client_id k, SUM(ROUND(invoice_items.line_total * 100)) v')->pluck('v', 'k');
        $payments = fn () => DB::table('invoice_transactions')->join('invoices', 'invoices.id', '=', 'invoice_transactions.invoice_id')
            ->where('invoice_transactions.is_credit_transaction', false)->where('invoice_transactions.status', 'succeeded')->groupBy('invoices.client_id');
        $hereIn = $payments()->selectRaw('invoices.client_id k, SUM(ROUND(invoice_transactions.amount * 100)) v')->pluck('v', 'k');
        $hereOut = $payments()->selectRaw('invoices.client_id k, SUM(ROUND(invoice_transactions.refunded_amount * 100)) v')->pluck('v', 'k');
        $hereCredit = DB::table('credits')->groupBy('client_id')->selectRaw('client_id k, SUM(ROUND(amount * 100)) v')->pluck('v', 'k');
        $clients = DB::table('clients')->pluck('id', 'id');

        foreach ($this->whmcs->query('SELECT id, ROUND(credit * 100) credit FROM tblclients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $client = $clients->get($id);
            $this->check('Accounts', $client !== null, "account #{$id}: not here");
            if ($client === null) {
                continue;
            }
            $currency = (string) ($currencies[$id] ?? ''); // an account's money is in its WHMCS currency: told with it
            $this->money('Account credit', $id, (int) $row['credit'], (int) ($hereCredit[$id] ?? 0), $currency);
            $this->money('Account billed', $id, (int) ($billed[$id] ?? 0), (int) ($hereBilled[$id] ?? 0), $currency);
            $this->money('Account paid', $id, (int) ($in[$id] ?? 0), (int) ($hereIn[$id] ?? 0), $currency);
            $this->money('Account refunded', $id, (int) ($out[$id] ?? 0), (int) ($hereOut[$id] ?? 0), $currency);
        }
    }

    private function invoices(): void
    {
        $parents = array_fill_keys($this->parents(), true);
        $here = DB::table('invoices')->get(['id', 'client_id', 'status', 'due_at'])->keyBy('id');
        $lines = DB::table('invoice_items')->groupBy('invoice_id')->selectRaw('invoice_id k, SUM(ROUND(line_total * 100)) v')->pluck('v', 'k');
        foreach ($this->whmcs->query('SELECT id, userid, status, duedate, ROUND((subtotal + tax + tax2) * 100) amount FROM tblinvoices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (isset($parents[$id])) {
                continue; // a Mass Pay parent: no invoice here (its money is on its children)
            }
            $invoice = $here->get($id);
            $this->check('Invoices', $invoice !== null, "invoice #{$id}: not here");
            if ($invoice === null) {
                continue;
            }
            $status = ImportFromWhmcs::invoiceStatus((string) $row['status']);
            $this->check('Invoices', (int) $invoice->client_id === (int) $row['userid'], "invoice #{$id}: account #{$row['userid']} in WHMCS, #{$invoice->client_id} here");
            $this->check('Invoices', $status === (string) $invoice->status, "invoice #{$id}: {$status} in WHMCS, {$invoice->status} here");
            $this->check('Invoices', (int) $row['amount'] === (int) ($lines[$id] ?? 0),
                "invoice #{$id}: " . self::cents((int) $row['amount']) . ' billed in WHMCS, ' . self::cents((int) ($lines[$id] ?? 0)) . ' here');
            $this->check('Invoices', self::day($row['duedate']) === self::day($invoice->due_at),
                "invoice #{$id}: due " . self::day($row['duedate']) . ' in WHMCS, ' . self::day($invoice->due_at) . ' here');
        }
    }

    private function services(): void
    {
        $here = DB::table('services')->get(['id', 'client_id', 'product_id', 'status', 'price', 'expires_at', 'server_id', 'whmcs_server_id', 'username', 'domain'])->keyBy('id');
        $servers = Extension::query()->where('type', 'server')->whereNotNull('whmcs_id')->with('settings')->get()->keyBy('id');
        $byWhmcs = $servers->keyBy('whmcs_id');
        $whmcsServers = [];
        foreach ($this->whmcs->query('SELECT id, name, hostname, ipaddress FROM tblservers')->fetchAll(PDO::FETCH_ASSOC) as $server) {
            $whmcsServers[(int) $server['id']] = $server;
        }
        $status = ['Active' => 'active', 'Suspended' => 'suspended', 'Terminated' => 'terminated', 'Cancelled' => 'cancelled', 'Fraud' => 'cancelled', 'Pending' => 'pending'];
        foreach ($this->whmcs->query('SELECT id, userid, packageid, domainstatus, amount, nextduedate, server, username, domain FROM tblhosting ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $service = $here->get($id);
            $this->check('Services', $service !== null, "service #{$id}: not here");
            if ($service === null) {
                continue;
            }
            $want = $status[(string) $row['domainstatus']] ?? 'pending';
            $this->check('Services', (int) $service->client_id === (int) $row['userid'], "service #{$id}: account #{$row['userid']} in WHMCS, #{$service->client_id} here");
            $this->check('Services', (int) $service->product_id === (int) $row['packageid'], "service #{$id}: product #{$row['packageid']} in WHMCS, #{$service->product_id} here");
            $this->check('Services', $want === (string) $service->status, "service #{$id}: {$want} in WHMCS, {$service->status} here");
            // Never below zero here (ADV-551-3): a negative WHMCS amount is 0.
            $price = max(0, (int) round((float) $row['amount'] * 100));
            $this->check('Services', $price === (int) round((float) $service->price * 100),
                "service #{$id}: " . self::cents($price) . ' in WHMCS, ' . self::cents((int) round((float) $service->price * 100)) . ' here');
            $this->check('Services', self::day($row['nextduedate']) === self::day($service->expires_at),
                "service #{$id}: due " . self::day($row['nextduedate']) . ' in WHMCS, ' . self::day($service->expires_at) . ' here');

            // Where it lives: the very account on the very server (a live one only: an ended one is acted on no more).
            if (!in_array($want, ['active', 'suspended'], true)) {
                continue;
            }
            $this->check('Service accounts', trim((string) $row['username']) === trim((string) $service->username),
                "service #{$id}: username «{$row['username']}» in WHMCS, «{$service->username}» here");
            $this->check('Service accounts', strcasecmp(trim((string) $row['domain']), trim((string) $service->domain)) === 0,
                "service #{$id}: domain «{$row['domain']}» in WHMCS, «{$service->domain}» here");
            $whmcsServer = (int) $row['server'];
            if ($whmcsServer === 0) {
                // None in WHMCS: none here either, or one of a module of MyCS's own its product gives it (licensing, offline
                // SSL: no WHMCS server stands for it); never a server that came from WHMCS.
                $this->check('Service servers', $service->server_id === null || !$servers->has((int) $service->server_id),
                    "service #{$id}: no server in WHMCS, WHMCS's server #{$service->server_id} here");

                continue;
            }
            $server = $service->server_id === null ? null : $servers->get((int) $service->server_id);
            $expected = $byWhmcs->get($whmcsServer);
            $name = $whmcsServers[$whmcsServer]['name'] ?? "#{$whmcsServer}";
            $this->check('Service servers', $expected !== null, "service #{$id}: WHMCS server {$name} has no server here (no server action until staff set one)");
            if ($expected === null) {
                continue;
            }
            $this->check('Service servers', $server !== null && $server->id === $expected->id,
                "service #{$id}: on WHMCS server {$name}, here on " . ($server === null ? 'none' : "server #{$server->id} ({$server->name})"));
            // The server here points where WHMCS's does (its host or address in its settings).
            $host = strtolower((string) ($expected->settings->firstWhere('key', 'host')->value ?? $expected->settings->firstWhere('key', 'hostname')->value ?? ''));
            $theirs = array_filter([strtolower((string) ($whmcsServers[$whmcsServer]['hostname'] ?? '')), strtolower((string) ($whmcsServers[$whmcsServer]['ipaddress'] ?? ''))]);
            $this->check('Service servers', $host === '' || $theirs === [] || array_filter($theirs, fn (string $address) => str_contains($host, $address)) !== [],
                "service #{$id}: WHMCS server {$name} is " . implode(' / ', $theirs) . ", server #{$expected->id} here points to {$host}");
        }
    }

    private function domains(): void
    {
        $here = DB::table('domains')->get(['id', 'client_id', 'fqdn', 'expires_at', 'recurring_amount', 'registrar_connection_id'])->keyBy('id');
        $connections = DomainRegistrarConnection::query()->get(['id', 'registrar'])->keyBy('id');
        $aliases = [];
        foreach (WhmcsCredentialImport::REGISTRARS as $registrar => $modules) {
            foreach ($modules as $module) {
                $aliases[$module] = $registrar;
            }
        }
        foreach ($this->whmcs->query('SELECT id, userid, domain, expirydate, ROUND(recurringamount * 100) amount, registrar, status FROM tbldomains ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $domain = $here->get($id);
            $this->check('Domains', $domain !== null, "domain #{$id} ({$row['domain']}): not here");
            if ($domain === null) {
                continue;
            }
            $this->check('Domains', (int) $domain->client_id === (int) $row['userid'], "domain #{$id}: account #{$row['userid']} in WHMCS, #{$domain->client_id} here");
            $this->check('Domains', strcasecmp(trim((string) $row['domain']), (string) $domain->fqdn) === 0, "domain #{$id}: «{$row['domain']}» in WHMCS, «{$domain->fqdn}» here");
            $this->check('Domains', self::day($row['expirydate']) === self::day($domain->expires_at),
                "domain #{$id} ({$domain->fqdn}): expires " . self::day($row['expirydate']) . ' in WHMCS, ' . self::day($domain->expires_at) . ' here');
            $this->check('Domains', (int) $row['amount'] === (int) round((float) $domain->recurring_amount * 100),
                "domain #{$id} ({$domain->fqdn}): " . self::cents((int) $row['amount']) . ' in WHMCS, ' . self::cents((int) round((float) $domain->recurring_amount * 100)) . ' here');
            if (!in_array((string) $row['status'], ['Active', 'Pending', 'Pending Registration', 'Pending Transfer', 'Grace', 'Redemption'], true)) {
                continue; // an ended one is renewed nowhere
            }
            $module = strtolower(trim((string) $row['registrar']));
            $want = $aliases[$module] ?? $module;
            $got = (string) ($connections->get((int) $domain->registrar_connection_id)->registrar ?? 'none');
            $this->check('Domain registrars', $want === '' || $want === $got || ($got === 'manual' && !isset($aliases[$module])),
                "domain #{$id} ({$domain->fqdn}): registrar {$module} in WHMCS, {$got} here");
        }
    }

    private function money(string $check, int $client, int $whmcs, int $mycs, string $currency): void
    {
        $this->check($check, $whmcs === $mycs, "account #{$client}: " . self::cents($whmcs) . ' in WHMCS, ' . self::cents($mycs) . " here ({$currency})");
    }

    private function check(string $name, bool $same, string $what): void
    {
        $this->result[$name] ??= ['checked' => 0, 'differ' => [], 'count' => 0];
        $this->result[$name]['checked']++;
        if (!$same) {
            $this->result[$name]['count']++;
            if (count($this->result[$name]['differ']) < $this->examples) {
                $this->result[$name]['differ'][] = $what;
            }
        }
    }

    /** @return list<int> */
    private function parents(): array
    {
        return array_map('intval', $this->whmcs->query(WhmcsMassPay::PARENTS_SQL)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, mixed> */
    private function pairs(string $sql): array
    {
        return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private static function day(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' || str_starts_with($text, '0000-00-00') ? null : substr($text, 0, 10);
    }

    private static function cents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

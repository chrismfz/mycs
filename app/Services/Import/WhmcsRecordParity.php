<?php

namespace App\Services\Import;

use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Money\MoneyTrail;
use App\Services\Whmcs\CatalogImport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Parity record by record (docs/importer.md, «Parity»; predeploy review 2026-11-02: totals that agree can hide two errors
 * that cancel out). Each account, invoice, payment, service and domain of WHMCS's copy against the one of the same id here,
 * each mapped by the importer's own rules (its shared mappings): an account's money in its own currency; an invoice's
 * account, number, status, amount, due and paid days; a payment's invoice and amount; a service's account, product,
 * status, price, cycle and due date, and where it lives (its server and the address that is reached at, its username, its
 * domain: a suspension of the wrong account is the worst a cut-over can do); a domain's account, name, status, expiry,
 * amount and registrar; a live addon's parent, account, status, price, cycle and due date. What the importer leaves out by its own rule (a payment on no invoice here, money out on no payment
 * here) is a note, never a difference. Read only on both. Amounts in cents.
 */
final class WhmcsRecordParity
{
    /** A plan here by the name the importer picks it by (ImportFromWhmcs::planName): what it renews as. */
    private const CYCLES = [
        'Monthly' => ['recurring', 1, 'month'], 'Quarterly' => ['recurring', 3, 'month'], 'Semiannually' => ['recurring', 6, 'month'],
        'Annually' => ['recurring', 1, 'year'], 'Biennially' => ['recurring', 2, 'year'], 'Triennially' => ['recurring', 3, 'year'],
        'One-Time' => ['one-time', null, null], 'Free' => ['free', null, null],
    ];

    /** A service lives on its server while active or suspended; a pending one is created there when paid (review of #590). */
    private const ON_A_SERVER = ['active', 'suspended', 'pending'];

    /** @var array<string, array{records: array<int, true>, differ: array<int, true>, examples: list<string>}> */
    private array $checks = [];

    /** @var array<string, list<string>> by kind: what the importer leaves out by rule, a server staff set */
    private array $notes = [];

    /** @var array<int, list<int>>|null each Mass Pay parent with its children (its «Invoice» lines) */
    private ?array $parents = null;

    public function __construct(private readonly PDO $whmcs, private readonly int $examples = 20) {}

    /**
     * @return array<string, array{checked: int, count: int, differ: list<string>}> per check: how many records compared, how
     *                                                                              many of them differ, examples
     */
    public function run(): array
    {
        $this->checks = [];
        $this->notes = [];
        $this->accounts();
        $this->invoices();
        $this->payments();
        $this->services();
        $this->addons();
        $this->domains();

        return array_map(fn (array $check) => ['checked' => count($check['records']), 'count' => count($check['differ']), 'differ' => $check['examples']],
            $this->checks);
    }

    /** @return list<string> after run(), what is no difference but is to be known: left out by the importer's rule, set by staff */
    public function notes(): array
    {
        $shown = $this->examples;
        $out = [];
        foreach ($this->notes as $kind => $lines) {
            foreach (array_slice($lines, 0, $shown) as $line) {
                $out[] = "{$kind}: {$line}";
            }
            if (count($lines) > $shown) {
                $out[] = "{$kind}: … and " . (count($lines) - $shown) . ' more';
            }
        }

        return $out;
    }

    private function accounts(): void
    {
        $parents = $this->parents();
        $invoicesHere = $this->invoicesHere();
        $paymentsHere = array_fill_keys(DB::table('invoice_transactions')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $notParent = $parents === [] ? '1 = 1' : 'i.id NOT IN (' . implode(',', array_keys($parents)) . ')';
        $billed = $this->pairs("SELECT i.userid, SUM(ROUND((i.subtotal + i.tax + i.tax2) * 100)) FROM tblinvoices i WHERE {$notParent} GROUP BY i.userid");
        $currencies = $this->pairs('SELECT c.id, cur.code FROM tblclients c LEFT JOIN tblcurrencies cur ON cur.id = c.currency');

        // Whose a payment is: its own customer, or its invoice's where WHMCS left it 0 (the importer puts it on its invoice).
        // What the importer leaves out by its rule (as WhmcsParity::notes) is left out of the sums, and noted.
        $in = [];
        $out = [];
        foreach ($this->whmcs->query('SELECT a.*, i.userid AS parity_invoice_userid FROM tblaccounts a LEFT JOIN tblinvoices i ON i.id = a.invoiceid ORDER BY a.id')
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owner = (int) ($row['userid'] ?? 0) ?: (int) ($row['parity_invoice_userid'] ?? 0);
            $invoice = (int) ($row['invoiceid'] ?? 0);
            $amountIn = WhmcsMassPay::cents($row['amountin'] ?? 0);
            if ($amountIn > 0 && !isset($invoicesHere[$invoice]) && !isset($parents[$invoice])) {
                $this->note('Not imported (importPayments)', "payment #{$row['id']} of account #{$owner}, " . self::cents($amountIn)
                    . " on invoice #{$invoice}: on no invoice here; left out of its account's sums.");
            } elseif ($amountIn !== 0) {
                $in[$owner] = ($in[$owner] ?? 0) + $amountIn;
            }
            $amountOut = WhmcsMassPay::cents($row['amountout'] ?? 0);
            if ($amountOut > 0 && !isset($paymentsHere[(int) ($row['refundid'] ?? 0)])) {
                $this->note('Not imported (importRefunds)', "money out #{$row['id']} of account #{$owner}, " . self::cents($amountOut)
                    . ": on no payment here; left out of its account's sums.");
            } elseif ($amountOut !== 0) {
                $out[$owner] = ($out[$owner] ?? 0) + $amountOut;
            }
        }

        $hereBilled = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->groupBy('invoices.client_id')->selectRaw('invoices.client_id k, SUM(ROUND(invoice_items.line_total * 100)) v')->pluck('v', 'k');
        $payments = fn () => DB::table('invoice_transactions')->join('invoices', 'invoices.id', '=', 'invoice_transactions.invoice_id')
            ->where('invoice_transactions.is_credit_transaction', false)->where('invoice_transactions.status', 'succeeded')->groupBy('invoices.client_id');
        $hereIn = $payments()->selectRaw('invoices.client_id k, SUM(ROUND(invoice_transactions.amount * 100)) v')->pluck('v', 'k');
        $hereOut = $payments()->selectRaw('invoices.client_id k, SUM(ROUND(invoice_transactions.refunded_amount * 100)) v')->pluck('v', 'k');
        // WHMCS's money out includes its refunds to credit: here a payment's part refunded to credit (refund_to_credit, as
        // WhmcsParity counts it), never its whole credited part (an overpayment is credit too).
        $hereToCredit = DB::table('money_events')->join('invoice_transactions', 'invoice_transactions.id', '=', 'money_events.invoice_transaction_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_transactions.invoice_id')
            ->where('money_events.source', MoneyTrail::SOURCE_IMPORT)->where('money_events.cause', MoneyTrail::CAUSE_REFUND_TO_CREDIT)
            ->groupBy('invoices.client_id')->selectRaw('invoices.client_id k, SUM(ROUND(money_events.amount * 100)) v')->pluck('v', 'k');
        $hereCredit = DB::table('credits')->groupBy('client_id')->selectRaw('client_id k, SUM(ROUND(amount * 100)) v')->pluck('v', 'k');
        $clients = DB::table('clients')->pluck('id', 'id');

        foreach ($this->whmcs->query('SELECT id, ROUND(credit * 100) credit FROM tblclients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $client = $clients->get($id);
            $this->check('Accounts', $id, $client !== null, "account #{$id}: not here");
            if ($client === null) {
                continue;
            }
            $currency = (string) ($currencies[$id] ?? ''); // an account's money is in its WHMCS currency: told with it
            $this->money('Account credit', $id, (int) $row['credit'], (int) ($hereCredit[$id] ?? 0), $currency);
            $this->money('Account billed', $id, (int) ($billed[$id] ?? 0), (int) ($hereBilled[$id] ?? 0), $currency);
            $this->money('Account paid', $id, $in[$id] ?? 0, (int) ($hereIn[$id] ?? 0), $currency);
            $this->money('Account refunded', $id, $out[$id] ?? 0, (int) ($hereOut[$id] ?? 0) + (int) ($hereToCredit[$id] ?? 0), $currency);
        }
    }

    private function invoices(): void
    {
        $parents = $this->parents();
        $here = DB::table('invoices')->get(['id', 'number', 'client_id', 'status', 'due_at', 'paid_at'])->keyBy('id');
        $lines = DB::table('invoice_items')->groupBy('invoice_id')->selectRaw('invoice_id k, SUM(ROUND(line_total * 100)) v')->pluck('v', 'k');
        $rows = $this->whmcs->query('SELECT i.*, ROUND((i.subtotal + i.tax + i.tax2) * 100) AS parity_amount FROM tblinvoices i ORDER BY i.id')->fetchAll(PDO::FETCH_ASSOC);
        // A number two invoices have: the importer gives it to the first, none to the other (unique here).
        $numbers = [];
        foreach ($rows as $row) {
            $number = trim((string) ($row['invoicenum'] ?? ''));
            if ($number !== '' && !isset($parents[(int) $row['id']])) {
                $numbers[$number] = ($numbers[$number] ?? 0) + 1;
            }
        }
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($parents[$id])) {
                continue; // a Mass Pay parent: no invoice here (its money is on its children)
            }
            $invoice = $here->get($id);
            $this->check('Invoices', $id, $invoice !== null, "invoice #{$id}: not here");
            if ($invoice === null) {
                continue;
            }
            $status = ImportFromWhmcs::invoiceStatus((string) $row['status']);
            $this->check('Invoices', $id, (int) $invoice->client_id === (int) $row['userid'], "invoice #{$id}: account #{$row['userid']} in WHMCS, #{$invoice->client_id} here");
            $this->check('Invoices', $id, $status === (string) $invoice->status, "invoice #{$id}: {$status} in WHMCS, {$invoice->status} here");
            $this->check('Invoices', $id, (int) $row['parity_amount'] === (int) ($lines[$id] ?? 0),
                "invoice #{$id}: " . self::cents((int) $row['parity_amount']) . ' billed in WHMCS, ' . self::cents((int) ($lines[$id] ?? 0)) . ' here');
            $this->check('Invoices', $id, self::day($row['duedate']) === self::day($invoice->due_at),
                "invoice #{$id}: due " . self::day($row['duedate']) . ' in WHMCS, ' . self::day($invoice->due_at) . ' here');
            // Its number (a WHMCS without the column, a test fixture, has none to compare).
            if (array_key_exists('invoicenum', $row)) {
                $number = trim((string) $row['invoicenum']);
                $theirs = $number === '' ? null : $number;
                $ours = trim((string) $invoice->number) === '' ? null : trim((string) $invoice->number);
                if ($theirs !== null && $ours === null && ($numbers[$number] ?? 0) > 1) {
                    $this->note('Invoice numbers', "invoice #{$id}: its number «{$number}» is another WHMCS invoice's too, imported without one (importInvoices).");
                } else {
                    $this->check('Invoices', $id, $theirs === $ours, "invoice #{$id}: number «{$theirs}» in WHMCS, «{$ours}» here");
                }
            }
            // Paid on the day WHMCS says (the revenue reports count by it): only a paid one has one.
            if (array_key_exists('datepaid', $row)) {
                $paid = (string) $row['status'] === 'Paid' ? self::day($row['datepaid']) : null;
                $this->check('Invoices', $id, $paid === self::day($invoice->paid_at),
                    "invoice #{$id}: paid " . ($paid ?? 'never') . ' in WHMCS, ' . (self::day($invoice->paid_at) ?? 'never') . ' here');
            }
        }
    }

    /**
     * Each payment by its id (invoice_transactions.id = tblaccounts.id): on its invoice, for its amount. The account's sums
     * agree when a payment sits on another invoice of the same account (review of #590); this does not. A Mass Pay parent's
     * lands on one of its children (WhmcsMassPay); one on no invoice here is not imported (noted by accounts()).
     */
    private function payments(): void
    {
        $parents = $this->parents();
        $invoicesHere = $this->invoicesHere();
        $here = DB::table('invoice_transactions')->get(['id', 'invoice_id', 'amount', 'status', 'is_credit_transaction'])->keyBy('id');
        foreach ($this->whmcs->query('SELECT * FROM tblaccounts WHERE amountin > 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $invoice = (int) $row['invoiceid'];
            $children = $parents[$invoice] ?? null;
            if ($children === null && !isset($invoicesHere[$invoice])) {
                continue; // left out by the importer's rule: noted by accounts()
            }
            $cents = WhmcsMassPay::cents($row['amountin']);
            $payment = $here->get($id);
            $found = $payment !== null && !$payment->is_credit_transaction;
            $this->check('Payments', $id, $found, "payment #{$id} (" . self::cents($cents) . " on invoice #{$invoice}): not here");
            if (!$found) {
                continue;
            }
            if ($children !== null) {
                $this->check('Payments', $id, in_array((int) $payment->invoice_id, $children, true), "payment #{$id}: on Mass Pay invoice #{$invoice} in WHMCS"
                    . ($children === [] ? '' : ' (its invoices #' . implode(', #', $children) . ')') . ", here on invoice #{$payment->invoice_id}");
            } else {
                $this->check('Payments', $id, (int) $payment->invoice_id === $invoice, "payment #{$id}: on invoice #{$invoice} in WHMCS, #{$payment->invoice_id} here");
            }
            $this->check('Payments', $id, $cents === WhmcsMassPay::cents($payment->amount),
                "payment #{$id}: " . self::cents($cents) . ' in WHMCS, ' . self::cents(WhmcsMassPay::cents($payment->amount)) . ' here');
            $this->check('Payments', $id, (string) $payment->status === 'succeeded', "payment #{$id}: {$payment->status} here");
        }
    }

    private function services(): void
    {
        $here = DB::table('services')->get(['id', 'client_id', 'product_id', 'plan_id', 'status', 'price', 'expires_at', 'server_id', 'username', 'domain'])->keyBy('id');
        $plans = DB::table('plans')->get(['id', 'name', 'type', 'billing_period', 'billing_unit'])->keyBy('id');
        $productServers = DB::table('products')->pluck('server_id', 'id');
        // Every server here (one staff added, licensing's, offline SSL's too), named in what is said.
        $servers = Server::query()->get(['id', 'name', 'extension', 'whmcs_id'])->keyBy('id');
        $byWhmcs = $servers->whereNotNull('whmcs_id')->keyBy('whmcs_id');
        $addresses = $this->addresses($servers);
        $whmcsServers = [];
        foreach ($this->whmcs->query('SELECT * FROM tblservers')->fetchAll(PDO::FETCH_ASSOC) as $server) {
            $whmcsServers[(int) $server['id']] = $server;
        }
        // A cluster's nodes are one server here, its WHMCS id one node's (CatalogImport::CLUSTER_MODULES): each node is it.
        foreach ($whmcsServers as $whmcsId => $server) {
            $module = strtolower((string) ($server['type'] ?? ''));
            if (!$byWhmcs->has($whmcsId) && in_array($module, CatalogImport::CLUSTER_MODULES, true)) {
                $node = collect($whmcsServers)->first(fn ($other) => strtolower((string) ($other['type'] ?? '')) === $module && $byWhmcs->has((int) $other['id']));
                if ($node !== null) {
                    $byWhmcs->put($whmcsId, $byWhmcs->get((int) $node['id']));
                }
            }
        }
        $byHand = [];
        // Each account's group discount: WHMCS bills it as a line of its own on each renewal, the import takes it off (WG-1).
        try {
            $groupPercents = $this->pairs('SELECT c.id, g.discountpercent FROM tblclients c JOIN tblclientgroups g ON g.id = c.groupid WHERE g.discountpercent > 0');
        } catch (Throwable) {
            $groupPercents = [];
        }
        foreach ($this->whmcs->query('SELECT * FROM tblhosting ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $service = $here->get($id);
            $this->check('Services', $id, $service !== null, "service #{$id}: not here");
            if ($service === null) {
                continue;
            }
            $raw = (string) $row['domainstatus'];
            $want = ImportFromWhmcs::serviceStatus($raw);
            $this->check('Services', $id, isset(ImportFromWhmcs::SERVICE_STATUSES[$raw]), "service #{$id}: WHMCS status «{$raw}» unknown, imported {$want}: check it");
            $this->check('Services', $id, (int) $service->client_id === (int) $row['userid'], "service #{$id}: account #{$row['userid']} in WHMCS, #{$service->client_id} here");
            $this->check('Services', $id, (int) $service->product_id === (int) $row['packageid'], "service #{$id}: product #{$row['packageid']} in WHMCS, #{$service->product_id} here");
            $this->check('Services', $id, $want === (string) $service->status, "service #{$id}: {$want} in WHMCS, {$service->status} here");
            // Never below zero here (ADV-551-3): a negative WHMCS amount is 0. Less its account's group discount (WG-1).
            $price = WhmcsMassPay::cents(ClientGroupPricing::discounted(self::cents(max(0, WhmcsMassPay::cents($row['amount']))),
                number_format((float) ($groupPercents[(int) $row['userid']] ?? 0), 2, '.', '')));
            $this->check('Services', $id, $price === WhmcsMassPay::cents($service->price),
                "service #{$id}: " . self::cents($price) . ' in WHMCS, ' . self::cents(WhmcsMassPay::cents($service->price)) . ' here');
            // A «Free Account» has none here, whatever WHMCS kept (ImportFromWhmcs: ADV-594-1).
            $this->check('Services', $id, self::day($row['nextduedate']) === self::day($service->expires_at)
                || (trim((string) ($row['billingcycle'] ?? '')) === 'Free Account' && $service->expires_at === null),
                "service #{$id}: due " . self::day($row['nextduedate']) . ' in WHMCS, ' . self::day($service->expires_at) . ' here');
            // Its cycle: the plan the importer picks by name (ImportFromWhmcs::planName), compared by what it renews as.
            $cycle = trim((string) ($row['billingcycle'] ?? ''));
            $planName = ImportFromWhmcs::planName($cycle);
            $plan = $service->plan_id === null ? null : $plans->get((int) $service->plan_id);
            $live = in_array($want, self::ON_A_SERVER, true);
            $billed = "service #{$id}: billed «" . ($cycle !== '' ? $cycle : 'none') . '» in WHMCS, ';
            if ($plan === null) {
                $this->check('Services', $id, !$live, $billed . "no plan here (its product has no «{$planName}» plan)");
            } else {
                [$type, $period, $unit] = self::CYCLES[$planName];
                $same = (string) $plan->type === $type
                    && ($type !== 'recurring' || ((int) $plan->billing_period === $period && strtolower((string) $plan->billing_unit) === $unit));
                // A «Free Account» of a product with no Free plan: its first plan at 0.00 (ImportFromWhmcs::freeAccountPlan).
                $same = $same || ($cycle === 'Free Account' && WhmcsMassPay::cents($service->price) === 0
                    && (int) $service->plan_id === ImportFromWhmcs::freeAccountPlan((int) $service->product_id));
                $this->check('Services', $id, $same, $billed . "here on plan «{$plan->name}» ("
                    . ((string) $plan->type === 'recurring' ? "every {$plan->billing_period} {$plan->billing_unit}" : $plan->type) . ')');
            }

            // Where it lives: the very account on the very server. A live one only (an ended one is acted on no more); a
            // pending one is created on its server when paid, its account not made yet.
            if (!$live) {
                continue;
            }
            if ($want !== 'pending') {
                $this->check('Service accounts', $id, trim((string) $row['username']) === trim((string) $service->username),
                    "service #{$id}: username «{$row['username']}» in WHMCS, «{$service->username}» here");
                $this->check('Service accounts', $id, strcasecmp(trim((string) $row['domain']), trim((string) $service->domain)) === 0,
                    "service #{$id}: domain «{$row['domain']}» in WHMCS, «{$service->domain}» here");
            }
            $server = $service->server_id === null ? null : $servers->get((int) $service->server_id);
            $on = $service->server_id === null ? 'none' : "server #{$service->server_id}" . ($server === null ? '' : " ({$server->name})");
            $productServerId = $productServers[(int) $service->product_id] ?? null;
            $productServer = $productServerId === null ? null : $servers->get((int) $productServerId);
            if ($productServer !== null && $productServer->extension === 'OfflineSsl') {
                // A certificate of an SSL module with no extension here: on its provider's Offline SSL server (#532).
                $this->check('Service servers', $id, $server !== null && $server->id === $productServer->id,
                    "service #{$id}: an offline SSL certificate, on server #{$productServer->id} ({$productServer->name}) expected, here on {$on}");

                continue;
            }
            $whmcsServer = (int) ($row['server'] ?? 0);
            if ($whmcsServer === 0) {
                // None in WHMCS: none here either, or its product's (a module of MyCS's own: licensing).
                $this->check('Service servers', $id, $service->server_id === null || ($productServerId !== null && (int) $service->server_id === (int) $productServerId),
                    "service #{$id}: no server in WHMCS, here on {$on}, not its product's");

                continue;
            }
            $theirs = $whmcsServers[$whmcsServer] ?? null;
            $name = $theirs === null ? "#{$whmcsServer} (not in tblservers)" : (string) $theirs['name'];
            $expected = $byWhmcs->get($whmcsServer);
            if ($expected === null) {
                // Not imported (a module with no extension, a deleted one): one staff set by hand at WHMCS's very address is
                // the one; none is no server action, one elsewhere the wrong one.
                $at = $server === null || $theirs === null ? 'its address unknown' : $this->pointsElsewhere($addresses[$server->id] ?? [], $theirs, strict: true);
                $this->check('Service servers', $id, $server !== null && $at === null, $server === null
                    ? "service #{$id}: WHMCS server {$name} has no server here, and none is set (no server action until staff set one)"
                    : "service #{$id}: WHMCS server {$name} has no server here; here on {$on}, which points to {$at}");
                if ($server !== null && $at === null) {
                    $byHand["WHMCS server {$name}, here {$on}"][] = $id;
                }

                continue;
            }
            $this->check('Service servers', $id, $server !== null && $server->id === $expected->id, "service #{$id}: on WHMCS server {$name}, here on {$on}");
            // The server here is reached where WHMCS's is: its host, and its IP (cPanel and DirectAdmin connect to it). A
            // cluster's is reached at any of its nodes (Pegasus's service, the cluster's server at Vega's address).
            $module = strtolower((string) ($theirs['type'] ?? ''));
            $nodes = $theirs === null ? [] : (in_array($module, CatalogImport::CLUSTER_MODULES, true)
                ? array_filter($whmcsServers, fn ($other) => strtolower((string) ($other['type'] ?? '')) === $module) : [$theirs]);
            $at = null;
            foreach ($nodes as $node) {
                $at = $this->pointsElsewhere($addresses[$expected->id] ?? [], $node, strict: false);
                if ($at === null) {
                    break;
                }
            }
            $this->check('Service servers', $id, $at === null,
                "service #{$id}: WHMCS server {$name} is " . self::whmcsAddress($theirs ?? []) . ", server #{$expected->id} here points to {$at}");
        }
        foreach ($byHand as $what => $ids) {
            $this->note('Servers set by staff', "{$what}, at WHMCS's address: service(s) #" . implode(', #', $ids) . '.');
        }
    }

    /**
     * Each live WHMCS addon against its own service here (WhmcsAddonImport, docs/addons.md step 4): fastened to its parent, on
     * its account, an addon product, its status (suspended with a suspended parent), price, quantity, cycle and due date.
     * One whose parent is not here is left out by the importer's rule: a note.
     */
    private function addons(): void
    {
        try {
            $rows = $this->whmcs->query("SELECT a.*, h.domainstatus AS parent_status FROM tblhostingaddons a LEFT JOIN tblhosting h ON h.id = a.hostingid
                WHERE a.status IN ('" . implode("', '", WhmcsAddonImport::LIVE) . "') ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return; // a WHMCS without addons
        }
        $here = DB::table('services')->whereNotNull('whmcs_addon_id')
            ->get(['id', 'whmcs_addon_id', 'parent_service_id', 'client_id', 'product_id', 'plan_id', 'status', 'price', 'quantity', 'expires_at'])->keyBy('whmcs_addon_id');
        $parents = DB::table('services')->whereNull('parent_service_id')->pluck('client_id', 'id');
        $addonProducts = DB::table('products')->where('is_addon', true)->pluck('id')->flip();
        $plans = DB::table('plans')->get(['id', 'type', 'billing_period', 'billing_unit'])->keyBy('id');
        try {
            $groupPercents = $this->pairs('SELECT c.id, g.discountpercent FROM tblclients c JOIN tblclientgroups g ON g.id = c.groupid WHERE g.discountpercent > 0');
        } catch (Throwable) {
            $groupPercents = [];
        }
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parent = (int) $row['hostingid'];
            $service = $here->get($id);
            if ($service === null && !$parents->has($parent)) {
                $this->note('Addons', "WHMCS addon #{$id}: its service #{$parent} is not here (deleted in WHMCS), not imported by rule.");

                continue;
            }
            $this->check('Addons', $id, $service !== null, "addon #{$id}: not here");
            if ($service === null) {
                continue;
            }
            $this->check('Addons', $id, (int) $service->parent_service_id === $parent, "addon #{$id}: of service #{$parent} in WHMCS, of #" . ($service->parent_service_id ?? 'none') . ' here');
            $this->check('Addons', $id, (int) $service->client_id === (int) ($parents[$parent] ?? 0), "addon #{$id}: on account #{$service->client_id} here, its service on #" . ($parents[$parent] ?? '?'));
            $this->check('Addons', $id, $addonProducts->has((int) $service->product_id), "addon #{$id}: product #{$service->product_id} here is not an addon product");
            $want = ImportFromWhmcs::serviceStatus((string) $row['status']);
            $want = $want === 'active' && (string) $row['parent_status'] === 'Suspended' ? 'suspended' : $want;
            $this->check('Addons', $id, $want === (string) $service->status, "addon #{$id}: {$want} in WHMCS, {$service->status} here");
            $price = WhmcsMassPay::cents(ClientGroupPricing::discounted(self::cents(max(0, WhmcsMassPay::cents($row['recurring']))),
                number_format((float) ($groupPercents[(int) ($parents[$parent] ?? 0)] ?? 0), 2, '.', '')));
            $this->check('Addons', $id, $price === WhmcsMassPay::cents($service->price),
                "addon #{$id}: " . self::cents($price) . ' in WHMCS, ' . self::cents(WhmcsMassPay::cents($service->price)) . ' here');
            $this->check('Addons', $id, max(1, (int) $row['qty']) === (int) $service->quantity, "addon #{$id}: quantity {$row['qty']} in WHMCS, {$service->quantity} here");
            $cycle = trim((string) $row['billingcycle']);
            // An addon's may be «Free» or «One Time» as well as a service's names (WhmcsAddonImport::planFor).
            $name = match (strtolower(str_replace(['-', ' '], '', $cycle))) {
                'free', 'freeaccount' => 'Free',
                'onetime' => 'One-Time',
                default => ImportFromWhmcs::planName($cycle),
            };
            [$type, $period, $unit] = self::CYCLES[$name];
            $plan = $service->plan_id === null ? null : $plans->get((int) $service->plan_id);
            $this->check('Addons', $id, $plan !== null && (string) $plan->type === $type
                && ($type !== 'recurring' || ((int) $plan->billing_period === $period && strtolower((string) $plan->billing_unit) === $unit)),
                "addon #{$id}: billed «{$cycle}» in WHMCS, here " . ($plan === null ? 'on no plan' : ((string) $plan->type === 'recurring' ? "every {$plan->billing_period} {$plan->billing_unit}" : $plan->type)));
            if ($type === 'recurring') {
                $this->check('Addons', $id, self::day($row['nextduedate']) === self::day($service->expires_at),
                    "addon #{$id}: due " . self::day($row['nextduedate']) . ' in WHMCS, ' . self::day($service->expires_at) . ' here');
            }
        }
    }

    private function domains(): void
    {
        $here = DB::table('domains')->get(['id', 'client_id', 'fqdn', 'status', 'expires_at', 'recurring_amount', 'registrar_connection_id'])->keyBy('id');
        $connections = DomainRegistrarConnection::query()->get(['id', 'registrar'])->keyBy('id');
        $aliases = [];
        foreach (WhmcsCredentialImport::REGISTRARS as $registrar => $modules) {
            foreach ($modules as $module) {
                $aliases[$module] = $registrar;
            }
        }
        // Live here by name: of WHMCS's live rows of one name, one stays live, the others come cancelled (WhmcsDomainImport).
        $liveByName = $here->filter(fn ($domain) => !in_array((string) $domain->status, DomainStatus::terminalValues(), true))->groupBy('fqdn');
        $paidRenewals = array_fill_keys(array_map('intval', $this->whmcs->query("SELECT DISTINCT ii.relid FROM tblinvoiceitems ii
            JOIN tblinvoices i ON i.id = ii.invoiceid WHERE ii.type = 'Domain' AND i.status = 'Paid'")->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($this->whmcs->query('SELECT * FROM tbldomains ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            try {
                $name = Domain::normalise((string) $row['domain']);
            } catch (Throwable) {
                $name = null;
            }
            $domain = $here->get($id);
            if ($domain === null && $name === null) {
                $this->note('Not imported (WhmcsDomainImport)', "domain #{$id}: «{$row['domain']}» is not a domain name.");

                continue;
            }
            $this->check('Domains', $id, $domain !== null, "domain #{$id} ({$row['domain']}): not here");
            if ($domain === null) {
                continue;
            }
            $this->check('Domains', $id, (int) $domain->client_id === (int) $row['userid'], "domain #{$id}: account #{$row['userid']} in WHMCS, #{$domain->client_id} here");
            $this->check('Domains', $id, ($name ?? strtolower(trim((string) $row['domain']))) === (string) $domain->fqdn,
                "domain #{$id}: «{$row['domain']}» in WHMCS, «{$domain->fqdn}» here");
            $this->check('Domains', $id, self::day($row['expirydate']) === self::day($domain->expires_at),
                "domain #{$id} ({$domain->fqdn}): expires " . self::day($row['expirydate']) . ' in WHMCS, ' . self::day($domain->expires_at) . ' here');
            $this->check('Domains', $id, WhmcsMassPay::cents($row['recurringamount']) === WhmcsMassPay::cents($domain->recurring_amount),
                "domain #{$id} ({$domain->fqdn}): " . self::cents(WhmcsMassPay::cents($row['recurringamount'])) . ' in WHMCS, '
                . self::cents(WhmcsMassPay::cents($domain->recurring_amount)) . ' here');
            // Its status as the importer maps it: an ended one is renewed nowhere, a live one is.
            $want = WhmcsDomainImport::status((string) $row['status'], (string) ($row['type'] ?? ''));
            if ($want !== null && WhmcsDomainImport::registeredWhilePending($want, $row, isset($paidRenewals[$id]))) {
                $want = DomainStatus::Active;
            }
            $got = (string) $domain->status;
            $otherLive = $liveByName->get((string) $domain->fqdn, collect())->first(fn ($other) => (int) $other->id !== $id);
            if ($want !== null && !$want->isTerminal() && $got === DomainStatus::Cancelled->value && $otherLive !== null) {
                $this->note('Domains live twice', "domain #{$id} ({$domain->fqdn}): {$row['status']} in WHMCS, also live as #{$otherLive->id}: imported cancelled.");
            } else {
                $this->check('Domains', $id, $want !== null && $want->value === $got, "domain #{$id} ({$domain->fqdn}): "
                    . ($want === null ? "WHMCS status «{$row['status']}» unknown" : "{$want->value} in WHMCS ({$row['status']})") . ", {$got} here");
            }
            if (in_array($got, DomainStatus::terminalValues(), true)) {
                continue; // ended here: renewed nowhere
            }
            $module = strtolower(trim((string) $row['registrar']));
            $registrar = $aliases[$module] ?? $module;
            $connection = (string) ($connections->get((int) $domain->registrar_connection_id)->registrar ?? 'none');
            $this->check('Domain registrars', $id, $registrar === '' || $registrar === $connection || ($connection === 'manual' && !isset($aliases[$module])),
                "domain #{$id} ({$domain->fqdn}): registrar {$module} in WHMCS, {$connection} here");
        }
    }

    /**
     * Each server's address settings, its host (a URL or a name) and its IP, as its module reads them: a server's own
     * (App\Models\Server, as the catalog and the admin store them), else one stored as an Extension's.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int, array{host?: string, ip?: string}>
     */
    private function addresses(Collection $servers): array
    {
        $out = [];
        $settings = Setting::query()->whereIn('settingable_type', [Server::class, Extension::class])->whereIn('settingable_id', $servers->keys()->all())
            ->whereIn('key', ['host', 'hostname', 'ip'])->get()
            ->sortBy(fn (Setting $setting) => [$setting->settingable_type === Server::class ? 0 : 1, $setting->key === 'hostname' ? 1 : 0]);
        foreach ($settings as $setting) {
            $key = $setting->key === 'ip' ? 'ip' : 'host';
            $value = trim((string) $setting->value);
            if ($value !== '' && !isset($out[(int) $setting->settingable_id][$key])) {
                $out[(int) $setting->settingable_id][$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Where a server here is reached when that is not where WHMCS's is, or null: its host is WHMCS's hostname or its IP,
     * and its IP (where set) is WHMCS's IP. Strict: one with no address is not taken for WHMCS's (a server set by hand).
     *
     * @param  array{host?: string, ip?: string}  $ours
     * @param  array<string, mixed>  $theirs  its tblservers row
     */
    private function pointsElsewhere(array $ours, array $theirs, bool $strict): ?string
    {
        $theirHost = rtrim(strtolower(trim((string) ($theirs['hostname'] ?? ''))), '.');
        $theirIp = trim((string) ($theirs['ipaddress'] ?? ''));
        $host = $ours['host'] ?? '';
        $ip = $ours['ip'] ?? '';
        $shown = implode(' / ', array_filter([$host, $ip !== '' ? "IP {$ip}" : ''])) ?: 'no address';
        if ($theirHost === '' && $theirIp === '') {
            return $strict ? "{$shown} (WHMCS gives none)" : null; // nothing to compare with
        }
        $name = self::hostOf($host);
        $hostOk = $host === '' || ($name !== '' && (($theirHost !== '' && $name === $theirHost) || ($theirIp !== '' && self::sameIp($name, $theirIp))));
        $ipOk = $ip === '' || ($theirIp !== '' && self::sameIp($ip, $theirIp));

        return $hostOk && $ipOk && (!$strict || $host !== '' || $ip !== '') ? null : $shown;
    }

    /** A host setting's host name: a URL's (https://host:port/…), or the name itself; lowercase, no brackets or final dot. */
    private static function hostOf(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = parse_url(str_contains($value, '://') ? $value : "//{$value}", PHP_URL_HOST);

        return rtrim(strtolower(trim((string) $host, '[]')), '.');
    }

    private static function sameIp(string $a, string $b): bool
    {
        $a = @inet_pton(trim($a, '[] '));
        $b = @inet_pton(trim($b, '[] '));

        return $a !== false && $b !== false && $a === $b;
    }

    /** @param array<string, mixed> $server its tblservers row */
    private static function whmcsAddress(array $server): string
    {
        return implode(' / ', array_filter([strtolower(trim((string) ($server['hostname'] ?? ''))), trim((string) ($server['ipaddress'] ?? ''))])) ?: 'no address';
    }

    private function money(string $check, int $client, int $whmcs, int $mycs, string $currency): void
    {
        $this->check($check, $client, $whmcs === $mycs, "account #{$client}: " . self::cents($whmcs) . ' in WHMCS, ' . self::cents($mycs) . " here ({$currency})");
    }

    /** One comparison of a record: the record differs when any of its comparisons does, and is counted once. */
    private function check(string $name, int $record, bool $same, string $what): void
    {
        $this->checks[$name] ??= ['records' => [], 'differ' => [], 'examples' => []];
        $this->checks[$name]['records'][$record] = true;
        if (!$same) {
            $this->checks[$name]['differ'][$record] = true;
            if (count($this->checks[$name]['examples']) < $this->examples) {
                $this->checks[$name]['examples'][] = $what;
            }
        }
    }

    private function note(string $kind, string $what): void
    {
        $this->notes[$kind][] = $what;
    }

    /** @return array<int, list<int>> each Mass Pay parent (WhmcsMassPay::PARENTS_SQL, the importer's rule) with its children */
    private function parents(): array
    {
        if ($this->parents === null) {
            $this->parents = array_fill_keys(array_map('intval', $this->whmcs->query(WhmcsMassPay::PARENTS_SQL)->fetchAll(PDO::FETCH_COLUMN)), []);
            if ($this->parents !== []) {
                foreach ($this->whmcs->query("SELECT invoiceid, relid FROM tblinvoiceitems WHERE type = 'Invoice' AND invoiceid IN ("
                    . implode(',', array_keys($this->parents)) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $line) {
                    $this->parents[(int) $line['invoiceid']][] = (int) $line['relid'];
                }
            }
        }

        return $this->parents;
    }

    /** @return array<int, true> */
    private function invoicesHere(): array
    {
        return array_fill_keys(DB::table('invoices')->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
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

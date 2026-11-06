<?php

namespace App\Services\Whmcs;

use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Setting;
use App\Providers\SettingsProvider;
use App\Services\Licensing\WhmcsLicensingImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A WHMCS installation's catalog, copied (`app:import-whmcs-catalog`): currencies, product groups, products with their
 * plans and prices, config options, servers and server groups, the licensing products' settings, the TLDs and their
 * prices. No account, service, invoice, order or ticket. Read-only on WHMCS (a copy of it: docs/importer.md).
 *
 * Re-runnable. What it brought (`whmcs_id`, `whmcs_ref` on config options) follows WHMCS on a re-run: its fields, its
 * plans (by cycle) and prices; a cycle, currency or TLD period WHMCS no longer offers stops being sold here (a plan in
 * use keeps its row, without prices). What was here before is never taken over: an existing currency, a TLD of the
 * same name, keep theirs; a server of the same module and host is adopted, its settings untouched. Links staff added
 * (upgrades, config options) stay. Server passwords are never read, and the catalog alone brings no token: a cPanel server's
 * API token comes with the full import's credentials step (WhmcsCredentialImport).
 * All in one transaction; a dry run rolls it back, reports the same counts and leaves the cache alone.
 */
final class CatalogImport
{
    /** WHMCS's server module → MyCS's server extension; the others are reported and skipped. */
    private const SERVER_MODULES = ['cpanel' => 'CPanel', 'centovacast' => 'CentovaCast', 'mailcow' => 'Mailcow', 'pvewhmcs' => 'Proxmox',
        'hetznercloud' => 'OfflineVm', 'ngm' => 'Ngm'];

    /**
     * Modules whose WHMCS servers are nodes of one cluster: one server here for them all, as the extension talks to the
     * cluster (myip's Proxmox: Pegasus and Vega, owner 2026-11-04). A server staff already set up of the extension is it.
     */
    public const CLUSTER_MODULES = ['pvewhmcs'];

    /**
     * Modules with no API here (Hetzner Cloud): their servers come as Offline VM, so every action on their services is a
     * task for staff, never a silent «nothing» (docs/importer.md).
     */
    private const OFFLINE_MODULES = ['hetznercloud' => 'Hetzner Cloud'];

    /**
     * Modules whose API is on a port of its own, not WHMCS's server port (ngm's on 9601, its 443 serves the customers'
     * sites, as our WHMCS module ignores that port): their host comes bare, and the extension adds its port.
     */
    private const OWN_PORT_MODULES = ['ngm'];

    /** A module's default port when WHMCS has none (its admin API over TLS). */
    private const SERVER_PORTS = ['cpanel' => 2087, 'centovacast' => 2199, 'pvewhmcs' => 8006];

    /** WHMCS's recurring cycles → [plan name, billing period, unit, setup fee column]. */
    private const CYCLES = [
        'monthly' => ['Monthly', 1, 'month', 'msetupfee'],
        'quarterly' => ['Quarterly', 3, 'month', 'qsetupfee'],
        'semiannually' => ['Semiannually', 6, 'month', 'ssetupfee'],
        'annually' => ['Annually', 1, 'year', 'asetupfee'],
        'biennially' => ['Biennially', 2, 'year', 'bsetupfee'],
        'triennially' => ['Triennially', 3, 'year', 'tsetupfee'],
    ];

    /** tblpricing's columns for a domain's 1 to 10 years. */
    private const DOMAIN_YEARS = [1 => 'msetupfee', 2 => 'qsetupfee', 3 => 'ssetupfee', 4 => 'asetupfee', 5 => 'bsetupfee',
        6 => 'monthly', 7 => 'quarterly', 8 => 'semiannually', 9 => 'annually', 10 => 'biennially'];

    private const DOMAIN_OPERATIONS = ['domainregister' => 'register', 'domainrenew' => 'renew', 'domaintransfer' => 'transfer'];

    /** @var array<string, int> */
    private array $counts = ['currencies' => 0, 'categories' => 0, 'servers' => 0, 'server_groups' => 0, 'config_options' => 0,
        'products' => 0, 'plans' => 0, 'prices' => 0, 'licensing_products' => 0, 'tlds' => 0, 'tld_prices' => 0, 'withdrawn' => 0];

    /** @var list<string> what was skipped and why */
    private array $notes = [];

    /** @var array<int, string> WHMCS currency id => code */
    private array $currencies = [];

    /** WHMCS's default currency: its grace and redemption fees are in it. */
    private ?string $whmcsDefault = null;

    private bool $currencyAdded = false;

    private bool $settingsChanged = false;

    /** @var array<int, int> */
    private array $categoryIds = [];

    /** @var array<int, bool> WHMCS group id => hidden */
    private array $hiddenGroups = [];

    /** @var array<int, int> */
    private array $serverIds = [];

    /** @var array<string, int> WHMCS module => the MyCS server of its default (active) WHMCS server */
    private array $defaultServers = [];

    /** @var array<int, int> */
    private array $groupIds = [];

    /** @var array<int, int> WHMCS option id => MyCS config option id */
    private array $optionIds = [];

    /** @var array<int, int> */
    private array $productIds = [];

    /**
     * @param  ?int  $registrarConnectionId  the connection that takes WHMCS's TLDs with automatic registration at a
     *                                       registrar it has a driver for (by its key: openprovider, …); null: none
     * @param  bool  $keepWhmcsIds  a new product group or product takes its WHMCS id (the full importer's: its services,
     *                              orders and licenses point at WHMCS's product ids)
     */
    public function __construct(
        private readonly PDO $whmcs,
        private readonly bool $makeDefaultCurrency = true,
        private readonly ?int $registrarConnectionId = null,
        private readonly bool $keepWhmcsIds = false,
    ) {}

    /** @return array{counts: array<string, int>, notes: list<string>} */
    public function run(bool $dryRun = false): array
    {
        $connection = null;
        if ($this->registrarConnectionId !== null) {
            // A wrong id is an error, not «no connection» (review 12).
            $connection = DomainRegistrarConnection::query()->find($this->registrarConnectionId)
                ?? throw new InvalidArgumentException("No registrar connection #{$this->registrarConnectionId}.");
        }

        DB::beginTransaction();
        try {
            $this->currencies();
            $this->categories();
            $this->servers();
            $this->serverGroups();
            $this->configOptions();
            $this->products();
            $this->tlds($connection !== null ? [$connection->registrar => $connection] : []);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        // After the commit, never inside it or on a dry run (reviews 7, F7): a currency added clears the query cache as
        // the admin's does; the settings reload, queue workers included.
        if (!$dryRun && $this->currencyAdded) {
            Cache::flush();
        }
        if (!$dryRun && $this->settingsChanged) {
            SettingsProvider::flushCache();
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * The full importer's step (app:import-from-whmcs, before productsForFullImport, which gives each product its group
     * and server from it): WHMCS's servers and server groups, so that every account comes in on its own server (AUD4-1).
     * A product here already (found by its `whmcs_id`, else by the same id) is given its group and server too; in the
     * full import there is none yet.
     *
     * @return array{counts: array<string, int>, notes: list<string>, server_ids: array<int, int>} server_ids: WHMCS server id => its server here (a cluster's nodes share one)
     */
    public function serversForFullImport(): array
    {
        $this->servers();
        $this->serverGroups();
        foreach ($this->rows('SELECT id, name, servertype, servergroup FROM tblproducts ORDER BY id') as $row) {
            $product = Product::query()->where('whmcs_id', (int) $row['id'])->first() ?? Product::query()->whereNull('whmcs_id')->where('is_addon', false)->find((int) $row['id']);
            $module = strtolower((string) ($row['servertype'] ?? ''));
            if ($product === null || !isset(self::SERVER_MODULES[$module])) {
                continue;
            }
            $group = $this->groupIds[(int) ($row['servergroup'] ?? 0)] ?? null;
            $product->forceFill(['server_group_id' => $product->server_group_id ?? $group]);
            $product->server_id ??= $this->productServer($module, $group);
            if ($product->server_id === null) {
                $this->notes[] = "Product #{$row['id']} «{$row['name']}»: no {$module} server imported, set its server by hand.";
            }
            $product->save();
        }

        return ['counts' => $this->counts, 'notes' => $this->notes, 'server_ids' => $this->serverIds];
    }

    /**
     * The full importer's catalog step (WG-2), after serversForFullImport() on this same instance, whose servers and groups
     * its products take: the product groups, config options and products, their plans, prices, packages and links, as run()
     * brings them. Before it the full importer had its own older copy, which lost the cPanel and ngm packages, the hidden
     * groups, the retired products, the free (0.00) prices, the order and the config options' links. Made with their WHMCS
     * ids (keepWhmcsIds): the services, orders and licenses the full importer brings point at them.
     *
     * @return array{counts: array<string, int>, notes: list<string>}
     */
    public function productsForFullImport(): array
    {
        $this->notes = []; // the servers' were reported with their step
        $this->currencies();
        $this->categories();
        $this->configOptions();
        $this->products();

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * The full importer's TLD step (D6, before its domains): WHMCS's TLDs and their prices as run() brings them, each one
     * automatically registered at the connection made for its WHMCS registrar module, if any. Currencies are the full
     * importer's already: only read here (never a new default).
     *
     * @param  array<string, DomainRegistrarConnection>  $connections  WHMCS's registrar module => the connection here
     * @return array{counts: array<string, int>, notes: list<string>}
     */
    public function tldsForFullImport(array $connections): array
    {
        $this->currencies();
        $this->tlds($connections);

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    // ---- currencies, groups, servers -------------------------------------------------------------------------------

    private function currencies(): void
    {
        foreach ($this->rows('SELECT * FROM tblcurrencies ORDER BY id') as $row) {
            $code = strtoupper((string) $row['code']);
            $this->currencies[(int) $row['id']] = $code;
            if ((int) ($row['default'] ?? 0) === 1) {
                $this->whmcsDefault = $code;
            }
            // Past the query cache (a dry run must not leave a currency in it), and an existing one keeps its display
            // (review F6).
            if (!DB::table('currencies')->where('code', $code)->exists()) {
                $format = match ((int) ($row['format'] ?? 3)) {
                    1 => '1 000.00',
                    2, 4 => '1,000.00',
                    default => '1.000,00',
                };
                if (in_array((int) ($row['format'] ?? 3), [1, 4], true)) {
                    $this->notes[] = "Currency {$code}: WHMCS format {$row['format']} has no exact match here, «{$format}» set: check it under Currencies.";
                }
                // Its save clears the whole cache: after the commit instead (review F7).
                Currency::withoutEvents(fn () => Currency::query()->create([
                    'code' => $code, 'name' => $code, 'format' => $format,
                    'prefix' => (string) ($row['prefix'] ?? '') ?: null,
                    'suffix' => (string) ($row['suffix'] ?? '') ?: null,
                ]));
                $this->currencyAdded = true;
                $this->counts['currencies']++;
            }
            if ((int) ($row['default'] ?? 0) === 1 && $this->makeDefaultCurrency) {
                Setting::query()->updateOrCreate(['key' => 'default_currency', 'settingable_type' => null, 'settingable_id' => null], ['value' => $code, 'type' => 'string']);
                $this->settingsChanged = true;
            }
        }
    }

    private function categories(): void
    {
        foreach ($this->rows('SELECT * FROM tblproductgroups ORDER BY id') as $row) {
            $category = $this->byWhmcs(Category::class, 'whmcs_id', (int) $row['id']);
            $slug = (string) ($row['slug'] ?? '') ?: Str::slug((string) $row['name']);
            $category->forceFill([
                'name' => (string) $row['name'],
                'description' => $row['tagline'] ?? null,
                'sort' => max(0, min(255, (int) ($row['order'] ?? 0))), // an unsigned tinyint here (review 14)
                'slug' => $this->uniqueSlug('categories', $slug ?: 'group-' . $row['id'], $category->id),
                // A hidden group: off the menu, still sold by its link (docs/admin-ux.md, «Μενού επισκέπτη»).
                'show_in_menu' => !($row['hidden'] ?? false),
            ])->save();
            $this->categoryIds[(int) $row['id']] = $category->id;
            $this->hiddenGroups[(int) $row['id']] = (bool) ($row['hidden'] ?? false);
            $this->counts['categories']++;
        }
    }

    private function servers(): void
    {
        $clusters = [];
        foreach ($this->rows('SELECT * FROM tblservers ORDER BY id') as $row) {
            $module = strtolower((string) $row['type']);
            $extension = self::SERVER_MODULES[$module] ?? null;
            if ($extension === null) {
                $this->notes[] = "Server #{$row['id']} «{$row['name']}»: module «{$row['type']}» has no MyCS extension, skipped.";

                continue;
            }
            if (in_array($module, self::CLUSTER_MODULES, true)) {
                $clusters[$module][] = $row; // all its nodes together, below

                continue;
            }
            $hostname = strtolower(trim((string) ($row['hostname'] ?: $row['ipaddress'])));
            $server = Server::withTrashed()->where('whmcs_id', (int) $row['id'])->first();
            if ($server?->trashed()) {
                // Deleted here on purpose: not brought back (review 6).
                $this->notes[] = "Server #{$row['id']} «{$row['name']}»: deleted here, left deleted.";

                continue;
            }
            // One staff already set up (same module and host) is adopted, not copied without its password (review F10).
            $adopted = $server === null && ($server = $this->sameServer($extension, $hostname)) !== null;
            $server ??= new Server;
            $offline = self::OFFLINE_MODULES[$module] ?? null;
            $server->forceFill([
                'whmcs_id' => (int) $row['id'],
                'name' => $offline !== null ? "{$row['name']} ({$offline}, offline)" : (string) $row['name'],
                'extension' => $extension,
                'type' => 'server',
                'enabled' => !(int) ($row['disabled'] ?? 0),
                'max_accounts' => (int) ($row['maxaccounts'] ?? 0) > 0 ? (int) $row['maxaccounts'] : null,
            ])->save();
            // Its address (a URL, as the extension takes it: review 3) and login, only where none is set yet: a URL or
            // login staff corrected stays, and a stored API key is never sent to a host they did not type (R6). Never
            // its password (the API token comes with the credentials step). An offline one has neither.
            $port = (int) ($row['port'] ?? 0) ?: (self::SERVER_PORTS[$module] ?? 443);
            $settings = match (true) {
                $offline !== null => [],
                in_array($module, self::OWN_PORT_MODULES, true) => ['host' => $hostname], // no login: its token is staff's to enter
                default => ['host' => $hostname !== '' ? "https://{$hostname}:{$port}" : '', 'username' => (string) ($row['username'] ?? '')],
            };
            // Its IP (WHMCS's ipaddress): the panel's connection goes to it whatever DNS says (PanelConnection). Only for a
            // module with that setting; a public address only: a private or reserved one is pinned to nothing the panel could reach.
            // A server staff set up (adopted) keeps its connection: its host may be one they corrected (ADV-561-3).
            if ($module === 'cpanel' && !$adopted) {
                $ip = trim((string) ($row['ipaddress'] ?? ''));
                $settings['ip'] = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ? $ip : '';
            }
            foreach ($settings as $key => $value) {
                $where = ['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id, 'key' => $key];
                if ($value !== '' && !Setting::query()->where($where)->exists()) {
                    Setting::query()->create($where + ['value' => $value, 'type' => 'string', 'encrypted' => false]);
                }
            }
            $this->serverIds[(int) $row['id']] = $server->id;
            if (($row['active'] ?? 0) && !($row['disabled'] ?? 0)) {
                $this->defaultServers[$module] = $server->id;
            }
            $this->defaultServers[$module] ??= $server->id;
            $this->counts['servers']++;
        }
        foreach ($clusters as $module => $rows) {
            $this->cluster($module, $rows);
        }
    }

    /**
     * A cluster's WHMCS servers (its nodes) as its one server here (CLUSTER_MODULES), found by any node's id on a re-run.
     * Kept as it is once here, made by the import or adopted from staff (its name, state, addresses and token are staff's
     * then: review of #628); deleted here, left deleted. A new one is enabled when a node is, its live nodes' addresses
     * first: the host, then the extension's failover nodes.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function cluster(string $module, array $rows): void
    {
        $extension = self::SERVER_MODULES[$module];
        $ids = array_map(fn (array $row) => (int) $row['id'], $rows);
        $names = implode(', ', array_map(fn (array $row) => "#{$row['id']} «{$row['name']}»", $rows));
        $live = array_values(array_filter($rows, fn (array $row) => !(int) ($row['disabled'] ?? 0)));
        $nodes = [...$live, ...array_values(array_filter($rows, fn (array $row) => (bool) (int) ($row['disabled'] ?? 0)))];
        $hostOf = fn (array $row): string => strtolower(trim((string) ($row['hostname'] ?: $row['ipaddress'])));
        $server = Server::withTrashed()->whereIn('whmcs_id', $ids)->orderBy('id')->first();
        if ($server?->trashed()) {
            $this->notes[] = "Servers {$names} («{$module}»): their cluster's server was deleted here, left deleted.";

            return;
        }
        // Staff's own, or the one made before whose node has left WHMCS: of the extension, at any node's hostname or IP in
        // any of its addresses (ADV-628-3, ADV-628-4); else the extension's only one staff set up (owner 2026-11-04).
        $server ??= $this->clusterAt($extension, $nodes);
        $server ??= $this->onlyServer($extension);
        $made = $server === null;
        if ($server === null) {
            $server = new Server;
            $server->forceFill(['whmcs_id' => (int) $nodes[0]['id'], 'name' => (string) $nodes[0]['name'], 'extension' => $extension, 'type' => 'server',
                'enabled' => $live !== []])->save();
            foreach (array_slice($nodes, 0, 3) as $i => $node) {
                $host = $hostOf($node);
                if ($host !== '') {
                    $port = (int) ($node['port'] ?? 0) ?: (self::SERVER_PORTS[$module] ?? 443);
                    Setting::query()->create(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id,
                        'key' => ['host', 'node2_host', 'node3_host'][$i], 'value' => "https://{$host}:{$port}", 'type' => 'string', 'encrypted' => false]);
                }
            }
        } elseif (!in_array((int) $server->whmcs_id, $ids, true)) {
            // Adopted, or its node gone from WHMCS: a node of now, so a re-run finds it by id.
            $server->forceFill(['whmcs_id' => (int) $nodes[0]['id']])->save();
        }
        foreach ($ids as $id) {
            $this->serverIds[$id] = $server->id;
        }
        $this->defaultServers[$module] = $server->id;
        $this->counts['servers']++;
        $this->notes[] = "Servers {$names} («{$module}»): one cluster here, server «{$server->name}» (" . ($made ? 'made' : 'kept as it is') . ', '
            . ($server->enabled ? 'enabled' : 'disabled') . '). Its API token is staff\'s to enter, and its VMs are mapped by hand (Proxmox Guests).';
    }

    /**
     * A server of this extension reached at one of the nodes: any node's hostname or IP in its host, its failover nodes'
     * hosts or its IPs (a URL or a bare name). The first by id when more than one.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function clusterAt(string $extension, array $nodes): ?Server
    {
        $addresses = [];
        foreach ($nodes as $node) {
            foreach (['hostname', 'ipaddress'] as $key) {
                $address = strtolower(trim((string) ($node[$key] ?? '')));
                if ($address !== '') {
                    $addresses[$address] = true;
                }
            }
        }
        foreach (Server::query()->where('extension', $extension)->orderBy('id')->get() as $server) {
            $values = Setting::query()->where(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id])
                ->whereIn('key', ['host', 'node2_host', 'node3_host', 'ip', 'node2_ip', 'node3_ip'])->pluck('value');
            foreach ($values as $value) {
                $value = strtolower(trim((string) $value));
                $host = parse_url(str_contains($value, '://') ? $value : "https://{$value}", PHP_URL_HOST);
                if (is_string($host) && isset($addresses[trim($host, '[]')])) {
                    return $server;
                }
            }
        }

        return null;
    }

    /** The one server of this extension staff set up (not from WHMCS), or none when there is none or more than one. */
    private function onlyServer(string $extension): ?Server
    {
        $servers = Server::query()->where('extension', $extension)->whereNull('whmcs_id')->limit(2)->get();

        return $servers->count() === 1 ? $servers->first() : null;
    }

    /** A server of this extension not from WHMCS, at this host (its URL or bare name). */
    private function sameServer(string $extension, string $hostname): ?Server
    {
        if ($hostname === '') {
            return null;
        }
        foreach (Server::query()->where('extension', $extension)->whereNull('whmcs_id')->get() as $server) {
            $host = strtolower(trim((string) Setting::query()
                ->where(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id, 'key' => 'host'])->value('value')));
            if ($host !== '' && parse_url(str_contains($host, '://') ? $host : "https://{$host}", PHP_URL_HOST) === $hostname) {
                return $server;
            }
        }

        return null;
    }

    private function serverGroups(): void
    {
        foreach ($this->rows('SELECT * FROM tblservergroups ORDER BY id') as $row) {
            $group = $this->byWhmcs(ServerGroup::class, 'whmcs_id', (int) $row['id']);
            $group->forceFill([
                'name' => (string) $row['name'],
                'fill_type' => (int) ($row['filltype'] ?? 1) === 2 ? ServerGroup::FILL_IN_ORDER : ServerGroup::FILL_LEAST_FULL,
            ])->save();
            $this->groupIds[(int) $row['id']] = $group->id;
            $this->counts['server_groups']++;
        }
        foreach ($this->rows('SELECT * FROM tblservergroupsrel ORDER BY groupid, serverid') as $i => $row) {
            $group = $this->groupIds[(int) $row['groupid']] ?? null;
            $server = $this->serverIds[(int) $row['serverid']] ?? null;
            if ($group !== null && $server !== null) {
                DB::table('server_group_server')->updateOrInsert(['server_group_id' => $group, 'server_id' => $server], ['position' => $i]);
            }
        }
    }

    // ---- config options, products, prices --------------------------------------------------------------------------

    private function configOptions(): void
    {
        // WHMCS's «Quantity» options a service ever took (WG-15): the others are not brought (myip: 133 of 134, SSL SANs
        // and servers, dediserve, onApp and Hetzner VPS sizes the modules made and nobody bought); the ones taken come
        // hidden, as MyCS does not charge a quantity yet (CART-9). Unknown (no tblhostingconfigoptions): all brought.
        try {
            $used = array_flip(array_map('intval', array_column($this->rows('SELECT DISTINCT configid FROM tblhostingconfigoptions WHERE qty > 0'), 'configid')));
        } catch (PDOException) {
            $used = null;
        }
        $skipped = 0;
        foreach ($this->rows('SELECT * FROM tblproductconfigoptions ORDER BY id') as $row) {
            $quantity = (int) ($row['optiontype'] ?? 1) === 4;
            if ($quantity && $used !== null && !isset($used[(int) $row['id']])) {
                $skipped++;
                // One an earlier run brought (the catalog's own command on a live MyCS) is hidden, never left on sale for
                // nothing (R662-1).
                $earlier = ConfigOption::query()->where('whmcs_ref', 'option:' . $row['id'])->first();
                if ($earlier !== null && !$earlier->hidden) {
                    $earlier->forceFill(['hidden' => true])->save();
                    $this->notes[] = "Quantity option #{$row['id']} «{$earlier->name}»: brought by an earlier run, no service ever took it: hidden now.";
                }

                continue;
            }
            [$env, $name] = $this->envName((string) $row['optionname']);
            $option = $this->byWhmcs(ConfigOption::class, 'whmcs_ref', 'option:' . $row['id']);
            if ($quantity) {
                $this->notes[] = "Quantity option #{$row['id']} «{$name}»: brought hidden, MyCS does not charge a quantity yet (WG-15, CART-9): "
                    . 'the services that took it keep WHMCS\'s price; an addon product does it here (importer.md).';
            }
            $option->forceFill([
                'name' => $name, 'env_variable' => $env, 'sort' => (int) ($row['order'] ?? 0), 'hidden' => $quantity || (bool) ($row['hidden'] ?? false),
                'type' => match ((int) ($row['optiontype'] ?? 1)) {
                    2 => 'radio',
                    3 => 'checkbox',
                    4 => 'number',
                    default => 'select',
                },
            ])->save();
            $this->optionIds[(int) $row['id']] = $option->id;
            $this->counts['config_options']++;

            foreach ($this->rows('SELECT * FROM tblproductconfigoptionssub WHERE configid = ? ORDER BY sortorder, id', [$row['id']]) as $sub) {
                [$env, $name] = $this->envName((string) $sub['optionname']);
                $value = $this->byWhmcs(ConfigOption::class, 'whmcs_ref', 'value:' . $sub['id']);
                $value->forceFill(['parent_id' => $option->id, 'name' => $name, 'env_variable' => $env, 'sort' => (int) ($sub['sortorder'] ?? 0)])->save();
                $this->prices($value, $this->rows("SELECT * FROM tblpricing WHERE type = 'configoptions' AND relid = ?", [$sub['id']]), 'recurring');
            }
        }
        if ($skipped > 0) {
            $this->notes[] = "{$skipped} «Quantity» option(s) no service ever took in WHMCS: not brought (WG-15).";
        }
    }

    private function products(): void
    {
        $licensing = null;
        $rows = $this->rows('SELECT * FROM tblproducts ORDER BY id');
        foreach ($rows as $row) {
            $categoryId = $this->categoryIds[(int) $row['gid']] ?? null;
            // Its group deleted in WHMCS, which still renews its services: brought, hidden, in a hidden group of its own, never
            // skipped (ADV-646-1: a live service on it pointed at no product and no plan, never renewed).
            $orphan = $categoryId === null;
            if ($orphan) {
                $categoryId = $this->groupless();
                $this->notes[] = "Product #{$row['id']} «{$row['name']}»: its group #{$row['gid']} is missing in WHMCS, brought hidden in «" . self::GROUPLESS_NAME . '».';
            }
            $product = $this->byWhmcs(Product::class, 'whmcs_id', (int) $row['id']);
            $slug = (string) ($row['slug'] ?? '') ?: Str::slug((string) $row['name']);
            $retired = (bool) ($row['retired'] ?? false);
            $module = strtolower((string) ($row['servertype'] ?? ''));
            $group = $this->groupIds[(int) ($row['servergroup'] ?? 0)] ?? null;
            if (($row['proratabilling'] ?? 0) && (int) ($row['proratadate'] ?? 0) > 28) {
                // Pro rata aligns to a day every month has here (PR D).
                $this->notes[] = "Product #{$row['id']} «{$row['name']}»: pro rata day {$row['proratadate']} imported as 28.";
            }
            $product->forceFill([
                'category_id' => $categoryId,
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'slug' => $this->uniqueSlug('products', $slug ?: 'product-' . $row['id'], $product->id),
                // Retired in WHMCS: not ordered any more, its own flag (ADV-1 of #216: a stock of 0 came back with the
                // first cancellation); a hidden group's products are not listed (reviews 5, F1).
                'hidden' => (bool) ($row['hidden'] ?? false) || $retired || $orphan || ($this->hiddenGroups[(int) $row['gid']] ?? false),
                'retired' => $retired,
                'taxable' => (bool) ($row['tax'] ?? true),
                'prorata_day' => ($row['proratabilling'] ?? 0) && (int) ($row['proratadate'] ?? 0) >= 1 ? min((int) $row['proratadate'], 28) : null,
                'prorata_next_after_day' => ($row['proratabilling'] ?? 0) && (int) ($row['proratachargenextmonth'] ?? 0) >= 1
                    && (int) $row['proratachargenextmonth'] <= 31 ? (int) $row['proratachargenextmonth'] : null,
                'autosetup' => Product::AUTOSETUP_FROM_WHMCS[$row['autosetup'] ?? 'payment'] ?? Product::AUTOSETUP_PAYMENT,
                'stock' => ($row['stockcontrol'] ?? 0) ? (int) $row['qty'] : null,
                'allow_quantity' => match ((int) ($row['allowqty'] ?? 0)) {
                    1 => 'separated',
                    3 => 'combined',
                    default => 'disabled',
                },
                'server_group_id' => $group,
            ]);
            // Its server gives it its module (ServerSelector): the group's first of the module, else WHMCS's default
            // server of the module, as WHMCS picks one without a group (review 1). One staff set stays.
            if ($product->server_id === null && isset(self::SERVER_MODULES[$module])) {
                $server = $this->productServer($module, $group);
                $product->server_id = $server;
                if ($server === null) {
                    $this->notes[] = "Product #{$row['id']} «{$row['name']}»: no {$module} server imported, set its server by hand.";
                }
            }
            $product->save();
            $this->productIds[(int) $row['id']] = $product->id;
            $this->counts['products']++;

            if (($row['paytype'] ?? '') === 'free') {
                $free = $this->plan($product, 'free', null, null);
                $free->forceFill(['name' => $free->exists ? $free->name : 'Free'])->save();
                $this->counts['plans']++;
                $this->withdraw($product, [$free->id]);
            } else {
                $this->prices($product, $this->rows("SELECT * FROM tblpricing WHERE type = 'product' AND relid = ?", [$row['id']]), (string) ($row['paytype'] ?? 'recurring'));
            }

            if ($module === 'cpanel' && (string) ($row['configoption1'] ?? '') !== '') {
                Setting::query()->updateOrCreate(['settingable_type' => $product->getMorphClass(), 'settingable_id' => $product->id, 'key' => 'package'],
                    ['value' => (string) $row['configoption1'], 'type' => 'string', 'encrypted' => false]);
            }
            if (in_array($module, ['cpanel', 'mailcow', 'centovacast'], true)) {
                $this->moduleSettings($product, $module, $row);
            }
            if ($module === 'ngm') {
                // As our WHMCS module's options: configoption1 the ngm package (by name), configoption2 the PHP version.
                foreach (['package' => 'configoption1', 'php' => 'configoption2'] as $key => $option) {
                    if (trim((string) ($row[$option] ?? '')) !== '') {
                        Setting::query()->updateOrCreate(['settingable_type' => $product->getMorphClass(), 'settingable_id' => $product->id, 'key' => $key],
                            ['value' => trim((string) $row[$option]), 'type' => 'string', 'encrypted' => false]);
                    }
                }
            }
            if ($module === 'licensing') {
                $licensing ??= Server::query()->firstOrCreate(['extension' => 'NixpalLicensing'], ['name' => 'nixpal Licensing', 'type' => 'server', 'enabled' => true]);
                (new WhmcsLicensingImport($this->whmcs))->productSettings($product, $row, $licensing);
                $this->counts['licensing_products']++;
            } elseif ($module !== '' && !isset(self::SERVER_MODULES[$module])) {
                // A server module's product has its server (above): only one of a module with no extension here is told
                // (review of #646: ~80 notes on myip hid the few that need staff).
                $this->notes[] = "Product #{$row['id']} «{$row['name']}»: module «{$module}» not mapped (its settings stay to be set).";
            }
        }

        // Once every product is here: its upgrades and its config options, added (review F9: links staff added stay;
        // one WHMCS dropped is left for staff).
        foreach ($rows as $row) {
            $product = isset($this->productIds[(int) $row['id']]) ? Product::query()->find($this->productIds[(int) $row['id']]) : null;
            if ($product === null) {
                continue;
            }
            $upgrades = array_filter(array_map(fn (array $u) => $this->productIds[(int) $u['upgrade_product_id']] ?? null,
                $this->rows('SELECT upgrade_product_id FROM tblproduct_upgrade_products WHERE product_id = ?', [$row['id']])));
            $product->upgrades()->syncWithoutDetaching(array_values($upgrades));
            foreach ($this->rows('SELECT o.id FROM tblproductconfiglinks l JOIN tblproductconfigoptions o ON o.gid = l.gid WHERE l.pid = ?', [$row['id']]) as $o) {
                $link = ['product_id' => $product->id, 'config_option_id' => $this->optionIds[(int) $o['id']] ?? null];
                if ($link['config_option_id'] !== null && !DB::table('config_option_products')->where($link)->exists()) {
                    DB::table('config_option_products')->insert($link);
                }
            }
        }
    }

    /**
     * A product's module settings beyond the package (WG-16, owner 2026-11-05), as the extension here reads them, from
     * WHMCS's configoptionN of the module:
     * - cPanel, a «Reseller Account» product (myip: 6, 23 live resellers): 15 limit by number (the accounts), 17 disk and
     *   18 bandwidth (MB, whatever 16 «limit by usage» says: owner 2026-11-05, MyCS's products set the limits), 19/20
     *   overselling, 21 the ACL list, 24 the reseller owns its account. Without it a create or upgrade made a plain account, and a suspension for non-payment
     *   suspended the reseller alone, its customers' accounts running (WHM's suspendreseller).
     * - mailcow (myip's MyMail): 1 aliases, 2 mailboxes, 3 default and 4 max mailbox quota, 5 domain quota (MB), 6/7 the
     *   rate limit and its frame, 8 DKIM, 9 its selector, 10 its key size, 11 restart SOGo, 12 terminate mode, 13 lock on
     *   suspend, 14 lock even a multi-domain admin: the order of both modules.
     * - Centova Cast: 1 the account template, 6 «start server» (the limits are the template's).
     * Not brought: cPanel themes (paper_lantern, x3mail, gone from cPanel) and the old custom limits a package overrides.
     *
     * @param  array<string, mixed>  $row  a tblproducts row
     */
    private function moduleSettings(Product $product, string $module, array $row): void
    {
        $option = fn (int $n) => trim((string) ($row['configoption' . $n] ?? ''));
        $on = fn (int $n) => in_array(strtolower($option($n)), ['on', 'yes', '1', 'true'], true);
        $number = fn (int $n) => ctype_digit($option($n)) && strlen($option($n)) <= 9 ? (string) (int) $option($n) : null;
        $resellerKeys = ['reseller', 'reseller_acl', 'reseller_max_accounts', 'reseller_disk_mb', 'reseller_bandwidth_mb',
            'reseller_overselling_disk', 'reseller_overselling_bandwidth', 'reseller_owns_itself'];
        $settings = match ($module) {
            // A product no longer a reseller one in WHMCS drops them all on a re-run (R663-2).
            'cpanel' => ($row['type'] ?? '') !== 'reselleraccount' ? array_fill_keys($resellerKeys, null) : [
                'reseller' => '1',
                'reseller_acl' => $option(21) !== '' ? $option(21) : null,
                'reseller_max_accounts' => $number(15),
                // The product's limits whether WHMCS applied them or not (owner 2026-11-05: limits are MyCS's products',
                // never set by hand in WHM again; Reseller 30/70/120 left «by usage» off and staff set them in WHM).
                'reseller_disk_mb' => $number(17),
                'reseller_bandwidth_mb' => $number(18),
                'reseller_overselling_disk' => $on(19) ? '1' : '0',
                'reseller_overselling_bandwidth' => $on(20) ? '1' : '0',
                'reseller_owns_itself' => $on(24) ? '1' : '0',
            ],
            'mailcow' => [
                'aliases' => $number(1), 'mailboxes' => $number(2), 'defquota' => $number(3), 'maxquota' => $number(4), 'quota' => $number(5),
                'rl_value' => $number(6), 'rl_frame' => in_array($option(7), ['s', 'm', 'h', 'd'], true) ? $option(7) : null,
                'create_dkim' => $on(8) ? '1' : '0', 'dkim_selector' => $option(9) !== '' ? $option(9) : null,
                'dkim_keysize' => in_array($option(10), ['1024', '2048', '4096'], true) ? $option(10) : null,
                'restart_sogo' => $option(11) === '0' ? '0' : '1',
                'terminate_mode' => in_array($option(12), ['lock_only', 'delete_all'], true) ? $option(12) : null,
                'lock_on_suspend' => $on(13) ? '1' : '0', 'force_lock_multi' => $on(14) ? '1' : '0',
            ],
            'centovacast' => ['template' => $option(1) !== '' ? $option(1) : null, 'autostart' => $on(6) ? '1' : '0'],
            default => [],
        };
        foreach ($settings as $key => $value) {
            $where = ['settingable_type' => $product->getMorphClass(), 'settingable_id' => $product->id, 'key' => $key];
            // One WHMCS no longer sets is gone on a re-run, never an older run's left behind (R663-2).
            $value === null ? Setting::query()->where($where)->delete()
                : Setting::query()->updateOrCreate($where, ['value' => $value, 'type' => 'string', 'encrypted' => false]);
        }
    }

    private const GROUPLESS_NAME = 'Without a group (WHMCS)';

    /** The hidden group of the products whose WHMCS group is gone: one, found again on a re-run by its slug. */
    private function groupless(): int
    {
        $category = Category::query()->whereNull('whmcs_id')->where('slug', 'whmcs-without-group')->first() ?? new Category;
        if (!$category->exists) {
            $category->forceFill(['name' => self::GROUPLESS_NAME, 'slug' => $this->uniqueSlug('categories', 'whmcs-without-group', null),
                'show_in_menu' => false, 'sort' => 255])->save();
        }

        return $category->id;
    }

    /** The group's first enabled server of the module, else the module's default one. */
    private function productServer(string $module, ?int $group): ?int
    {
        if ($group !== null) {
            $first = ServerGroup::query()->find($group)?->servers->first(fn (Server $s) => $s->enabled && $s->extension === self::SERVER_MODULES[$module]);
            if ($first !== null) {
                return $first->id;
            }
        }

        return $this->defaultServers[$module] ?? null;
    }

    /**
     * A product's (or a config option value's) prices: a plan per cycle priced in any currency (WHMCS -1 = not
     * offered; 0.00 is offered, free), its price per currency. A cycle or currency WHMCS no longer offers is withdrawn
     * (reviews 4, F2, F3).
     *
     * @param  list<array<string, mixed>>  $pricing  tblpricing rows, one per currency
     */
    private function prices(Product|ConfigOption $priceable, array $pricing, string $paytype): void
    {
        $cycles = $paytype === 'onetime' ? ['onetime' => ['One-Time', null, null, 'msetupfee']] : self::CYCLES;
        $kept = [];
        foreach ($cycles as $column => [$name, $period, $unit, $setupColumn]) {
            $priced = [];
            foreach ($pricing as $row) {
                $amount = $this->amount($row[$column === 'onetime' ? 'monthly' : $column] ?? null);
                $code = $this->currencies[(int) $row['currency']] ?? null;
                if ($code !== null && $amount !== null) {
                    $priced[$code] = [$amount, $this->amount($row[$setupColumn] ?? null) ?? '0.00'];
                }
            }
            if ($priced === []) {
                continue;
            }
            // By its cycle, not its name: staff may rename it (review F4).
            $plan = $this->plan($priceable, $column === 'onetime' ? 'one-time' : 'recurring', $period, $unit);
            $plan->forceFill(['name' => $plan->exists ? $plan->name : $name])->save();
            $kept[] = $plan->id;
            $this->counts['plans']++;
            foreach ($priced as $code => [$amount, $setup]) {
                Price::query()->updateOrCreate(['plan_id' => $plan->id, 'currency_code' => $code], ['price' => $amount, 'setup_fee' => $setup]);
                $this->counts['prices']++;
            }
            $withdrawn = Price::query()->where('plan_id', $plan->id)->whereNotIn('currency_code', array_keys($priced));
            $this->counts['withdrawn'] += $withdrawn->count();
            $withdrawn->delete();
        }
        $this->withdraw($priceable, $kept);
    }

    /**
     * A plan of the product for a WHMCS billing cycle it does not offer, without prices: so none is sold, as a cycle WHMCS no
     * longer offers (withdraw()), while a service WHMCS bills on it renews here at its own price, as WHMCS bills its amount
     * (myip rehearsal 2026-11-04: 13 live services had no plan, «not billed (set its plan)»). Null for a cycle with no
     * period (One Time, Free Account) or an unknown one.
     */
    public static function unpricedPlan(Product $product, string $whmcsCycle): ?int
    {
        [$name, $period, $unit] = self::CYCLES[strtolower(str_replace('-', '', trim($whmcsCycle)))] ?? [null, null, null];
        if ($name === null) {
            return null;
        }
        $plan = Plan::query()->where(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'type' => 'recurring'])
            ->where('billing_period', $period)->where('billing_unit', $unit)->orderBy('id')->first();
        $plan ??= Plan::query()->forceCreate(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'type' => 'recurring',
            'name' => $name, 'billing_period' => $period, 'billing_unit' => $unit, 'sort' => 99]);

        return (int) $plan->id;
    }

    /** The plan of this type and cycle, or a new one (not saved). */
    private function plan(Product|ConfigOption $priceable, string $type, ?int $period, ?string $unit): Plan
    {
        $plan = Plan::query()->where(['priceable_type' => $priceable->getMorphClass(), 'priceable_id' => $priceable->id, 'type' => $type])
            ->where('billing_period', $period)->where('billing_unit', $unit)->orderBy('id')->first() ?? new Plan;
        $plan->forceFill(['priceable_type' => $priceable->getMorphClass(), 'priceable_id' => $priceable->id, 'type' => $type,
            'billing_period' => $period, 'billing_unit' => $unit]);

        return $plan;
    }

    /**
     * The plans WHMCS no longer offers for this product: deleted, or, in use (its services' history), left without
     * prices so none is sold.
     *
     * @param  list<int>  $kept
     */
    private function withdraw(Product|ConfigOption $priceable, array $kept): void
    {
        $stale = Plan::query()->where(['priceable_type' => $priceable->getMorphClass(), 'priceable_id' => $priceable->id])->whereNotIn('id', $kept)->get();
        foreach ($stale as $plan) {
            Price::query()->where('plan_id', $plan->id)->delete();
            if (!$plan->inUse()) {
                $plan->delete();
            }
            $this->counts['withdrawn']++;
        }
    }

    // ---- TLDs ------------------------------------------------------------------------------------------------------

    /** @param  array<string, DomainRegistrarConnection>  $connections  WHMCS's registrar module => the connection here */
    private function tlds(array $connections): void
    {
        foreach (collect($connections)->unique(fn (DomainRegistrarConnection $connection) => $connection->id) as $connection) { // ispapi and cnic: one
            if (!$connection->is_active) {
                $this->notes[] = "Registrar connection #{$connection->id} «{$connection->label}» is not active: its TLDs register nothing until it is.";
            }
        }
        $default = $this->whmcsDefault ?? (string) config('settings.default_currency', 'USD');
        foreach ($this->rows('SELECT * FROM tbldomainpricing ORDER BY `order`, id') as $row) {
            try {
                $name = Domain::normalise(ltrim((string) $row['extension'], '. '));
            } catch (Throwable) {
                $this->notes[] = "TLD «{$row['extension']}»: not a TLD, skipped.";

                continue;
            }
            $tld = DomainTld::query()->where('whmcs_id', (int) $row['id'])->first();
            if ($tld === null && DomainTld::query()->where('tld', $name)->exists()) {
                // Set up here before: its settings and prices stay (review F5).
                $this->notes[] = ".{$name}: already here, left as it is.";

                continue;
            }
            $tld ??= (new DomainTld)->forceFill(['is_active' => true]);
            $autoreg = strtolower((string) ($row['autoreg'] ?? ''));
            $tld->forceFill([
                'whmcs_id' => (int) $row['id'],
                'tld' => $name,
                'epp_code_required' => (bool) ($row['eppcode'] ?? true),
                'dns_management' => (bool) ($row['dnsmanagement'] ?? false),
                'email_forwarding' => (bool) ($row['emailforwarding'] ?? false),
                // WHMCS's on a new TLD only: on a re-run its registrar's word stays (Openprovider's is_private_whois_allowed,
                // WG-32, ADV-670-2).
                'id_protection' => $tld->exists ? $tld->id_protection : (bool) ($row['idprotection'] ?? false),
                // WHMCS -1: the registrar's / not offered.
                'grace_days' => (int) ($row['grace_period'] ?? -1) >= 0 ? (int) $row['grace_period'] : null,
                'redemption_days' => (int) ($row['redemption_grace_period'] ?? -1) >= 0 ? (int) $row['redemption_grace_period'] : null,
                'sort' => (int) ($row['order'] ?? 0),
            ]);
            if ($autoreg !== '' && isset($connections[$autoreg])) {
                $tld->registrar_connection_id = $connections[$autoreg]->id;
            } elseif ($autoreg !== '') {
                $this->notes[] = ".{$name}: automatic registration at «{$autoreg}» in WHMCS: no matching connection given, left by hand.";
            }
            $tld->save();
            $this->counts['tlds']++;

            /** @var array<string, string> $offered "operation:years:currency" => price */
            $offered = [];
            foreach (self::DOMAIN_OPERATIONS as $type => $operation) {
                // The default price only: a client group's has its id in tsetupfee (WHMCS 7.10+, review 2).
                foreach ($this->rows('SELECT * FROM tblpricing WHERE type = ? AND relid = ? AND tsetupfee = 0', [$type, $row['id']]) as $pricing) {
                    $code = $this->currencies[(int) $pricing['currency']] ?? null;
                    if ($code === null) {
                        continue;
                    }
                    foreach (self::DOMAIN_YEARS as $years => $column) {
                        $amount = $this->amount($pricing[$column] ?? null);
                        if ($amount === null || ($operation === 'transfer' && $years > 1)) {
                            continue; // not offered in WHMCS; a transfer is one year here (D-5)
                        }
                        $offered["{$operation}:{$years}:{$code}"] = $amount;
                    }
                }
            }
            // The grace and redemption fees, in WHMCS's default currency (it keeps them in that one only): 0.00 is
            // free and offered (D-6, review 8), -1 not offered.
            foreach (['grace' => 'grace_period_fee', 'redemption' => 'redemption_grace_period_fee'] as $operation => $column) {
                $fee = $this->amount($row[$column] ?? null);
                if ($fee !== null && DB::table('currencies')->where('code', $default)->exists()) {
                    $offered["{$operation}:1:{$default}"] = $fee;
                }
            }
            foreach ($offered as $key => $amount) {
                [$operation, $years, $code] = explode(':', $key);
                DomainTldPrice::query()->updateOrCreate(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => (int) $years, 'currency_code' => $code],
                    ['price' => $amount]);
                $this->counts['tld_prices']++;
            }
            // What WHMCS no longer offers is not sold (null: not offered, D-6); its cost from the registrar stays (review F11).
            foreach (DomainTldPrice::query()->where('domain_tld_id', $tld->id)->whereNotNull('price')->get() as $price) {
                if (!isset($offered["{$price->operation}:{$price->years}:{$price->currency_code}"])) {
                    $price->forceFill(['price' => null])->save();
                    $this->counts['withdrawn']++;
                }
            }
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /** A WHMCS amount as a 2-decimal string, without float (review 14); null when not offered (negative, -1). */
    private function amount(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (string) $value;

        return bccomp($value, '0', 2) < 0 ? null : bcadd($value, '0', 2);
    }

    /**
     * The record this WHMCS id brought, or a new one carrying it (the key is set explicitly: it is not fillable). With
     * keepWhmcsIds, a new product group or product takes the WHMCS id as its own; one taken here already is an error, never
     * another id (its services would land on another product).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function byWhmcs(string $class, string $key, int|string $value): Model
    {
        $model = $class::query()->where($key, $value)->first();
        if ($model === null && $this->keepWhmcsIds && in_array($class, [Category::class, Product::class], true)) {
            if ($class::query()->whereKey($value)->exists()) {
                throw new RuntimeException(class_basename($class) . " #{$value} is here already, not from WHMCS: the full import keeps WHMCS's ids.");
            }
            $model = (new $class)->forceFill(['id' => $value]);
        }
        $model ??= new $class;
        $model->forceFill([$key => $value]);

        return $model;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        $stmt = $this->whmcs->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0: ?string, 1: string} «env|Name» → [env, Name] */
    private function envName(string $name): array
    {
        return str_contains($name, '|') ? [explode('|', $name, 2)[0], explode('|', $name, 2)[1]] : [null, $name];
    }

    /** A slug no other row uses (WHMCS groups and the ones here may share one). */
    private function uniqueSlug(string $table, string $slug, ?int $ignore): string
    {
        $candidate = $slug;
        for ($i = 2; DB::table($table)->where('slug', $candidate)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists(); $i++) {
            $candidate = $slug . '-' . $i;
        }

        return $candidate;
    }
}

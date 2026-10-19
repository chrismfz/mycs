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
 * (upgrades, config options) stay. Server passwords and API tokens are not read: staff enter them.
 * All in one transaction; a dry run rolls it back, reports the same counts and leaves the cache alone.
 */
final class CatalogImport
{
    /** WHMCS's server module → MyCS's server extension; the others are reported and skipped. */
    private const SERVER_MODULES = ['cpanel' => 'CPanel'];

    /** A module's default port when WHMCS has none (its admin API over TLS). */
    private const SERVER_PORTS = ['cpanel' => 2087];

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
     */
    public function __construct(
        private readonly PDO $whmcs,
        private readonly bool $makeDefaultCurrency = true,
        private readonly ?int $registrarConnectionId = null,
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
            $this->tlds($connection);
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
        foreach ($this->rows('SELECT * FROM tblservers ORDER BY id') as $row) {
            $module = strtolower((string) $row['type']);
            $extension = self::SERVER_MODULES[$module] ?? null;
            if ($extension === null) {
                $this->notes[] = "Server #{$row['id']} «{$row['name']}»: module «{$row['type']}» has no MyCS extension, skipped.";

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
            $server ??= $this->sameServer($extension, $hostname);
            $server ??= new Server;
            $server->forceFill([
                'whmcs_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'extension' => $extension,
                'type' => 'server',
                'enabled' => !(int) ($row['disabled'] ?? 0),
                'max_accounts' => (int) ($row['maxaccounts'] ?? 0) > 0 ? (int) $row['maxaccounts'] : null,
            ])->save();
            // Its address (a URL, as the extension takes it: review 3) and login, only where none is set yet: a URL or
            // login staff corrected stays, and a stored API key is never sent to a host they did not type (R6). Never
            // its password or API token (staff enter them, encrypted).
            $port = (int) ($row['port'] ?? 0) ?: (self::SERVER_PORTS[$module] ?? 443);
            $settings = ['host' => $hostname !== '' ? "https://{$hostname}:{$port}" : '', 'username' => (string) ($row['username'] ?? '')];
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
        foreach ($this->rows('SELECT * FROM tblproductconfigoptions ORDER BY id') as $row) {
            [$env, $name] = $this->envName((string) $row['optionname']);
            $option = $this->byWhmcs(ConfigOption::class, 'whmcs_ref', 'option:' . $row['id']);
            $option->forceFill([
                'name' => $name, 'env_variable' => $env, 'sort' => (int) ($row['order'] ?? 0), 'hidden' => (bool) ($row['hidden'] ?? false),
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
    }

    private function products(): void
    {
        $licensing = null;
        $rows = $this->rows('SELECT * FROM tblproducts ORDER BY id');
        foreach ($rows as $row) {
            $categoryId = $this->categoryIds[(int) $row['gid']] ?? null;
            if ($categoryId === null) {
                $this->notes[] = "Product #{$row['id']} «{$row['name']}»: its group #{$row['gid']} is missing, skipped.";

                continue;
            }
            $product = $this->byWhmcs(Product::class, 'whmcs_id', (int) $row['id']);
            $slug = (string) ($row['slug'] ?? '') ?: Str::slug((string) $row['name']);
            $retired = (bool) ($row['retired'] ?? false);
            $module = strtolower((string) ($row['servertype'] ?? ''));
            $group = $this->groupIds[(int) ($row['servergroup'] ?? 0)] ?? null;
            $product->forceFill([
                'category_id' => $categoryId,
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'slug' => $this->uniqueSlug('products', $slug ?: 'product-' . $row['id'], $product->id),
                // Retired in WHMCS: not ordered any more, its own flag (ADV-1 of #216: a stock of 0 came back with the
                // first cancellation); a hidden group's products are not listed (reviews 5, F1).
                'hidden' => (bool) ($row['hidden'] ?? false) || $retired || ($this->hiddenGroups[(int) $row['gid']] ?? false),
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
            if ($module === 'licensing') {
                $licensing ??= Server::query()->firstOrCreate(['extension' => 'NixpalLicensing'], ['name' => 'nixpal Licensing', 'type' => 'server', 'enabled' => true]);
                (new WhmcsLicensingImport($this->whmcs))->productSettings($product, $row, $licensing);
                $this->counts['licensing_products']++;
            } elseif ($module !== '' && $module !== 'cpanel') {
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

    private function tlds(?DomainRegistrarConnection $connection): void
    {
        if ($connection !== null && !$connection->is_active) {
            $this->notes[] = "Registrar connection #{$connection->id} «{$connection->label}» is not active: its TLDs register nothing until it is.";
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
                'id_protection' => (bool) ($row['idprotection'] ?? false),
                // WHMCS -1: the registrar's / not offered.
                'grace_days' => (int) ($row['grace_period'] ?? -1) >= 0 ? (int) $row['grace_period'] : null,
                'redemption_days' => (int) ($row['redemption_grace_period'] ?? -1) >= 0 ? (int) $row['redemption_grace_period'] : null,
                'sort' => (int) ($row['order'] ?? 0),
            ]);
            if ($autoreg !== '' && $connection !== null && $connection->registrar === $autoreg) {
                $tld->registrar_connection_id = $connection->id;
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
     * The record this WHMCS id brought, or a new one carrying it (the key is set explicitly: it is not fillable).
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function byWhmcs(string $class, string $key, int|string $value): Model
    {
        $model = $class::query()->where($key, $value)->first() ?? new $class;
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

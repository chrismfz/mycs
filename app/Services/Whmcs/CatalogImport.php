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
use App\Services\Licensing\WhmcsLicensingImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * A WHMCS installation's catalog, copied (`app:import-whmcs-catalog`): currencies, product groups, products with their
 * plans and prices, config options, servers and server groups, the licensing products' settings, the TLDs and their
 * prices. No account, service, invoice, order or ticket. Read-only on WHMCS (a copy of it: docs/importer.md).
 *
 * Re-runnable and never destructive: each record is matched by its WHMCS id (`whmcs_id`, `whmcs_ref` on config
 * options; currencies by code; plans by product and cycle; prices by plan and currency), updated if it is here and
 * added if not; nothing here is deleted or renumbered. Server passwords and API tokens are not read: staff enter them.
 * All in one transaction; a dry run rolls it back and reports the same counts.
 */
final class CatalogImport
{
    /** WHMCS's server module → MyCS's server extension; the others are reported and skipped. */
    private const SERVER_MODULES = ['cpanel' => 'CPanel'];

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
        'products' => 0, 'plans' => 0, 'prices' => 0, 'licensing_products' => 0, 'tlds' => 0, 'tld_prices' => 0];

    /** @var list<string> what was skipped and why */
    private array $notes = [];

    /** @var array<int, string> WHMCS currency id => code */
    private array $currencies = [];

    /** WHMCS's default currency: its grace and redemption fees are in it. */
    private ?string $whmcsDefault = null;

    /** @var array<int, int> */
    private array $categoryIds = [];

    /** @var array<int, int> */
    private array $serverIds = [];

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
        DB::beginTransaction();
        try {
            $this->currencies();
            $this->categories();
            $this->servers();
            $this->serverGroups();
            $this->configOptions();
            $this->products();
            $this->tlds();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        if (!$dryRun) {
            Cache::forget('settings.rows');
            Cache::forget('settings');
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    // ---- currencies, groups, servers -------------------------------------------------------------------------------

    private function currencies(): void
    {
        foreach ($this->rows('SELECT * FROM tblcurrencies ORDER BY id') as $row) {
            $code = strtoupper((string) $row['code']);
            $this->currencies[(int) $row['id']] = $code;
            $currency = Currency::query()->find($code) ?? new Currency(['code' => $code, 'name' => $code]);
            $currency->fill([
                'prefix' => (string) ($row['prefix'] ?? '') ?: null,
                'suffix' => (string) ($row['suffix'] ?? '') ?: null,
                'format' => match ((int) ($row['format'] ?? 3)) {
                    1 => '1 000.00',
                    2 => '1,000.00',
                    4 => '1 000,00',
                    default => '1.000,00',
                },
            ])->save();
            $this->counts['currencies']++;
            if ((int) ($row['default'] ?? 0) === 1) {
                $this->whmcsDefault = $code;
            }
            if ((int) ($row['default'] ?? 0) === 1 && $this->makeDefaultCurrency) {
                Setting::query()->updateOrCreate(['key' => 'default_currency', 'settingable_type' => null, 'settingable_id' => null], ['value' => $code, 'type' => 'string']);
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
                'sort' => (int) ($row['order'] ?? 0),
                'slug' => $this->uniqueSlug('categories', $slug ?: 'group-' . $row['id'], $category->id),
            ])->save();
            $this->categoryIds[(int) $row['id']] = $category->id;
            $this->counts['categories']++;
        }
    }

    private function servers(): void
    {
        foreach ($this->rows('SELECT id, name, type, hostname, ipaddress, username, maxaccounts, disabled FROM tblservers ORDER BY id') as $row) {
            $extension = self::SERVER_MODULES[strtolower((string) $row['type'])] ?? null;
            if ($extension === null) {
                $this->notes[] = "Server #{$row['id']} «{$row['name']}»: module «{$row['type']}» has no MyCS extension, skipped.";

                continue;
            }
            // As Server, the way the admin keeps a server and its settings.
            $server = $this->byWhmcs(Server::class, 'whmcs_id', (int) $row['id']);
            $server->forceFill([
                'name' => (string) $row['name'],
                'extension' => $extension,
                'type' => 'server',
                'enabled' => !(int) ($row['disabled'] ?? 0),
                'max_accounts' => (int) ($row['maxaccounts'] ?? 0) > 0 ? (int) $row['maxaccounts'] : null,
            ])->save();
            // Its address and login; never its password or API token (staff enter them, encrypted).
            foreach (['host' => (string) ($row['hostname'] ?: $row['ipaddress']), 'username' => (string) ($row['username'] ?? '')] as $key => $value) {
                if ($value !== '') {
                    Setting::query()->updateOrCreate(['settingable_type' => $server->getMorphClass(), 'settingable_id' => $server->id, 'key' => $key],
                        ['value' => $value, 'type' => 'string', 'encrypted' => false]);
                }
            }
            $this->serverIds[(int) $row['id']] = $server->id;
            $this->counts['servers']++;
        }
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
            $product->forceFill([
                'category_id' => $categoryId,
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'slug' => $this->uniqueSlug('products', $slug ?: 'product-' . $row['id'], $product->id),
                'hidden' => (bool) ($row['hidden'] ?? false),
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
                'server_group_id' => $this->groupIds[(int) ($row['servergroup'] ?? 0)] ?? null,
            ]);
            $product->save();
            $this->productIds[(int) $row['id']] = $product->id;
            $this->counts['products']++;

            if (($row['paytype'] ?? '') === 'free') {
                $free = Plan::query()->firstOrNew(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'type' => 'free']);
                $free->forceFill(['priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'type' => 'free', 'name' => 'Free'])->save();
                $this->counts['plans']++;
            } else {
                $this->prices($product, $this->rows("SELECT * FROM tblpricing WHERE type = 'product' AND relid = ?", [$row['id']]), (string) ($row['paytype'] ?? 'recurring'));
            }

            $module = strtolower((string) ($row['servertype'] ?? ''));
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

        // Once every product is here: its upgrades and its config options.
        foreach ($rows as $row) {
            $product = isset($this->productIds[(int) $row['id']]) ? Product::query()->find($this->productIds[(int) $row['id']]) : null;
            if ($product === null) {
                continue;
            }
            $upgrades = array_filter(array_map(fn (array $u) => $this->productIds[(int) $u['upgrade_product_id']] ?? null,
                $this->rows('SELECT upgrade_product_id FROM tblproduct_upgrade_products WHERE product_id = ?', [$row['id']])));
            $product->upgrades()->sync(array_values($upgrades));
            $options = [];
            foreach ($this->rows('SELECT o.id FROM tblproductconfiglinks l JOIN tblproductconfigoptions o ON o.gid = l.gid WHERE l.pid = ?', [$row['id']]) as $o) {
                if (isset($this->optionIds[(int) $o['id']])) {
                    $options[] = $this->optionIds[(int) $o['id']];
                }
            }
            DB::table('config_option_products')->where('product_id', $product->id)->delete();
            foreach (array_unique($options) as $option) {
                DB::table('config_option_products')->insert(['product_id' => $product->id, 'config_option_id' => $option]);
            }
        }
    }

    /**
     * A product's (or a config option value's) prices: a plan per cycle priced in any currency (WHMCS -1 = not
     * offered; 0.00 is offered, free), its price per currency.
     *
     * @param  list<array<string, mixed>>  $pricing  tblpricing rows, one per currency
     */
    private function prices(Product|ConfigOption $priceable, array $pricing, string $paytype): void
    {
        $cycles = $paytype === 'onetime' ? ['onetime' => ['One-Time', 0, null, 'msetupfee']] : self::CYCLES;
        foreach ($cycles as $column => [$name, $period, $unit, $setupColumn]) {
            $priced = [];
            foreach ($pricing as $row) {
                $amount = $row[$column === 'onetime' ? 'monthly' : $column] ?? '-1';
                $code = $this->currencies[(int) $row['currency']] ?? null;
                if ($code !== null && is_numeric($amount) && (float) $amount >= 0) {
                    $setup = is_numeric($row[$setupColumn] ?? null) && (float) $row[$setupColumn] > 0 ? (string) $row[$setupColumn] : '0';
                    $priced[$code] = [(string) $amount, $setup];
                }
            }
            if ($priced === []) {
                continue;
            }
            $plan = Plan::query()->firstOrNew(['priceable_type' => $priceable->getMorphClass(), 'priceable_id' => $priceable->id, 'name' => $name]);
            $plan->forceFill(['priceable_type' => $priceable->getMorphClass(), 'priceable_id' => $priceable->id, 'name' => $name,
                'type' => $column === 'onetime' ? 'one-time' : 'recurring', 'billing_period' => $period, 'billing_unit' => $unit])->save();
            $this->counts['plans']++;
            foreach ($priced as $code => [$amount, $setup]) {
                Price::query()->updateOrCreate(['plan_id' => $plan->id, 'currency_code' => $code], ['price' => $amount, 'setup_fee' => $setup]);
                $this->counts['prices']++;
            }
        }
    }

    // ---- TLDs ------------------------------------------------------------------------------------------------------

    private function tlds(): void
    {
        $connection = $this->registrarConnectionId !== null ? DomainRegistrarConnection::query()->find($this->registrarConnectionId) : null;
        $default = $this->whmcsDefault ?? (string) config('settings.default_currency', 'USD');
        foreach ($this->rows('SELECT * FROM tbldomainpricing ORDER BY `order`, id') as $row) {
            try {
                $name = Domain::normalise(ltrim((string) $row['extension'], '. '));
            } catch (Throwable) {
                $this->notes[] = "TLD «{$row['extension']}»: not a TLD, skipped.";

                continue;
            }
            $tld = DomainTld::query()->firstOrNew(['tld' => $name]);
            $autoreg = strtolower((string) ($row['autoreg'] ?? ''));
            $tld->fill([
                'epp_code_required' => (bool) ($row['eppcode'] ?? true),
                'dns_management' => (bool) ($row['dnsmanagement'] ?? false),
                'email_forwarding' => (bool) ($row['emailforwarding'] ?? false),
                'id_protection' => (bool) ($row['idprotection'] ?? false),
                // WHMCS -1: the registrar's / not offered.
                'grace_days' => (int) ($row['grace_period'] ?? -1) >= 0 ? (int) $row['grace_period'] : null,
                'redemption_days' => (int) ($row['redemption_grace_period'] ?? -1) >= 0 ? (int) $row['redemption_grace_period'] : null,
                'sort' => (int) ($row['order'] ?? 0),
            ]);
            if (!$tld->exists) {
                $tld->is_active = true;
            }
            if ($autoreg !== '' && $connection !== null && $connection->registrar === $autoreg) {
                $tld->registrar_connection_id = $connection->id;
            } elseif ($autoreg !== '' && ($connection === null || $connection->registrar !== $autoreg)) {
                $this->notes[] = ".{$name}: automatic registration at «{$autoreg}» in WHMCS: no matching connection given, left by hand.";
            }
            $tld->save();
            $this->counts['tlds']++;

            foreach (self::DOMAIN_OPERATIONS as $type => $operation) {
                foreach ($this->rows('SELECT * FROM tblpricing WHERE type = ? AND relid = ?', [$type, $row['id']]) as $pricing) {
                    $code = $this->currencies[(int) $pricing['currency']] ?? null;
                    if ($code === null) {
                        continue;
                    }
                    foreach (self::DOMAIN_YEARS as $years => $column) {
                        $amount = $pricing[$column] ?? '-1';
                        if (!is_numeric($amount) || (float) $amount < 0) {
                            continue; // not offered in WHMCS
                        }
                        if ($operation === 'transfer' && $years > 1) {
                            continue; // a transfer is one year here (D-5)
                        }
                        DomainTldPrice::query()->updateOrCreate(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => $code],
                            ['price' => number_format((float) $amount, 2, '.', '')]);
                        $this->counts['tld_prices']++;
                    }
                }
            }
            // The grace and redemption fees, in WHMCS's default currency (it keeps them in that one only).
            foreach (['grace' => 'grace_period_fee', 'redemption' => 'redemption_grace_period_fee'] as $operation => $column) {
                $fee = $row[$column] ?? null;
                if (is_numeric($fee) && (float) $fee > 0 && Currency::query()->whereKey($default)->exists()) {
                    DomainTldPrice::query()->updateOrCreate(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => 1, 'currency_code' => $default],
                        ['price' => number_format((float) $fee, 2, '.', '')]);
                    $this->counts['tld_prices']++;
                }
            }
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

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

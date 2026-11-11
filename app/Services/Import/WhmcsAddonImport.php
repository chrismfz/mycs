<?php

namespace App\Services\Import;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Whmcs\CatalogImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * WHMCS's product addons (docs/addons.md, step 4): each live one (`tblhostingaddons`, active, suspended or pending) a service
 * fastened to its parent here, with WHMCS's price, cycle, due date, status and notes; ended ones stay out (their lines stay
 * the parent's charges). Nothing is done on a server: the IP or the space is there already.
 *
 * - Products: each catalog addon (`tbladdons`) an addon product with its prices and the products it is for. One made by
 *   hand (`addonid` 0, or an addon WHMCS deleted) goes by its name: a catalog addon of that name, «Dedicated IP …» to the
 *   catalog's Dedicated IP, «+10gb …» / «20GB Extra Space» to «Extra disk space», anything else to «Addon (WHMCS)», each
 *   with its own price.
 * - Tax: WHMCS taxes each addon by its own flag (`tblhostingaddons.tax`), this app by its product's. One billed otherwise
 *   than its product goes on a product of the same name that is (retired: never added again), so no invoice changes at
 *   the cut-over (myip: 10 Dedicated IPs billed without VAT; owner to decide).
 * - Ids: `tblhostingaddons`'s collide with `tblhosting`'s, so an addon's service takes a new one and keeps WHMCS's in
 *   `whmcs_addon_id`; an addon product keeps its `tbladdons` id there too.
 * - Status: a suspended one is its parent's (back with it) when its parent is suspended too, WHMCS's dunning when its
 *   renewal is unpaid past due, staff's otherwise. A live one under a suspended parent is suspended with it (never active
 *   under a suspended parent here, AddonCascade).
 *
 * Rows are written directly (an import: no observer cascades). Run after the services and orders, before the invoice lines.
 */
final class WhmcsAddonImport
{
    public const LIVE = ['Active', 'Suspended', 'Pending'];

    /** A staff's suspension WHMCS kept no reason for. */
    public const SUSPENDED_IN_WHMCS = 'Suspended in WHMCS';

    /** @var array<string, int> */
    private array $counts = ['products' => 0, 'services' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<int, int> tbladdons id => its product here */
    private array $catalog = [];

    /** @var array<int, array<string, mixed>> tbladdons id => its row */
    private array $addons = [];

    /** @var array<int, string> tbladdons id => name: WHMCS's «Suspend Parent Product», unpaid it suspends its parent */
    private array $suspendsParent = [];

    /** @var array<string, int> a product made here (by hand's group, or a tax variant) => its id */
    private array $made = [];

    /** @var array<int, string> tblcurrencies id => code */
    private array $currencies = [];

    private ?int $category = null;

    public function __construct(private readonly PDO $whmcs, private readonly int $suspendDays = 0) {}

    /** @return array{counts: array<string, int>, notes: list<string>, services: array<int, int>} services: WHMCS addon id => its service */
    public function run(): array
    {
        $rows = $this->rows('SELECT * FROM tblhostingaddons ORDER BY id');
        if ($rows === null) {
            return ['counts' => $this->counts, 'notes' => $this->notes, 'services' => []];
        }
        foreach ($this->rows('SELECT id, code FROM tblcurrencies') ?? [] as $currency) {
            $this->currencies[(int) $currency['id']] = (string) $currency['code'];
        }
        $this->products();

        $parents = DB::table('services')->whereNull('parent_service_id')->whereIn('id', array_map(fn ($row) => (int) $row['hostingid'], $rows))
            ->get(['id', 'client_id', 'currency_code', 'status', 'payment_gateway_id'])->keyBy('id');
        $groupPercents = DB::table('clients')->join('client_groups', 'client_groups.id', '=', 'clients.client_group_id')
            ->pluck('client_groups.discount_percent', 'clients.id')->map(fn ($percent) => number_format((float) $percent, 2, '.', ''))->all();
        $orders = DB::table('orders')->whereIn('id', array_map(fn ($row) => (int) $row['orderid'], $rows))->pluck('id')->flip()->all();
        $overdue = $this->overdue();
        // Above every id WHMCS gave a service, even one it deleted since: its lines, cancellation requests and fields still
        // name it, and an addon in its place would take them (code review 2 of #647).
        $next = $this->firstFreeServiceId();
        $services = [];
        $underSuspended = [];
        $parentEnded = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            if (!in_array($status, self::LIVE, true)) {
                continue;
            }
            $parent = $parents->get((int) $row['hostingid']);
            if ($parent === null) {
                $this->skip("WHMCS addon #{$id} ({$status}): its service #{$row['hostingid']} is not here (deleted in WHMCS): not imported, nothing to renew it on");

                continue;
            }
            if ((int) $row['userid'] !== 0 && (int) $row['userid'] !== (int) $parent->client_id) {
                $this->notes[] = "WHMCS addon #{$id}: of account #{$row['userid']} in WHMCS, its service #{$parent->id} of #{$parent->client_id}: imported on the service's account.";
            }
            $product = $this->productFor($row);
            $cycle = trim((string) $row['billingcycle']);
            $planId = $this->planFor($product, $cycle);
            if ($planId === null) {
                $this->skip("WHMCS addon #{$id}: its cycle «{$cycle}» is not known here: not imported");

                continue;
            }
            $recurring = DB::table('plans')->where('id', $planId)->value('type') === 'recurring';
            $amount = number_format(max(0, (float) $row['recurring']), 2, '.', '');
            if ((float) $row['recurring'] < 0) {
                $this->notes[] = "WHMCS addon #{$id}: its recurring amount {$row['recurring']} is below zero; imported as 0.00, set its price.";
            }
            $percent = $groupPercents[(int) $parent->client_id] ?? '0.00';
            $priced = DB::table('prices')->where('plan_id', $planId)->where('currency_code', $parent->currency_code)->exists();

            $wanted = ImportFromWhmcs::serviceStatus($status);
            $suspendReason = null;
            $dunning = null;
            if ($parent->status === Service::STATUS_SUSPENDED && $wanted === Service::STATUS_ACTIVE) {
                $wanted = Service::STATUS_SUSPENDED;
                $underSuspended[] = $id;
            }
            if ($wanted === Service::STATUS_SUSPENDED) {
                if ($parent->status === Service::STATUS_SUSPENDED) {
                    $suspendReason = Service::SUSPEND_REASON_PARENT;
                } elseif (isset($overdue[$id])) {
                    $dunning = $this->suspendedOn(self::date($row['nextduedate']));
                } else {
                    $suspendReason = self::SUSPENDED_IN_WHMCS;
                }
            }
            // Never live under an ended parent here (AddonCascade ends addons with it): ended with it, its parent's status,
            // so nothing renews it (code review 3 of #647).
            if (in_array($parent->status, [Service::STATUS_TERMINATED, Service::STATUS_CANCELLED], true)) {
                $parentEnded[] = [$id, $status, (int) $parent->id, (string) $parent->status];
                [$wanted, $suspendReason, $dunning] = [(string) $parent->status, null, null];
            }
            $name = trim(WhmcsNoteImport::text($row['name'] ?? null) ?? '');
            $productName = (string) DB::table('products')->where('id', $product)->value('name');

            DB::table('services')->insert([
                'id' => $services[$id] = $next++,
                'parent_service_id' => (int) $parent->id,
                'whmcs_addon_id' => $id,
                'client_id' => (int) $parent->client_id,
                'currency_code' => (string) $parent->currency_code,
                'order_id' => isset($orders[(int) $row['orderid']]) ? (int) $row['orderid'] : null,
                'product_id' => $product,
                'plan_id' => $planId,
                'status' => $wanted,
                'quantity' => max(1, (int) ($row['qty'] ?? 1)),
                // As a service's (ImportFromWhmcs::importServices, WG-1): WHMCS's amount less its account's group discount.
                'price' => ClientGroupPricing::discounted($amount, $percent),
                'group_percent' => $percent,
                'undiscounted_price' => $amount,
                // No price of its own in the catalog (one made by hand): kept, never «recalculated» to 0.00.
                'price_locked' => $recurring && !$priced,
                'expires_at' => $recurring ? self::date($row['nextduedate']) : null,
                'activated_at' => $status === 'Pending' ? null : (self::date($row['regdate']) ?? now()),
                'suspend_reason' => $suspendReason,
                'dunning_suspended_at' => $dunning,
                // WHMCS's own name when it says more than its product's («Dedicated IP example.gr», an address).
                'label' => $name !== '' && strcasecmp($name, $productName) !== 0 ? mb_substr($name, 0, 255) : null,
                'notes' => WhmcsNoteImport::text($row['notes'] ?? null),
                'no_auto_charge' => false,
                'created_at' => self::date($row['regdate']) ?? now(),
                'updated_at' => self::date($row['regdate']) ?? now(),
            ]);
            $this->counts['services']++;
        }
        if ($underSuspended !== []) {
            $this->notes[] = count($underSuspended) . ' WHMCS addon(s) active under a suspended service: suspended with it here (back with it): #' . implode(', #', $underSuspended) . '.';
        }
        // WHMCS's «Suspend Parent Product» is not this app's: an unpaid addon suspends only itself here (ADV-647-6).
        foreach ($this->suspendsParent as $addonId => $addonName) {
            $live = array_keys(array_filter($rows, fn ($row) => (int) $row['addonid'] === $addonId && isset($services[(int) $row['id']])));
            if ($live !== []) {
                $this->notes[] = "WHMCS addon product #{$addonId} «{$addonName}» suspends its parent service when unpaid («Suspend Parent Product»); here an unpaid addon"
                    . ' suspends only itself. Live: addon(s) #' . implode(', #', array_map(fn ($key) => (int) $rows[$key]['id'], $live)) . '.';
            }
        }
        foreach ($parentEnded as [$id, $status, $parentId, $parentStatus]) {
            $this->notes[] = "WHMCS addon #{$id} is {$status} while its service #{$parentId} is {$parentStatus}: imported {$parentStatus} with it, never renewed; check it.";
        }

        return ['counts' => $this->counts, 'notes' => $this->notes, 'services' => $services];
    }

    /** Each catalog addon an addon product, with its prices and targets. */
    private function products(): void
    {
        foreach ($this->rows('SELECT * FROM tbladdons ORDER BY id') ?? [] as $row) {
            $id = (int) $row['id'];
            $this->addons[$id] = $row;
            $targets = array_values(array_filter(array_map('intval', explode(',', (string) ($row['packages'] ?? '')))));
            $here = $targets === [] ? [] : DB::table('products')->whereIn('id', $targets)->where('is_addon', false)->orderBy('id')->pluck('id')->all();
            if ($targets !== [] && $here === []) {
                $this->notes[] = "WHMCS addon product #{$id}: none of the products it is for is here: for any service.";
            }
            $product = $this->insertProduct([
                'name' => (string) $row['name'],
                'description' => WhmcsNoteImport::text($row['description'] ?? null),
                'hidden' => (bool) ($row['hidden'] ?? false),
                'retired' => (bool) ($row['retired'] ?? false),
                'taxable' => self::on($row['tax'] ?? '1'),
                'allow_quantity' => match ((int) ($row['allowqty'] ?? 0)) {
                    1 => 'separated',
                    3 => 'combined',
                    default => 'disabled',
                },
                'autosetup' => Product::AUTOSETUP_FROM_WHMCS[(string) ($row['autoactivate'] ?? '')] ?? Product::AUTOSETUP_NEVER,
                'addon_targets' => $here === [] ? null : json_encode(array_map(fn ($product) => "product:{$product}", $here)),
                'whmcs_addon_id' => $id,
            ]);
            $this->catalog[$id] = $product;
            if (self::on($row['suspendproduct'] ?? '0')) {
                $this->suspendsParent[$id] = (string) $row['name'];
            }
            $this->prices($product, $row);
        }
    }

    /**
     * Its plans and prices (`tblpricing`, type addon): a «recurring» addon one plan per cycle offered (WHMCS: −1 is off, 0.00
     * free), a «free» one a free plan, a «onetime» one a one-time plan; an addon of WHMCS before 7 names one cycle and keeps
     * its price in `monthly`.
     *
     * @param  array<string, mixed>  $addon
     */
    private function prices(int $product, array $addon): void
    {
        $kind = self::cycleKey((string) ($addon['billingcycle'] ?? ''));
        if ($kind === 'free' || $kind === 'freeaccount') {
            $this->planFor($product, 'Free');

            return;
        }
        // [plan name, type, period, unit, its price's column, its setup fee's]
        $offers = match (true) {
            $kind === 'onetime' => [['One-Time', 'one-time', null, null, 'monthly', 'msetupfee']],
            $kind === 'recurring' => array_map(fn (string $key) => [self::CYCLES[$key][0], 'recurring', self::CYCLES[$key][1], self::CYCLES[$key][2], $key, self::CYCLES[$key][3]],
                array_keys(self::CYCLES)),
            isset(self::CYCLES[$kind]) => [[self::CYCLES[$kind][0], 'recurring', self::CYCLES[$kind][1], self::CYCLES[$kind][2], 'monthly', 'msetupfee']],
            default => [],
        };
        if ($offers === []) {
            $this->notes[] = "WHMCS addon product #{$addon['id']}: its cycle «{$addon['billingcycle']}» is not known here: no prices.";
        }
        $plans = [];
        foreach ($this->rows('SELECT * FROM tblpricing WHERE type = \'addon\' AND relid = ' . (int) $addon['id'] . ' ORDER BY id') ?? [] as $pricing) {
            $currency = $this->currencies[(int) $pricing['currency']] ?? null;
            foreach ($currency === null ? [] : $offers as [$name, $type, $period, $unit, $column, $setupColumn]) {
                if ((float) ($pricing[$column] ?? -1) < 0) {
                    continue; // off in this currency
                }
                $plans["{$type}|{$period}|{$unit}"] ??= (int) DB::table('plans')->insertGetId(['priceable_type' => Product::class, 'priceable_id' => $product,
                    'name' => $name, 'type' => $type, 'billing_period' => $period, 'billing_unit' => $unit]);
                DB::table('prices')->insert(['plan_id' => $plans["{$type}|{$period}|{$unit}"], 'currency_code' => $currency,
                    'price' => number_format((float) $pricing[$column], 2, '.', ''), 'setup_fee' => number_format(max(0, (float) ($pricing[$setupColumn] ?? 0)), 2, '.', '')]);
            }
        }
    }

    /**
     * Its product here: its catalog addon's, else by its name; on a product of the same name taxed as WHMCS billed it.
     *
     * @param  array<string, mixed>  $row
     */
    private function productFor(array $row): int
    {
        $taxable = self::on($row['tax'] ?? '');
        $catalog = $this->catalog[(int) $row['addonid']] ?? null;
        if ($catalog === null) {
            $name = (string) (WhmcsNoteImport::text($row['name'] ?? null) ?? '');
            $group = match (true) {
                preg_match('/dedicated\s*ip/i', $name) === 1 => 'Dedicated IP',
                // «+10gb …», «10Gb addon …», «… - addon +10gb», «20GB Extra Space»; never any «N GB» (RAM, backup: ADV-647-5).
                preg_match('/^\+?\d+\s*gb\b|\baddon\s*\+\d+\s*gb\b|\bextra\s+(disk\s+)?space\b/i', $name) === 1 => 'Extra disk space',
                default => null,
            };
            // A catalog addon by its name (its own, or its group's): «Dedicated IP …» is the catalog's Dedicated IP.
            foreach ($this->addons as $addonId => $addon) {
                $catalogName = trim((string) $addon['name']);
                if ($catalogName !== '' && (strcasecmp($catalogName, trim($name)) === 0 || ($group !== null && strcasecmp($catalogName, $group) === 0))) {
                    $catalog = $this->catalog[$addonId];

                    break;
                }
            }
            if ($catalog === null) {
                $group ??= 'Addon (WHMCS)';
                $key = "group|{$group}|" . ($taxable ? 1 : 0);

                // None in the catalog: made here, priced by each addon's own amount; one only for what WHMCS billed without
                // tax is retired, as a variant (below).
                return $this->made[$key] ??= $this->insertProduct(['name' => $group, 'taxable' => $taxable, 'retired' => !$taxable,
                    'description' => $taxable ? null : self::untaxedNote()]);
            }
        }
        $product = Product::query()->findOrFail($catalog);
        if ((bool) $product->taxable === $taxable) {
            return $catalog;
        }
        $key = "variant|{$catalog}|" . ($taxable ? 1 : 0);

        return $this->made[$key] ??= $this->insertProduct(['name' => (string) $product->name, 'taxable' => $taxable, 'retired' => true, 'hidden' => true,
            'description' => $taxable ? 'Imported from WHMCS: addons billed with VAT there, as its catalog addon is not.' : self::untaxedNote(),
            'allow_quantity' => (string) $product->allow_quantity, 'autosetup' => (string) $product->autosetup, 'addon_targets' => $product->addon_targets === null ? null : json_encode($product->addon_targets)]);
    }

    /** WHMCS's flag (tbladdons.tax, tblhostingaddons.tax): 1, «on» or «yes». */
    public static function on(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes'], true);
    }

    /**
     * The first service id above every one WHMCS gave: its counter (tblhosting's AUTO_INCREMENT) and the ids still named by
     * what refers to a service (a counter reset by a restart, or a copy without it), and this instance's own. Also where
     * the services' own counter is moved to after the import (ImportFromWhmcs::moveServicesCounter, sweep 4 C-IMPORT-1).
     */
    public function firstFreeServiceId(): int
    {
        $highest = (int) DB::table('services')->max('id');
        foreach (['SELECT MAX(id) FROM tblhosting', "SELECT MAX(relid) FROM tblinvoiceitems WHERE type IN ('Hosting', 'Setup', 'PromoHosting')",
            'SELECT MAX(relid) FROM tblcancelrequests', "SELECT MAX(relid) FROM tblupgrades WHERE type IN ('package', 'configoptions')",
            'SELECT MAX(relid) FROM tblhostingconfigoptions', "SELECT MAX(v.relid) FROM tblcustomfieldsvalues v JOIN tblcustomfields f ON f.id = v.fieldid WHERE f.type = 'product'",
            "SELECT AUTO_INCREMENT - 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblhosting'"] as $sql) {
            $value = $this->rows($sql)[0] ?? [];
            $highest = max($highest, (int) (reset($value) ?: 0));
        }

        return $highest + 1;
    }

    private static function untaxedNote(): string
    {
        return 'Imported from WHMCS: addons billed without VAT there, kept so at the cut-over. Retired: never added again.';
    }

    /** @param  array<string, mixed>  $fields */
    private function insertProduct(array $fields): int
    {
        $this->counts['products']++;

        return (int) DB::table('products')->insertGetId($fields + [
            'category_id' => $this->category(),
            'slug' => Str::slug((string) $fields['name']),
            'is_addon' => true,
            'hidden' => false,
            'retired' => false,
            'allow_quantity' => 'disabled',
            'autosetup' => Product::AUTOSETUP_NEVER,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A category of their own, out of the shop's menu (an addon product is never listed: Product::scopeListed). */
    private function category(): int
    {
        return $this->category ??= (int) (DB::table('categories')->where('slug', 'whmcs-addons')->value('id')
            ?? DB::table('categories')->insertGetId(['name' => 'Addons', 'slug' => 'whmcs-addons', 'full_slug' => 'whmcs-addons', 'show_in_menu' => false,
                'created_at' => now(), 'updated_at' => now()]));
    }

    /** Its plan of WHMCS's cycle: the product's own, else one of that cycle without prices (never sold, CatalogImport). */
    private function planFor(int $product, string $cycle): ?int
    {
        $key = self::cycleKey($cycle);
        [$type, $period, $unit] = match (true) {
            $key === 'free' || $key === 'freeaccount' => ['free', null, null],
            $key === 'onetime' => ['one-time', null, null],
            isset(self::CYCLES[$key]) => ['recurring', self::CYCLES[$key][1], self::CYCLES[$key][2]],
            default => [null, null, null],
        };
        if ($type === null) {
            return null;
        }
        $plan = DB::table('plans')->where(['priceable_type' => Product::class, 'priceable_id' => $product, 'type' => $type])
            ->where('billing_period', $period)->where('billing_unit', $unit)->orderBy('id')->value('id');
        if ($plan !== null) {
            return (int) $plan;
        }
        if ($type === 'recurring') {
            return CatalogImport::unpricedPlan(Product::query()->findOrFail($product), $cycle);
        }

        return (int) DB::table('plans')->insertGetId(['priceable_type' => Product::class, 'priceable_id' => $product,
            'name' => $type === 'free' ? 'Free' : 'One-Time', 'type' => $type, 'billing_period' => null, 'billing_unit' => null]);
    }

    /** WHMCS's cycle names (tblpricing's columns) => [plan name, period, unit, setup fee column]. */
    private const CYCLES = [
        'monthly' => ['Monthly', 1, 'month', 'msetupfee'],
        'quarterly' => ['Quarterly', 3, 'month', 'qsetupfee'],
        'semiannually' => ['Semiannually', 6, 'month', 'ssetupfee'],
        'annually' => ['Annually', 1, 'year', 'asetupfee'],
        'biennially' => ['Biennially', 2, 'year', 'bsetupfee'],
        'triennially' => ['Triennially', 3, 'year', 'tsetupfee'],
    ];

    /**
     * How many whole periods of its cycle a WHMCS addon due $addonDue is behind its service (due $serviceDue): 0 on the
     * service's own date, as an addon is here (docs/addons.md); 1 or more when its date is that many cycles before the
     * service's, on the same billing day (a 29th-31st clamped to a short month's last day as BillingPeriod clamps it:
     * 2027-01-31 is one month behind 2027-02-28). Null for anything else: ahead of its service, another day, a part of a
     * cycle apart (a yearly addon three months off: sweep 7 S7-LATEST-FIXES-1), a missing date (a service with none, as a
     * free one: MyCS cannot give it an addon either), or a cycle not known here. Its cycle is its service's (sameCycle,
     * checked apart).
     */
    public static function periodsBehind(string $cycle, string $addonDue, string $serviceDue): ?int
    {
        $addon = self::date($addonDue);
        $due = self::date($serviceDue);
        $known = self::CYCLES[self::cycleKey($cycle)] ?? null;
        if ($known === null || $addon === null || $due === null) {
            return null;
        }
        if ($addon === $due) {
            return 0;
        }
        $addon = CarbonImmutable::parse($addon);
        $due = CarbonImmutable::parse($due);
        $day = max($addon->day, $due->day);
        $months = ($due->year - $addon->year) * 12 + $due->month - $addon->month;
        $cycleMonths = $known[1] * ($known[2] === 'year' ? 12 : 1);
        if ($months <= 0 || $months % $cycleMonths !== 0 || min($day, $addon->daysInMonth) !== $addon->day || min($day, $due->daysInMonth) !== $due->day) {
            return null;
        }

        return intdiv($months, $cycleMonths);
    }

    /** Whether a WHMCS addon is on its service's billing cycle, as an addon always is here (docs/addons.md, 2026-11-09). */
    public static function sameCycle(string $addonCycle, string $serviceCycle): bool
    {
        return self::cycleKey($addonCycle) === self::cycleKey($serviceCycle);
    }

    private static function cycleKey(string $cycle): string
    {
        return strtolower(str_replace(['-', ' '], '', trim($cycle)));
    }

    /** @return array<int, true> addons with a renewal unpaid past its due date (WHMCS's dunning suspended them) */
    private function overdue(): array
    {
        $ids = $this->rows("SELECT DISTINCT ii.relid FROM tblinvoiceitems ii JOIN tblinvoices i ON i.id = ii.invoiceid WHERE ii.type = 'Addon'
            AND i.status NOT IN ('Draft', 'Paid', 'Cancelled', 'Refunded') AND i.duedate > '0000-00-00' AND i.duedate < " . $this->whmcs->quote(now()->toDateString())) ?? [];

        return array_fill_keys(array_map(fn ($row) => (int) $row['relid'], $ids), true);
    }

    /** As a service WHMCS suspended for non-payment (ImportFromWhmcs::suspendedOn): its due date plus the days, never later than now. */
    private function suspendedOn(?string $due): Carbon
    {
        if ($due === null) {
            return now();
        }
        $on = Carbon::parse($due)->addDays($this->suspendDays);

        return $on->lessThan(now()) ? $on : now();
    }

    private static function date(mixed $value): ?string
    {
        $value = substr(trim((string) $value), 0, 10);

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }

    private function skip(string $what): void
    {
        $this->counts['skipped']++;
        $this->notes[] = $what;
    }

    /** @return list<array<string, mixed>>|null its rows, or null on a WHMCS without the table (an old one, a fixture) */
    private function rows(string $sql): ?array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return null;
        }
    }
}

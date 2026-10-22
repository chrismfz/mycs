<?php

namespace App\Services\Ssl;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Server;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Services\Domains\Pricing\TldPriceMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Openprovider's SSL products as a catalogue for staff (owner 2026-10-22: «what I may sell, at what price, with what
 * margin»): each product's cost per period (Openprovider's own 1, 2 and 3-year prices, not N times one year), whether
 * MyCS can sell it now, the MyCS product it is (by its Openprovider product id), and the prices a margin would make, with
 * the TLD import's arithmetic (TldPriceMath: exact, rounded once). The import writes only the products ticked: new ones
 * hidden until staff list them, an existing one's prices per period and currency, nothing else of it.
 */
final class SslCatalog
{
    public const MAX_YEARS = 3;

    /** Openprovider's categories of a website certificate the extension orders (one name and its www, or a wildcard). */
    private const WEBSITE_CATEGORIES = ['domain_validation', 'organization_validation', 'extended_validation', 'wildcard'];

    /**
     * Openprovider's rows, as staff read them. A cost is its reseller price rounded to the cent and above zero, an amount
     * MyCS takes (ADV-1, ADV-5 of #348: never a free or broken price); a period listed twice, or a product, keeps its
     * higher cost (ADV-8); none beyond the product's `max_period` (ADV-6). Sellable: a website certificate only, by its
     * category, its flags and its name (ADV-7: never what the extension cannot order).
     *
     * @param  list<array<string, mixed>>  $raw
     * @return array<int, array{id: int, name: string, brand: string, validation: string, wildcard: bool, sellable: bool, reason: ?string, costs: array<int, array{cost: string, currency: string}>}>
     */
    public static function entries(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $maxPeriod = (int) ($row['max_period'] ?? 0);
            $costs = $out[$id]['costs'] ?? [];
            foreach ((array) ($row['prices'] ?? []) as $price) {
                $years = (int) ($price['period'] ?? 0);
                $amount = $price['price']['reseller']['price'] ?? null;
                $currency = strtoupper((string) ($price['price']['reseller']['currency'] ?? ''));
                if ($years < 1 || $years > self::MAX_YEARS || ($maxPeriod > 0 && $years > $maxPeriod) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
                    || !(is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount))) || !is_finite((float) $amount)) {
                    continue;
                }
                $cost = number_format(round((float) $amount, 2), 2, '.', '');
                if (!TldPriceMath::isAmount($cost) || bccomp($cost, '0', 2) <= 0) {
                    continue;
                }
                $known = $costs[$years] ?? null;
                if ($known !== null && ($known['currency'] !== $currency || bccomp($known['cost'], $cost, 2) >= 0)) {
                    continue; // the higher of two, never a lower duplicate; two currencies for one period: the first
                }
                $costs[$years] = ['cost' => $cost, 'currency' => $currency];
            }
            ksort($costs);
            $name = trim((string) ($row['name'] ?? ''));
            $category = (string) ($row['category'] ?? '');
            $names = max((int) ($row['max_domains'] ?? 0), (int) ($row['included_domains_count'] ?? 0), (int) ($row['number_of_domains'] ?? 0));
            $reason = match (true) {
                preg_match('/code[\s-]*sign/i', $name) === 1 => 'code_signing',
                preg_match('#s/?mime|personal|e-?mail#i', $name) === 1 => 'smime',
                $category === 'multi_domain' || $names > 1 => 'multi_domain',
                !in_array($category, self::WEBSITE_CATEGORIES, true) || !in_array((string) ($row['validation_method'] ?? ''), ['domain', 'organization', 'extended'], true) => 'not_website',
                !isset($costs[1]) => 'no_price',
                default => null,
            };
            $sellable = $reason === null && ($out[$id]['sellable'] ?? true);
            $out[$id] = [
                'id' => $id,
                'name' => $out[$id]['name'] ?? $name,
                'brand' => $out[$id]['brand'] ?? trim((string) ($row['brand_name'] ?? '')),
                'validation' => $out[$id]['validation'] ?? (string) ($row['validation_method'] ?? ''),
                'wildcard' => ($out[$id]['wildcard'] ?? false) || !empty($row['is_wildcard_supported']) || $category === 'wildcard',
                'sellable' => $sellable,
                'reason' => $sellable ? null : ($out[$id]['reason'] ?? $reason ?? 'no_price'),
                'costs' => $costs,
            ];
        }

        return $out;
    }

    /**
     * The prices a margin makes, per currency and years: Openprovider's cost of those years, in its currency or
     * converted from euros by staff's rate (the rates are of the euro: a cost in another currency is never converted
     * with them, ADV-2 of #348); a currency with no rate gets none.
     *
     * @param  array{costs: array<int, array{cost: string, currency: string}>}  $entry
     * @return array<string, array<int, array{price: string, cost: string}>> currency => years => price and cost (in it)
     */
    public static function prices(array $entry, TldImportOptions $options): array
    {
        $out = [];
        foreach (Currency::query()->orderBy('code')->pluck('code') as $currency) {
            foreach ($entry['costs'] as $years => $cost) {
                $converted = $years <= $options->years ? self::cost($cost, $currency, $options) : null;
                if ($converted === null) {
                    continue; // never a silent conversion
                }
                $out[$currency][$years] = [
                    'price' => TldPriceMath::round(TldPriceMath::withMargin($converted, $options->marginType, $options->margin), $options->rounding),
                    'cost' => TldPriceMath::cents($converted),
                ];
            }
        }

        return $out;
    }

    /**
     * A cost in this currency: its own, or from euros by staff's rate; none otherwise.
     *
     * @param  array{cost: string, currency: string}  $cost
     */
    public static function cost(array $cost, string $currency, TldImportOptions $options): ?string
    {
        if ($currency === $cost['currency']) {
            return $cost['cost'];
        }
        $rate = $cost['currency'] === 'EUR' ? ($options->rates[$currency] ?? null) : null;

        return $rate === null || $rate === '' ? null : TldPriceMath::convert($cost['cost'], $rate);
    }

    /** The margin a price leaves over a cost, in percent of the cost (one decimal); none for no cost. */
    public static function marginPercent(string $price, string $cost): ?string
    {
        if (bccomp($cost, '0', 2) <= 0) {
            return null;
        }

        return bcadd(bcmul(bcdiv(bcsub($price, $cost, 8), $cost, 8), '100', 8), '0', 1);
    }

    /**
     * The live (not retired) MyCS products of this SSL server, by their Openprovider product id: all of them (two with one
     * id are both repriced and both shown, ADV-3 of #348), each with its prices now, the lowest where two plans have the
     * same years (ADV-10).
     *
     * @return array<int, list<array{product: Product, prices: array<string, array<int, string>>}>> id => products
     */
    public static function linked(Server $server): array
    {
        $out = [];
        $products = Product::query()->where('server_id', $server->id)->where('retired', false)->orderBy('id')->with(['settings', 'plans.prices'])->get();
        foreach ($products as $product) {
            $id = (int) $product->settings->pluck('value', 'key')->get('product_id');
            if ($id <= 0) {
                continue;
            }
            $prices = [];
            foreach ($product->plans as $plan) {
                $years = self::planYears($plan);
                if ($years === null || $plan->type !== 'recurring') {
                    continue;
                }
                foreach ($plan->prices as $price) {
                    if ($price->price !== null) {
                        $now = $prices[$price->currency_code][$years] ?? null;
                        $amount = number_format((float) $price->price, 2, '.', '');
                        $prices[$price->currency_code][$years] = $now === null || bccomp($amount, $now, 2) < 0 ? $amount : $now;
                    }
                }
            }
            $out[$id][] = ['product' => $product, 'prices' => $prices];
        }

        return $out;
    }

    /**
     * Import the products ticked, all or nothing, one at a time per server (its row locked): each Openprovider id once
     * (ADV-4 of #348); a new one made (hidden unless staff say otherwise) on this server and category; each live product
     * of an id keeps its name, listing and other plans, and every recurring plan of each period up to the years chosen
     * gets the new prices (made when missing; ADV-10). A free or not-offered price stays as it is (as the TLD import,
     * D-6). A product priced in a currency the import cannot price (no rate) is refused, never left under its cost
     * unseen (ADV-11). Only sellable products.
     *
     * @param  list<array<string, mixed>>  $entries  as entries() made them, the ticked ones
     * @return int products written
     */
    public static function import(Server $server, array $entries, TldImportOptions $options, int $categoryId, bool $hidden): int
    {
        return DB::transaction(function () use ($server, $entries, $options, $categoryId, $hidden) {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $linked = self::linked($server);
            $byId = [];
            foreach ($entries as $entry) {
                if (($entry['sellable'] ?? false) && isset($entry['id'])) {
                    $byId[(int) $entry['id']] = $entry;
                }
            }
            $count = 0;
            foreach ($byId as $id => $entry) {
                $prices = self::prices($entry, $options);
                if ($prices === []) {
                    continue;
                }
                foreach ($linked[$id] ?? [] as $existing) {
                    foreach ($existing['prices'] as $currency => $byYears) {
                        foreach ($byYears as $years => $price) {
                            if ($years <= $options->years && isset($entry['costs'][$years]) && bccomp($price, '0', 2) > 0 && !isset($prices[$currency][$years])) {
                                throw new InvalidArgumentException(__('«:product» is priced in :currency, which the import cannot price: give its rate.', [
                                    'product' => $existing['product']->name, 'currency' => $currency]));
                            }
                        }
                    }
                }
                $products = array_column($linked[$id] ?? [], 'product') ?: [self::newProduct($server, $entry, $categoryId, $hidden)];
                foreach ($products as $product) {
                    self::reprice($product, $entry, $prices, $options);
                }
                $count++;
            }

            return $count;
        });
    }

    /** @param  array<string, array<int, array{price: string, cost: string}>>  $prices */
    private static function reprice(Product $product, array $entry, array $prices, TldImportOptions $options): void
    {
        foreach (range(1, min($options->years, self::MAX_YEARS)) as $years) {
            if (!isset($entry['costs'][$years])) {
                continue;
            }
            $plans = $product->plans()->get()->filter(fn (Plan $plan) => $plan->type === 'recurring' && self::planYears($plan) === $years);
            if ($plans->isEmpty()) {
                $plan = (new Plan)->forceFill(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'type' => 'recurring',
                    'name' => $years === 1 ? '1 year' : "{$years} years", 'billing_unit' => 'year', 'billing_period' => $years, 'sort' => $years]);
                $plan->save();
                $plans = collect([$plan]);
            }
            foreach ($plans as $plan) {
                foreach ($prices as $currency => $byYears) {
                    if (!isset($byYears[$years])) {
                        continue;
                    }
                    $row = $plan->prices()->where('currency_code', $currency)->first();
                    if ($row !== null && $options->keepFreeAndClosed && ($row->price === null || bccomp((string) $row->price, '0', 2) === 0)) {
                        continue; // a free or not-offered price is a decision (D-6)
                    }
                    $plan->prices()->updateOrCreate(['currency_code' => $currency], ['price' => $byYears[$years]['price']]);
                }
            }
        }
    }

    /** A plan's years: 1 to 3 years, as a year unit or 12, 24, 36 months. */
    private static function planYears(Plan $plan): ?int
    {
        $months = match ($plan->billing_unit) {
            'year' => (int) $plan->billing_period * 12,
            'month' => (int) $plan->billing_period,
            default => 0,
        };

        return $months > 0 && $months % 12 === 0 && $months <= self::MAX_YEARS * 12 ? intdiv($months, 12) : null;
    }

    /** @param  array{id: int, name: string, brand: string}  $entry */
    private static function newProduct(Server $server, array $entry, int $categoryId, bool $hidden): Product
    {
        $name = trim($entry['brand'] . ' ' . $entry['name']);
        $slug = $base = Str::slug($name) ?: 'ssl-' . $entry['id'];
        for ($n = 2; Product::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }
        $product = Product::query()->create(['category_id' => $categoryId, 'name' => $name, 'slug' => $slug, 'description' => '',
            'server_id' => $server->id, 'hidden' => $hidden, 'autosetup' => Product::AUTOSETUP_PAYMENT]);
        $product->settings()->create(['key' => 'product_id', 'value' => (string) $entry['id']]);

        return $product;
    }
}

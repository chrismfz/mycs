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

    /**
     * Openprovider's rows, as staff read them.
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
            $costs = [];
            foreach ((array) ($row['prices'] ?? []) as $price) {
                $years = (int) ($price['period'] ?? 0);
                $amount = $price['price']['reseller']['price'] ?? null;
                $currency = strtoupper((string) ($price['price']['reseller']['currency'] ?? ''));
                if ($years >= 1 && $years <= self::MAX_YEARS && (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount)))
                    && (float) $amount > 0 && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                    $costs[$years] = ['cost' => number_format(round((float) $amount, 2), 2, '.', ''), 'currency' => $currency];
                }
            }
            ksort($costs);
            $name = trim((string) ($row['name'] ?? ''));
            $category = (string) ($row['category'] ?? '');
            $reason = match (true) {
                stripos($name, 'code signing') !== false => 'code_signing',
                stripos($name, 's/mime') !== false => 'smime',
                $category === 'multi_domain' || (int) ($row['max_domains'] ?? 0) > 1 || (int) ($row['included_domains_count'] ?? 0) > 1 => 'multi_domain',
                !isset($costs[1]) => 'no_price',
                default => null,
            };
            $out[$id] = [
                'id' => $id,
                'name' => $name,
                'brand' => trim((string) ($row['brand_name'] ?? '')),
                'validation' => (string) ($row['validation_method'] ?? ''),
                'wildcard' => !empty($row['is_wildcard_supported']) || $category === 'wildcard',
                'sellable' => $reason === null,
                'reason' => $reason,
                'costs' => $costs,
            ];
        }

        return $out;
    }

    /**
     * The prices a margin makes, per currency and years (Openprovider's cost of those years, converted by staff's rate
     * when it is in another currency; a currency with no rate gets none).
     *
     * @param  array{costs: array<int, array{cost: string, currency: string}>}  $entry
     * @return array<string, array<int, array{price: string, cost: string}>> currency => years => price and cost (in it)
     */
    public static function prices(array $entry, TldImportOptions $options): array
    {
        $out = [];
        foreach (Currency::query()->orderBy('code')->pluck('code') as $currency) {
            foreach ($entry['costs'] as $years => $cost) {
                if ($years > $options->years) {
                    continue;
                }
                $rate = $currency === $cost['currency'] ? '1' : ($options->rates[$currency] ?? null);
                if ($rate === null || $rate === '') {
                    continue; // never a silent conversion
                }
                $converted = TldPriceMath::convert($cost['cost'], $rate);
                $out[$currency][$years] = [
                    'price' => TldPriceMath::round(TldPriceMath::withMargin($converted, $options->marginType, $options->margin), $options->rounding),
                    'cost' => TldPriceMath::cents($converted),
                ];
            }
        }

        return $out;
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
     * The MyCS products of this SSL server, by their Openprovider product id, with their prices now.
     *
     * @return array<int, array{product: Product, prices: array<string, array<int, string>>}> id => product, currency => years => price
     */
    public static function linked(Server $server): array
    {
        $out = [];
        $products = Product::query()->where('server_id', $server->id)->with(['settings', 'plans.prices'])->get();
        foreach ($products as $product) {
            $id = (int) $product->settings->pluck('value', 'key')->get('product_id');
            if ($id <= 0 || isset($out[$id])) {
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
                        $prices[$price->currency_code][$years] = (string) $price->price;
                    }
                }
            }
            $out[$id] = ['product' => $product, 'prices' => $prices];
        }

        return $out;
    }

    /**
     * Import the products ticked, all or nothing: a new one made (hidden unless staff say otherwise) on this server and
     * category; an existing one (its Openprovider id on this server) keeps its name, listing and other plans, its plan
     * of each period up to the years chosen gets the new prices (made when missing). Only sellable products.
     *
     * @param  list<array<string, mixed>>  $entries  as entries() made them, the ticked ones
     * @return int products written
     */
    public static function import(Server $server, array $entries, TldImportOptions $options, int $categoryId, bool $hidden): int
    {
        return DB::transaction(function () use ($server, $entries, $options, $categoryId, $hidden) {
            $linked = self::linked($server);
            $count = 0;
            foreach ($entries as $entry) {
                if (!($entry['sellable'] ?? false)) {
                    continue;
                }
                $prices = self::prices($entry, $options);
                if ($prices === []) {
                    continue;
                }
                $product = $linked[$entry['id']]['product'] ?? self::newProduct($server, $entry, $categoryId, $hidden);
                foreach (range(1, min($options->years, self::MAX_YEARS)) as $years) {
                    if (!isset($entry['costs'][$years])) {
                        continue;
                    }
                    $plan = $product->plans()->get()->first(fn (Plan $plan) => $plan->type === 'recurring' && self::planYears($plan) === $years);
                    if ($plan === null) {
                        $plan = (new Plan)->forceFill(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'type' => 'recurring',
                            'name' => trans_choice('{1} :count year|[2,*] :count years', $years, ['count' => $years]),
                            'billing_unit' => 'year', 'billing_period' => $years, 'sort' => $years]);
                        $plan->save();
                    }
                    foreach ($prices as $currency => $byYears) {
                        if (isset($byYears[$years])) {
                            $plan->prices()->updateOrCreate(['currency_code' => $currency], ['price' => $byYears[$years]['price']]);
                        }
                    }
                }
                $count++;
            }

            return $count;
        });
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

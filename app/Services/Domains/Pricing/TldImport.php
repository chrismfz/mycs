<?php

namespace App\Services\Domains\Pricing;

use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\DomainRegistrarFactory;
use App\Support\Domains\TldPricing;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHMCS's «TLD Import & Pricing Sync» (docs/domains.md D-6): read the registrar's costs for some TLDs, show them with
 * the price the margin would make, and import only the TLDs staff ticked: each TLD (created if new; its registrar for
 * automatic registration if asked) and its prices in every currency of MyCS that has a rate. Register and renew for 1
 * to N years (N times the rounded 1-year price), transfer for 1 year, the redemption fee if asked. A price is written
 * with the cost it came from. Prices it was not asked for (other currencies, more years, grace) stay as they are, and
 * so do free and not-offered ones unless staff say otherwise. A TLD nothing could be priced for is not created.
 */
class TldImport
{
    /** The operations the import prices, and whether they are priced per year. */
    private const OPERATIONS = ['register' => true, 'renew' => true, 'transfer' => false, 'grace' => false, 'redemption' => false];

    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /**
     * The registrar's costs, per TLD: its TldPricing, or the reason it could not be read (one TLD never stops the rest).
     *
     * @param  list<string>  $tlds
     * @return array<string, TldPricing|string>
     */
    public function fetch(DomainRegistrarConnection $connection, array $tlds): array
    {
        $adapter = $this->factory->for($connection);
        $credentials = $this->factory->credentialsFor($connection);
        $out = [];
        foreach ($tlds as $tld) {
            try {
                $out[$tld] = $adapter->getTldPricing($tld, $credentials);
            } catch (Throwable $e) {
                $out[$tld] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * The prices the import would write for one TLD: currency => operation => years => price (and the cost behind it).
     *
     * @return array<string, array<string, array<int, array{price: string, cost: string, cost_currency: string}>>>
     */
    public function prices(TldPricing $pricing, TldImportOptions $options): array
    {
        $out = [];
        foreach (Currency::query()->orderBy('code')->pluck('code') as $currency) {
            foreach (self::OPERATIONS as $operation => $perYear) {
                // The fees after expiry (D5a: the restore fee is the grace stage's where the TLD has no redemption).
                if (in_array($operation, ['grace', 'redemption'], true) && !$options->redemptionFee) {
                    continue;
                }
                $entry = $pricing->costs[$operation] ?? null;
                if ($entry === null) {
                    continue;
                }
                $rate = $currency === $entry['currency'] ? '1' : ($options->rates[$currency] ?? null);
                if ($rate === null || $rate === '' || !TldPriceMath::isAmount($entry['cost'])) {
                    continue; // never a silent conversion, never a negative or garbled cost
                }
                // Exact until one rounding (review: converting, then adding the margin, each to the cent, drifted).
                $yearly = TldPriceMath::round(
                    TldPriceMath::withMargin(TldPriceMath::convert($entry['cost'], $rate), $options->marginType, $options->margin),
                    $options->rounding,
                );
                foreach (range(1, $perYear ? $options->years : 1) as $years) {
                    $out[$currency][$operation][$years] = [
                        'price' => TldPriceMath::years($yearly, $years),
                        'cost' => TldPriceMath::years($entry['cost'], $perYear ? $years : 1),
                        'cost_currency' => $entry['currency'],
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Import the ticked TLDs, all or nothing.
     *
     * @param  array<string, TldPricing>  $pricings  tld => its costs, as fetched
     * @return int the TLDs imported
     */
    public function import(DomainRegistrarConnection $connection, array $pricings, TldImportOptions $options): int
    {
        return DB::transaction(function () use ($connection, $pricings, $options) {
            $imported = 0;
            foreach ($pricings as $tld => $pricing) {
                $prices = $this->prices($pricing, $options);
                if ($prices === []) {
                    continue; // nothing could be priced (no rate for the registrar's currency): not even the TLD
                }
                $row = DomainTld::query()->firstOrNew(['tld' => $tld]);
                if (!$row->exists) {
                    $row->is_active = true;
                }
                if ($options->autoRegister || !$row->exists) {
                    $row->registrar_connection_id = $options->autoRegister ? $connection->id : $row->registrar_connection_id;
                }
                // The registry's stages after expiry, as the registrar reports them (D5a): only from the TLD's own
                // registrar (review 6).
                if ($row->registrar_connection_id === $connection->id) {
                    $row->fill(array_filter(['grace_days' => $pricing->graceDays, 'redemption_days' => $pricing->redemptionDays], fn ($days) => $days !== null));
                }
                $row->save();

                foreach ($prices as $currency => $operations) {
                    foreach ($operations as $operation => $byYears) {
                        foreach ($byYears as $years => $price) {
                            $existing = DomainTldPrice::query()->where(['domain_tld_id' => $row->id, 'operation' => $operation, 'years' => $years, 'currency_code' => $currency])->first();
                            // Free and not offered are staff's decisions (D-6): the import keeps them unless told not to.
                            if ($existing !== null && $options->keepFreeAndClosed && ($existing->price === null || bccomp((string) $existing->price, '0', 2) === 0)) {
                                $existing->update(['cost' => $price['cost'], 'cost_currency' => $price['cost_currency']]);

                                continue;
                            }
                            DomainTldPrice::query()->updateOrCreate(
                                ['domain_tld_id' => $row->id, 'operation' => $operation, 'years' => $years, 'currency_code' => $currency],
                                $price,
                            );
                        }
                    }
                }
                $imported++;
            }

            return $imported;
        });
    }
}

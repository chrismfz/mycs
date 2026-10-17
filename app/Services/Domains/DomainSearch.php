<?php

namespace App\Services\Domains;

use App\Models\Currency;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\Lookup\DomainLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Nixpal\DomainTools\DomainName;
use Throwable;

/**
 * The shop's domain search (D8a, owner 2026-10-17: one box, the exact name first, then the TLDs staff put first): what
 * we sell (active TLDs with a register price in the currency), whether each name is free (its TLD's registrar, else RDAP;
 * cached 5 minutes), and its price for a year (and the renewal's when it differs). Premium names are not sold here.
 */
class DomainSearch
{
    /** How many TLDs the search offers besides the one typed (staff order them in Domain Pricing). */
    public const ALTERNATIVES = 6;

    public const FREE = 'free';

    public const TAKEN = 'taken';

    public const UNKNOWN = 'unknown';

    public const PREMIUM = 'premium';

    public function __construct(
        private readonly DomainRegistrarFactory $registrars,
        private readonly DomainLookup $lookup,
    ) {}

    /** @return Collection<int, DomainTld> what we sell in $currency: active, with a register price for a year */
    public function sold(string $currency): Collection
    {
        return DomainTld::query()->where('is_active', true)->with(['prices', 'registrarConnection'])->orderBy('sort')->orderBy('tld')->get()
            ->filter(fn (DomainTld $tld) => $tld->priceFor('register', 1, $currency) !== null)->values();
    }

    /**
     * The results for what was typed: the exact name first (its TLD, or the first sold), then the others staff put first.
     *
     * @return list<array{fqdn: string, tld: string, status: string, price: string, renew: ?string}>|null null: not a name;
     *                                                                                                    empty: nothing sold in $currency
     */
    public function search(string $input, string $currency): ?array
    {
        $sold = $this->sold($currency)->keyBy('tld');
        if ($sold->isEmpty()) {
            return []; // nothing sold in this currency
        }
        $name = DomainName::parse($input, $sold->keys()->all());
        if ($name === null) {
            return null;
        }
        $tlds = collect([$name->tld ?? $sold->keys()->first()])
            ->concat($sold->keys()->take(self::ALTERNATIVES + 1))->unique()->take(self::ALTERNATIVES + 1);

        return $tlds->map(function (string $tld) use ($name, $sold, $currency) {
            $model = $sold[$tld];
            $fqdn = $name->withTld($tld);
            $register = (string) $model->priceFor('register', 1, $currency);
            $renew = $model->priceFor('renew', 1, $currency);

            return [
                'fqdn' => $fqdn,
                'tld' => $tld,
                'status' => $this->status($model, $fqdn),
                'price' => $register,
                // «then X a year» only when it differs (a first-year offer).
                'renew' => $renew !== null && bccomp($renew, $register, 2) !== 0 ? $renew : null,
            ];
        })->values()->all();
    }

    /** Free, taken, premium or unknown: its TLD's registrar (D-…: «is it free?» per TLD), else RDAP. */
    public function status(DomainTld $tld, string $fqdn): string
    {
        return Cache::remember('domain-available:' . sha1($fqdn), 300, function () use ($tld, $fqdn) {
            $connection = $tld->registrarConnection;
            if ($connection !== null && $connection->is_active) {
                try {
                    $result = $this->registrars->for($connection)->checkAvailability($fqdn, $this->registrars->credentialsFor($connection));

                    return match (true) {
                        $result->premium => self::PREMIUM,
                        $result->available => self::FREE,
                        default => self::TAKEN,
                    };
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return match ($this->lookup->availableByRdap($fqdn)) {
                true => self::FREE,
                false => self::TAKEN,
                null => self::UNKNOWN,
            };
        });
    }

    /**
     * The price list (D8a): every sold TLD with register, renew and transfer for each year priced, in $currency.
     *
     * @return list<array{tld: string, years: array<int, array{register: ?string, renew: ?string, transfer: ?string}>}>
     */
    public function priceList(string $currency): array
    {
        return $this->sold($currency)->map(function (DomainTld $tld) use ($currency) {
            $years = $tld->prices->where('currency_code', $currency)->pluck('years')->unique()->sort()->values();

            return [
                'tld' => $tld->tld,
                'years' => $years->mapWithKeys(fn (int $y) => [$y => [
                    'register' => $tld->priceFor('register', $y, $currency),
                    'renew' => $tld->priceFor('renew', $y, $currency),
                    'transfer' => $tld->priceFor('transfer', $y, $currency),
                ]])->all(),
            ];
        })->values()->all();
    }

    /** The currencies the shop sells in (those with any TLD price). */
    public static function currencies(): Collection
    {
        return Currency::query()->whereIn('code', DomainTldPrice::query()->distinct()->pluck('currency_code'))->get();
    }
}

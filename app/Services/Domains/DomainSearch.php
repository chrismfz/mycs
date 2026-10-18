<?php

namespace App\Services\Domains;

use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Nixpal\DomainTools\DomainName;
use Throwable;

/**
 * The shop's domain search (D8a, owner 2026-10-17: one box, the exact name first, then the TLDs staff put first): what
 * we sell (active TLDs with a register price in the currency), whether each name is free, and its price for a year (and
 * the renewal's when it differs). Premium names are not sold here.
 *
 * After review (#178): one registrar request per search (its names together, 5 seconds at most), RDAP only for TLDs
 * without a registrar, within a time budget; only definite answers are cached (5 minutes); a registrar that failed says
 * «could not check», never RDAP's «free» (it cannot see premium); at most GLOBAL_CHECKS registrar lookups a minute for
 * the whole site, so no one can spend the registrar account.
 */
class DomainSearch
{
    /** How many TLDs the search offers besides the one typed (staff order them in Domain Pricing). */
    public const ALTERNATIVES = 6;

    /** Registrar requests a minute, the whole site (review R2). */
    public const GLOBAL_CHECKS = 120;

    /** Seconds RDAP may take in all for one search (review R1). */
    public const RDAP_BUDGET = 8;

    public const FREE = 'free';

    public const TAKEN = 'taken';

    public const UNKNOWN = 'unknown';

    public const PREMIUM = 'premium';

    /** Not free as it is, registrable with the regulator's approval (geographic terms, .gov.gr): never «taken». */
    public const RESTRICTED = 'restricted';

    public function __construct(
        private readonly DomainRegistrarFactory $registrars,
        private readonly DomainLookup $lookup,
    ) {}

    /**
     * @return Collection<int, DomainTld> what we sell in $currency: active, with a register price (for its shortest period:
     *                                    a year for most, two for .gr, whose periods are even: G4b of grepp)
     */
    public function sold(string $currency): Collection
    {
        return DomainTld::query()->where('is_active', true)->with(['prices', 'registrarConnection'])->orderBy('sort')->orderBy('tld')->get()
            ->filter(fn (DomainTld $tld) => $tld->shortestYears('register', $currency) !== null)->values();
    }

    /**
     * The results for what was typed: the exact name first (its TLD, or the first sold), then the others staff put first.
     *
     * @return list<array{fqdn: string, tld: string, status: string, years: int, price: string, renew: ?string}>|null null: not a name;
     *                                                                                                                empty: nothing sold in $currency
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
        // A name in another script (punycode) only where its TLD takes one (allow_idn, adversarial ADV-5).
        if (str_starts_with($name->sld, 'xn--')) {
            $sold = $sold->filter(fn (DomainTld $tld) => $tld->allow_idn);
            if ($sold->isEmpty()) {
                return null;
            }
        }
        $first = $name->tld !== null && $sold->has($name->tld) ? $name->tld : $sold->keys()->first();
        $tlds = collect([$first])
            ->concat($sold->keys()->take(self::ALTERNATIVES + 1))->unique()->take(self::ALTERNATIVES + 1)->values();
        $names = $tlds->mapWithKeys(fn (string $tld) => [$name->withTld($tld) => $sold[$tld]])->all();
        $statuses = $this->statuses($names);

        return collect($names)->map(function (DomainTld $model, string $fqdn) use ($currency, $statuses) {
            // Its shortest period (a year for most; two for .gr) and its price, renewed for as long.
            $years = (int) $model->shortestYears('register', $currency);
            $register = (string) $model->priceFor('register', $years, $currency);
            $renew = $model->priceFor('renew', $years, $currency);

            return [
                'fqdn' => $fqdn,
                'tld' => $model->tld,
                'status' => $statuses[$fqdn] ?? self::UNKNOWN,
                'years' => $years,
                'price' => $register,
                // «then X a year» only when it differs (a first-year offer).
                'renew' => $renew !== null && bccomp($renew, $register, 2) !== 0 ? $renew : null,
            ];
        })->values()->all();
    }

    /**
     * Whether each name is free: from the cache, else its TLD's registrar (all of one registrar's in one request), else
     * RDAP for TLDs without one.
     *
     * @param  array<string, DomainTld>  $names  fqdn => its TLD
     * @return array<string, string> fqdn => status
     */
    public function statuses(array $names): array
    {
        $statuses = [];
        $byConnection = [];
        $rdap = [];
        foreach ($names as $fqdn => $tld) {
            $connection = $tld->registrarConnection;
            if (($cached = Cache::get(self::cacheKey($fqdn))) !== null) {
                $statuses[$fqdn] = $cached;
            } elseif ($connection !== null && $connection->is_active) {
                $byConnection[$connection->id]['connection'] = $connection;
                $byConnection[$connection->id]['names'][] = $fqdn;
            } else {
                $rdap[] = $fqdn;
            }
        }
        foreach ($byConnection as $group) {
            $statuses += $this->atRegistrar($group['connection'], $group['names']);
        }
        $deadline = microtime(true) + self::RDAP_BUDGET;
        foreach ($rdap as $fqdn) {
            if (microtime(true) > $deadline) {
                $statuses[$fqdn] = self::UNKNOWN; // past the budget: «could not check», not a wait

                continue;
            }
            $status = match ($this->lookup->availableByRdap($fqdn)) {
                true => self::FREE,
                false => self::TAKEN,
                null => self::UNKNOWN,
            };
            $this->remember($fqdn, $status);
            $statuses[$fqdn] = $status;
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $fqdns
     * @return array<string, string>
     */
    private function atRegistrar(DomainRegistrarConnection $connection, array $fqdns): array
    {
        $unknown = array_fill_keys($fqdns, self::UNKNOWN);
        // The whole site's budget at the registrar (review R2): past it, «could not check» until the minute turns.
        if (RateLimiter::tooManyAttempts('domain-search-registrar', self::GLOBAL_CHECKS)) {
            return $unknown;
        }
        RateLimiter::hit('domain-search-registrar', 60);
        try {
            $registrar = $this->registrars->for($connection);
            $credentials = $this->registrars->credentialsFor($connection);
            // All of a search's names in one request where the registrar can (Openprovider, the .gr registry).
            $results = $registrar instanceof OpenproviderRegistrar || $registrar instanceof GrEppRegistrar
                ? $registrar->checkAvailabilityMany($fqdns, $credentials)
                : collect($fqdns)->mapWithKeys(fn ($fqdn) => [$fqdn => $registrar->checkAvailability($fqdn, $credentials)])->all();
        } catch (Throwable $e) {
            // Reported once a minute per registrar, not for every search during an outage (review R11).
            if (Cache::add("domain-search-registrar-failed:{$connection->id}", true, 60)) {
                report($e);
            }

            return $unknown; // never RDAP's «free» instead: it cannot see a premium name (review R4)
        }
        $statuses = [];
        foreach ($fqdns as $fqdn) {
            $result = $results[$fqdn] ?? null;
            $status = match (true) {
                $result === null => self::UNKNOWN,
                !$result->available && str_starts_with((string) $result->reason, 'restricted:') => self::RESTRICTED,
                !$result->available => self::TAKEN,
                $result->premium => self::PREMIUM, // a premium name that is free (review R4: a taken one is taken)
                default => self::FREE,
            };
            $this->remember($fqdn, $status);
            $statuses[$fqdn] = $status;
        }

        return $statuses;
    }

    /** Only a definite answer is kept (review R3): «could not check» is asked again next time. */
    private function remember(string $fqdn, string $status): void
    {
        if ($status !== self::UNKNOWN) {
            Cache::put(self::cacheKey($fqdn), $status, 300);
        }
    }

    private static function cacheKey(string $fqdn): string
    {
        return 'domain-available:' . sha1(mb_strtolower($fqdn));
    }

    /**
     * The price list (D8a): every sold TLD with register, renew and transfer for each year priced, in $currency.
     *
     * @return list<array{tld: string, years: array<int, array{register: ?string, renew: ?string, transfer: ?string}>}>
     */
    public function priceList(string $currency): array
    {
        return $this->sold($currency)->map(function (DomainTld $tld) use ($currency) {
            // The years of what the list shows (review R12: not a grace or redemption fee's).
            $years = $tld->prices->where('currency_code', $currency)->whereIn('operation', ['register', 'renew', 'transfer'])
                ->pluck('years')->unique()->sort()->values();

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

    /** The cached price lists, cleared when a TLD's price changes (review R12). */
    public static function forgetPriceLists(): void
    {
        foreach (Currency::query()->pluck('code') as $code) {
            Cache::forget("domain-price-list:{$code}");
        }
    }
}

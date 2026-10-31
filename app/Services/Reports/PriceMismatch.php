<?php

namespace App\Services\Reports;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Billing\RenewalPrice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * What renews at a price other than the catalog's (sweep 9γ, owner 2026-10-30): for staff to see before the renewals
 * bill it. A service: its own price (services.price) against what the catalog makes of it for its next renewal (its
 * plan, options, coupon, group discount: Service::calculatePrice). A domain with a renewal price of its own
 * (recurring_amount) against its extension's renew price for its years; one without follows its extension already.
 * Read only: staff change it on the service or the domain.
 */
final class PriceMismatch
{
    /** How long a sweep is kept: every filter and page reads it (ADV-PM-5); «Refresh» sweeps again. */
    public const KEEP_SECONDS = 600;

    /** @return array{at: string, rows: list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}>} */
    public static function kept(bool $fresh = false): array
    {
        // Per language: its rows carry translated words (a cycle, «years»; ADV-PM-R2-2). Kept: read without waiting
        // (ADV-PM-R3-2). Swept one at a time (R2-4), re-read under the lock; a sweep running elsewhere longer than a few
        // seconds: swept here too, never an error page (R3-3).
        $key = 'reports:price-mismatch:' . app()->getLocale();
        if (!$fresh && is_array($kept = Cache::get($key))) {
            return $kept;
        }
        $sweep = function () use ($key, $fresh) {
            if (!$fresh && is_array($kept = Cache::get($key))) {
                return $kept;
            }
            $kept = ['at' => now()->format('d/m/Y H:i'), 'rows' => self::rows()];
            Cache::put($key, $kept, self::KEEP_SECONDS);

            return $kept;
        };
        try {
            return Cache::lock('reports:price-mismatch:sweep', 300)->block(5, $sweep);
        } catch (LockTimeoutException) {
            return $sweep();
        }
    }

    /** @return list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}> */
    public static function rows(): array
    {
        return [...self::services(), ...self::domains()];
    }

    /** @return list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}> */
    private static function services(): array
    {
        $rows = [];
        Service::query()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->whereNotNull('plan_id')
            ->with(['plan.prices', 'nextPlan.prices', 'product', 'client.group', 'client.primaryContact', 'coupon.products', 'coupon.requiredProducts',
                'configs.configValue.plans.prices'])
            ->orderBy('id')->chunk(200, function ($services) use (&$rows) {
                foreach ($services as $service) {
                    // A free or one-time plan renews nothing; a plan with no price in its currency reads as free: no
                    // catalog to compare with (ADV-PM-4).
                    if ($service->plan === null || $service->plan->type !== 'recurring'
                        || !$service->plan->prices->contains('currency_code', $service->currency_code) || !self::optionsPriced($service)) {
                        continue;
                    }
                    // As its next renewal bills it (RenewalPrice: a coupon past its cycles, a cycle change), not the
                    // price kept on it (ADV-PM-3): the same as the forecast and Upcoming renewals.
                    $start = CarbonImmutable::parse($service->expires_at ?? now())->startOfDay();
                    $billed = RenewalPrice::for($service, $start, true);
                    $price = BigDecimal::of(number_format((float) $billed->price, 2, '.', ''));
                    // A coupon's cycle only where it counts (still running, for a number of cycles: ADV-PM-R2-5).
                    $counted = $billed->coupon !== null && $billed->coupon_expired_at === null && $billed->coupon->cycles() > 0;
                    $catalog = BigDecimal::of((string) $billed->calculatePrice(cycle: $counted ? $service->cycleNumber($start) : null));
                    if (!$price->isEqualTo($catalog)) {
                        $rows[] = self::row('service', (int) $service->id, $service->client_id, $service->client?->name,
                            trim($service->product->name . ' · ' . $billed->plan->label(), ' ·'), (string) $service->currency_code,
                            $price, $catalog, (bool) $service->price_locked);
                    }
                }
            });

        return $rows;
    }

    /**
     * Each option value it has is priced for its plan's cycle in its currency (or not priced for that cycle at all: free in
     * every currency); one priced only in other currencies reads as 0 (ADV-PM-R2-6): no catalog to compare with.
     */
    private static function optionsPriced(Service $service): bool
    {
        foreach ($service->configs as $config) {
            $forCycle = $config->configValue?->plans->filter(fn ($plan) => $plan->type === 'free'
                || ((int) $plan->billing_period === (int) $service->plan->billing_period && $plan->billing_unit === $service->plan->billing_unit));
            if ($forCycle === null || $forCycle->isEmpty() || $forCycle->contains(fn ($plan) => $plan->type === 'free')) {
                continue;
            }
            if (!$forCycle->contains(fn ($plan) => $plan->prices->contains('currency_code', $service->currency_code))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}> */
    private static function domains(): array
    {
        $rows = [];
        Domain::query()->whereNotNull('recurring_amount')
            ->whereIn('status', [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value])
            ->with(['domainTld.prices', 'domainTld.registrarConnection', 'registrarConnection', 'client'])->orderBy('id')
            ->chunk(200, function ($domains) use (&$rows) {
                foreach ($domains as $domain) {
                    // A name only staff price (.gr two characters) has no catalog price to compare with.
                    if ($domain->staffPriced()) {
                        continue;
                    }
                    $tld = $domain->domainTld?->priceFor('renew', max(1, (int) $domain->years), (string) $domain->currency_code);
                    if ($tld === null) {
                        continue; // its extension no longer offers it: nothing to compare (staff's own price)
                    }
                    $price = BigDecimal::of(number_format((float) $domain->recurring_amount, 2, '.', ''));
                    $catalog = BigDecimal::of(number_format((float) $tld, 2, '.', ''));
                    if (!$price->isEqualTo($catalog)) {
                        $rows[] = self::row('domain', (int) $domain->id, $domain->client_id, $domain->client->name,
                            $domain->fqdn . ' · ' . trans_choice(':count year|:count years', max(1, (int) $domain->years)), (string) $domain->currency_code,
                            $price, $catalog, false);
                    }
                }
            });

        return $rows;
    }

    /** @return array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool} */
    private static function row(string $type, int $id, ?int $clientId, ?string $client, string $name, string $currency, BigDecimal $price, BigDecimal $catalog, bool $locked): array
    {
        return ['type' => $type, 'id' => $id, 'client_id' => $clientId, 'client' => (string) $client, 'name' => $name, 'currency' => $currency,
            'price' => (string) $price->toScale(2), 'catalog' => (string) $catalog->toScale(2), 'difference' => (string) $price->minus($catalog)->toScale(2),
            'locked' => $locked];
    }
}

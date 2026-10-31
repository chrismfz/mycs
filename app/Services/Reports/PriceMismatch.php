<?php

namespace App\Services\Reports;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Service;
use Brick\Math\BigDecimal;

/**
 * What renews at a price other than the catalog's (sweep 9γ, owner 2026-10-30): for staff to see before the renewals
 * bill it. A service: its own price (services.price) against what the catalog makes of it for its next renewal (its
 * plan, options, coupon, group discount: Service::calculatePrice). A domain with a renewal price of its own
 * (recurring_amount) against its extension's renew price for its years; one without follows its extension already.
 * Read only: staff change it on the service or the domain.
 */
final class PriceMismatch
{
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
            ->with(['plan.prices', 'product', 'client', 'coupon', 'configs.configValue'])->orderBy('id')
            ->chunk(200, function ($services) use (&$rows) {
                foreach ($services as $service) {
                    // A free or one-time plan renews nothing.
                    if ($service->plan === null || $service->plan->type !== 'recurring') {
                        continue;
                    }
                    $price = BigDecimal::of(number_format((float) $service->price, 2, '.', ''));
                    $catalog = BigDecimal::of((string) $service->calculatePrice());
                    if (!$price->isEqualTo($catalog)) {
                        $rows[] = self::row('service', (int) $service->id, $service->client_id, $service->client?->name,
                            trim($service->product->name . ' · ' . $service->plan->label(), ' ·'), (string) $service->currency_code,
                            $price, $catalog, (bool) $service->price_locked);
                    }
                }
            });

        return $rows;
    }

    /** @return list<array{type: string, id: int, client_id: int|null, client: string, name: string, currency: string, price: string, catalog: string, difference: string, locked: bool}> */
    private static function domains(): array
    {
        $rows = [];
        Domain::query()->whereNotNull('recurring_amount')
            ->whereIn('status', [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value])
            ->with(['domainTld.prices', 'client'])->orderBy('id')
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

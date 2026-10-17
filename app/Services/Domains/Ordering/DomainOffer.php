<?php

namespace App\Services\Domains\Ordering;

use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Services\Domains\DomainSearch;
use Illuminate\Support\Collection;
use Nixpal\DomainTools\DomainName;

/**
 * What the shop sells of a name (D8b): a TLD we sell, active, with a price for the operation and years (D-5: years per
 * TLD and operation); a register only when free (never a premium name, D8a), a transfer only when taken and not ours
 * already; a name in another script only where its TLD allows it. Checked when added to the cart and again at checkout.
 */
class DomainOffer
{
    public function __construct(private readonly DomainSearch $search) {}

    /** @return array{0: string, 1: DomainTld} the normalised name and its TLD */
    public function check(string $fqdn, string $operation, int $years, string $currency, bool $availability = true): array
    {
        if (!in_array($operation, [DomainOrderLine::REGISTER, DomainOrderLine::TRANSFER], true) || !self::allowed($operation)) {
            throw new DisplayException(__('domains.order.not_offered'));
        }
        $sold = $this->search->sold($currency)->keyBy('tld');
        $name = DomainName::parse($fqdn, $sold->keys()->all());
        if ($name === null || $name->tld === null || !$sold->has($name->tld)) {
            throw new DisplayException(__('domains.order.not_offered'));
        }
        /** @var DomainTld $tld */
        $tld = $sold[$name->tld];
        $fqdn = $name->withTld($name->tld);
        if (str_starts_with($name->sld, 'xn--') && !$tld->allow_idn) {
            throw new DisplayException(__('domains.order.not_offered'));
        }
        if ($this->years($tld, $operation, $currency)->isEmpty()) {
            throw new DisplayException(__('domains.order.not_offered_op', ['operation' => __('domains.shop.' . $operation)])); // review L9
        }
        if (!in_array($years, $this->years($tld, $operation, $currency)->all(), true)) {
            throw new DisplayException(__('domains.order.years_not_offered', ['years' => $years]));
        }
        // One live record per name (live_fqdn): a name pending or ours already is not ordered again.
        if (Domain::query()->where('live_fqdn', $fqdn)->exists()) {
            throw new DisplayException(__('domains.order.already_here', ['domain' => $fqdn]));
        }
        if ($availability) {
            $status = $this->search->statuses([$fqdn => $tld])[$fqdn] ?? DomainSearch::UNKNOWN;
            $expected = $operation === DomainOrderLine::REGISTER ? DomainSearch::FREE : DomainSearch::TAKEN;
            if ($status !== $expected) {
                throw new DisplayException(match (true) {
                    $status === DomainSearch::PREMIUM => __('domains.shop.premium'),
                    $status === DomainSearch::UNKNOWN => __('domains.shop.unknown'),
                    $operation === DomainOrderLine::REGISTER => __('domains.order.taken', ['domain' => $fqdn]),
                    default => __('domains.order.not_registered', ['domain' => $fqdn]),
                });
            }
        }

        return [$fqdn, $tld];
    }

    /** The TLD we sell that $fqdn ends with, or null. */
    public function tldFor(string $fqdn, string $currency): ?DomainTld
    {
        $sold = $this->search->sold($currency)->keyBy('tld');
        $name = DomainName::parse($fqdn, $sold->keys()->all());

        return $name?->tld === null ? null : $sold[$name->tld];
    }

    /** Whether the shop takes this operation at all (Settings → Domains: allow registrations, allow transfers). */
    public static function allowed(string $operation): bool
    {
        return (bool) config($operation === DomainOrderLine::TRANSFER ? 'settings.domain_allow_transfer' : 'settings.domain_allow_register', true);
    }

    /** @return Collection<int, int> the years $operation is priced for in $currency, ascending (D-5) */
    public function years(DomainTld $tld, string $operation, string $currency): Collection
    {
        // A transfer adds one year at its registrar (transferIn has no period): never sold for more (adversarial note of
        // #180), else the customer pays years they never get.
        return $tld->prices->where('operation', $operation)->where('currency_code', $currency)->whereNotNull('price')
            ->pluck('years')->map(fn ($y) => (int) $y)->unique()
            ->reject(fn (int $y) => $operation === DomainOrderLine::TRANSFER && $y !== 1)->sort()->values();
    }
}

<?php

namespace App\Services\Domains\Ordering;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Services\Domains\DomainSearch;
use Illuminate\Support\Collection;
use Nixpal\DomainTools\DomainName;

/**
 * What the shop sells of a name (D8b): a TLD we sell, active, with a price for the operation and years (D-5: years per
 * TLD and operation); a register only when free (never a premium name, D8a), a transfer only when taken and not held
 * here (D-25); a name in another script only where its TLD allows it. Checked when added to the cart and again at checkout.
 */
class DomainOffer
{
    public function __construct(private readonly DomainSearch $search) {}

    /**
     * @param  int|null  $clientId  the account ordering it (ADV-DNH-3): never a second open order of a name it ordered
     * @return array{0: string, 1: DomainTld} the normalised name and its TLD
     */
    public function check(string $fqdn, string $operation, int $years, string $currency, bool $availability = true, ?int $clientId = null): array
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
        // Its registrar must be able to do it (review 1 of #191: never a paid order that only staff can fulfil).
        // Never sold at its TLD's price whatever the search said (review 3 of #202: a taken 2-character .gr name was
        // still transferable; the checkout re-checks without availability).
        if ($tld->staffPriced($fqdn)) {
            throw new DisplayException(__('domains.shop.premium'));
        }
        if (!$tld->registrarCan($operation)) {
            throw new DisplayException(__('domains.order.not_offered_op', ['operation' => __('domains.shop.' . $operation)]));
        }
        // A name held here is not ordered again (D-25, owner 2026-11-08, as WHMCS): active, in grace or redemption, or an
        // order paid (live_fqdn). An expired row or an unpaid order of it does not hold it: ordered again, whoever pays
        // first holds it (DomainNameHold), and the other rows are closed.
        if (Domain::query()->where('live_fqdn', $fqdn)->exists()) {
            throw new DisplayException(__('domains.order.already_here', ['domain' => $fqdn]));
        }
        // Nor by the same account twice (ADV-DNH-3): its unpaid order holds nothing, so live_fqdn no longer stops a
        // second one (two tabs, a retry), and paying both would charge it twice. Its order is paid, or cancelled first.
        if ($clientId !== null && Domain::query()->where('client_id', $clientId)->where('fqdn', $fqdn)
            ->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])->exists()) {
            throw new DisplayException(__('domains.order.already_ordered', ['domain' => $fqdn]));
        }
        if ($availability) {
            $error = self::availabilityError($this->search->statuses([$fqdn => $tld])[$fqdn] ?? DomainSearch::UNKNOWN, $operation, $fqdn);
            if ($error !== null) {
                throw new DisplayException($error);
            }
        }

        return [$fqdn, $tld];
    }

    /** Why a name in $status cannot be ordered for $operation (free to register, taken to transfer); null when it can. */
    public static function availabilityError(string $status, string $operation, string $fqdn): ?string
    {
        if ($status === ($operation === DomainOrderLine::REGISTER ? DomainSearch::FREE : DomainSearch::TAKEN)) {
            return null;
        }

        return match (true) {
            $status === DomainSearch::PREMIUM => __('domains.shop.premium'),
            $status === DomainSearch::RESTRICTED => __('domains.shop.restricted'),
            $status === DomainSearch::UNKNOWN => __('domains.shop.unknown'),
            $operation === DomainOrderLine::REGISTER => __('domains.order.taken', ['domain' => $fqdn]),
            default => __('domains.order.not_registered', ['domain' => $fqdn]),
        };
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
        // Offered only (DomainTld::offeredYears): never a registration or renewal year at 0.00 (ADV-A5-1), a registration
        // only for years it renews for, a transfer only where a renewal is offered (review D1).
        return $tld->offeredYears($operation, $currency)
            ->reject(fn (int $y) => $operation === DomainOrderLine::TRANSFER && $y !== 1)->sort()->values();
    }
}

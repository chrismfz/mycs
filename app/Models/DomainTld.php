<?php

namespace App\Models;

use App\Contracts\StaffPricedNames;
use App\Exceptions\DisplayException;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A TLD MyCS sells (docs/domains.md D-7, WHMCS «Domain Pricing»): its registrar for automatic registration, EPP code,
 * addons, grace and redemption days. Its prices per operation, years and currency are DomainTldPrice rows.
 *
 * @property int $id
 * @property string $tld without the dot (com, gr, com.gr)
 * @property int|null $registrar_connection_id
 * @property int|null $grace_days
 * @property int|null $redemption_days
 * @property bool $is_active
 * @property DomainRegistrarConnection|null $registrarConnection
 */
class DomainTld extends Model implements Auditable
{
    use Traits\Auditable;

    public const OPERATIONS = ['register', 'renew', 'transfer', 'grace', 'redemption'];

    /** Its domains are every account's, and its connection holds credentials: neither in a JSON or a template (review A4). */
    protected $hidden = ['domains', 'registrarConnection'];

    public const TEMPLATE_HIDDEN = ['domains', 'registrarConnection'];

    protected $fillable = [
        'tld', 'registrar_connection_id', 'epp_code_required', 'dns_management', 'email_forwarding', 'id_protection',
        'allow_idn', 'grace_days', 'redemption_days', 'is_active', 'sort',
    ];

    protected static function booted(): void
    {
        // A TLD with domains is kept (D-18); one without them goes with its prices.
        // One form of a TLD: lowercase ASCII without the dot (com, gr, xn--qxam for .ελ), as Domain stores its tld.
        static::saving(function (self $tld) {
            $tld->tld = Domain::normalise(ltrim((string) $tld->tld, '. '));
        });
        static::deleting(function (self $tld) {
            if ($tld->domains()->exists()) {
                throw new DisplayException(__('domains.tld_in_use', ['tld' => $tld->tld]));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'epp_code_required' => 'boolean',
            'dns_management' => 'boolean',
            'email_forwarding' => 'boolean',
            'id_protection' => 'boolean',
            'allow_idn' => 'boolean',
            'grace_days' => 'integer',
            'redemption_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<DomainRegistrarConnection, $this> */
    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class);
    }

    /** @return HasMany<DomainTldPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(DomainTldPrice::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** The shortest period $operation is priced for in $currency (1 for most; 2 for .gr: even years), null when none. */
    public function shortestYears(string $operation, string $currency): ?int
    {
        $years = $this->offeredYears($operation, $currency)->filter(fn (int $y) => $y >= 1)->min();

        return $years === null ? null : (int) $years;
    }

    /**
     * The years $operation is offered for in $currency (DomainTldPrice::offered: never a registration or renewal year at
     * 0.00, ADV-A5-1), unordered: the one rule of what is sold (priceFor, the shop, the price list follow it, review D2).
     * A registration only for years its renewal is offered for too: it renews for the years it was registered for, and
     * one with no renew price is never billed and lapses (review of ADV-A5-1). A transfer only when a renewal is offered
     * at all (review D1): it adds its registrar's year (.gr's registry its own, D-5) and renews for a period its TLD
     * renews for (two for .gr: never «the transfer's year» there); with none it would lapse unbilled.
     *
     * @return Collection<int, int>
     */
    public function offeredYears(string $operation, string $currency): Collection
    {
        $years = $this->loadMissing('prices')->prices->where('operation', $operation)->where('currency_code', $currency)
            ->filter(fn (DomainTldPrice $row) => $row->isOffered())->pluck('years')->map(fn ($y) => (int) $y)->unique()->values();

        return match ($operation) {
            'register' => $years->intersect($this->offeredYears('renew', $currency))->values(),
            'transfer' => $this->offeredYears('renew', $currency)->isEmpty() ? collect() : $years,
            default => $years,
        };
    }

    /**
     * Whether its registrar can do $operation (register, transfer) through its API now: a TLD at the .gr registry before
     * G5 cannot, so it is not sold (review 1 of #191). No connection, or one without an API: staff do it, as before.
     */
    /** A name of this TLD its registrar never does at the TLD's price (.gr: 2 characters): never sold here. */
    public function staffPriced(string $fqdn): bool
    {
        if (GrEppRegistrar::twoCharacters($fqdn)) {
            return true; // by its name, whatever its registrar (F4 of #202)
        }
        try {
            $connection = $this->registrarConnection;
            $registrar = $connection !== null ? app(DomainRegistrarFactory::class)->for($connection) : null;

            return $registrar instanceof StaffPricedNames && $registrar->staffPriced($fqdn);
        } catch (\Throwable) {
            return false;
        }
    }

    public function registrarCan(string $operation): bool
    {
        $connection = $this->registrarConnection;
        if ($connection === null || !$connection->isUsable()) {
            return true;
        }
        try {
            $capabilities = app(DomainRegistrarFactory::class)->for($connection)->capabilities();
        } catch (\Throwable) {
            return false;
        }

        return $operation === 'transfer' ? $capabilities->supportsTransfer : $capabilities->supportsRegistration;
    }

    /**
     * The price of an operation for so many years in a currency: null when it is not offered (no row, no price, a
     * registration or renewal at 0.00: ADV-A5-1, or a year offeredYears does not sell: review D2), a string amount
     * otherwise, «0.00» being free and offered (D-6).
     */
    public function priceFor(string $operation, int $years, string $currency): ?string
    {
        // Only a year offeredYears sells (review D2): never a price the checkout then refuses.
        if (!$this->offeredYears($operation, $currency)->contains($years)) {
            return null;
        }
        $price = $this->prices->first(fn (DomainTldPrice $row) => $row->operation === $operation && $row->years === $years
            && $row->currency_code === $currency);

        return $price?->price;
    }
}

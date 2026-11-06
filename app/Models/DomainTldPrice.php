<?php

namespace App\Models;

use App\Services\Domains\DomainSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One price of a TLD (docs/domains.md D-5, D-6, D-8): an operation (register, renew, transfer, grace, redemption) for so
 * many years in a currency of MyCS. `price` null = not offered, 0 = free and offered (a transfer, a grace or redemption
 * fee: never a registration or renewal, offered()). `cost` is the registrar's, in its own currency: the pricing sync
 * writes only that.
 *
 * @property int $id
 * @property int $domain_tld_id
 * @property string $operation
 * @property int $years
 * @property string $currency_code
 * @property string|null $price
 * @property string|null $cost
 * @property string|null $cost_currency
 */
class DomainTldPrice extends Model implements Auditable
{
    protected static function booted(): void
    {
        // The public price lists are cached (D8a review R12): a price changed shows at once.
        static::saved(fn () => DomainSearch::forgetPriceLists());
        static::deleted(fn () => DomainSearch::forgetPriceLists());
    }

    use Traits\Auditable;

    protected $fillable = ['domain_tld_id', 'operation', 'years', 'currency_code', 'price', 'cost', 'cost_currency'];

    protected function casts(): array
    {
        return [
            'years' => 'integer',
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * Whether a price is one to sell at (ADV-A5-1): none is not offered, and only a transfer or a grace or redemption fee
     * may be free (D-6). A registration or renewal year at 0.00 is one WHMCS never sold (it keeps 0.00 in a year column
     * it does not offer, ADV-655-1): never a free registration or renewal its registrar still charges for.
     */
    public static function offered(string $operation, mixed $price): bool
    {
        if ($price === null || !is_numeric($price)) {
            return false;
        }
        $compare = bccomp((string) $price, '0', 2);

        return $compare > 0 || ($compare === 0 && !in_array($operation, ['register', 'renew'], true));
    }

    public function isOffered(): bool
    {
        return self::offered($this->operation, $this->price);
    }

    /** @return BelongsTo<DomainTld, $this> */
    public function domainTld(): BelongsTo
    {
        return $this->belongsTo(DomainTld::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}

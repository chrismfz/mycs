<?php

namespace App\Models;

use App\Observers\ClientGroupObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer group (ROADMAP 5, as WHMCS's): a name and colour for staff, and a percentage off its members' services,
 * taken in their price after a coupon (OrderPricing::line). Never on domains.
 *
 * @property int $id
 * @property string $name
 * @property string|null $colour
 * @property string $discount_percent
 */
#[ObservedBy([ClientGroupObserver::class])]
class ClientGroup extends Model
{
    protected $fillable = ['name', 'colour', 'discount_percent'];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2'];
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** Its discount for pricing: null when none. */
    public function percent(): ?string
    {
        return (float) $this->discount_percent > 0 ? (string) $this->discount_percent : null;
    }
}

<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use App\Observers\ClientGroupObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

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
class ClientGroup extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['name', 'colour', 'discount_percent', 'separate_invoices', 'dunning_exempt', 'no_overdue_reminders'];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'separate_invoices' => 'boolean', 'dunning_exempt' => 'boolean', 'no_overdue_reminders' => 'boolean'];
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** A percentage from 0 to 100, however it is saved (the form, the API, an import; review F4 of #272). */
    protected static function booted(): void
    {
        static::saving(function (ClientGroup $group) {
            if (!is_numeric($group->discount_percent) || (float) $group->discount_percent < 0 || (float) $group->discount_percent > 100) {
                throw new DisplayException(__('A group discount is a percentage from 0 to 100.'));
            }
        });
    }

    /** Its discount for pricing: null when none. */
    public function percent(): ?string
    {
        return (float) $this->discount_percent > 0 ? number_format((float) $this->discount_percent, 2, '.', '') : null;
    }
}

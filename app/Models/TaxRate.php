<?php

namespace App\Models;

use App\Events\Service\RenewalTaxChanged;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class TaxRate extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = ['name', 'rate', 'country', 'whmcs_rate'];

    protected static function booted(): void
    {
        // A rate somebody changed is theirs: it stops being «the one WHMCS charged», so no seed brings it back to the list,
        // even if it is set to that value again later (ADV-562 review). The import and the seed set the mark themselves.
        static::saving(function (TaxRate $rate) {
            if ($rate->exists && $rate->isDirty('rate') && !$rate->isDirty('whmcs_rate')) {
                $rate->whmcs_rate = null;
            }
        });
        // A rate added, changed or removed changes what renewals charge, so the gateway subscriptions that bill by
        // themselves (Stripe, PayPal) are repriced (sweep TAX-REPORTS-1). Which accounts a rate reaches depends on the
        // «all» rule and the home-country fallback (Settings::tax), so all of them are.
        static::created(fn () => RenewalTaxChanged::forEverySubscribed());
        static::updated(function (TaxRate $rate) {
            if ($rate->wasChanged(['rate', 'country'])) {
                RenewalTaxChanged::forEverySubscribed();
            }
        });
        static::deleted(fn () => RenewalTaxChanged::forEverySubscribed());
    }
}

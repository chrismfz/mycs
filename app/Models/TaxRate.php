<?php

namespace App\Models;

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
    }
}

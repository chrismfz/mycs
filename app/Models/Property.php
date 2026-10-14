<?php

namespace App\Models;

use App\Observers\PropertyObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([PropertyObserver::class])]
class Property extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public $guarded = [];

    /** @return BelongsTo<CustomProperty, $this> */
    public function parent_property(): BelongsTo
    {
        return $this->belongsTo(CustomProperty::class, 'custom_property_id');
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The checkout's «domain» (the server extensions' field) is the service's domain too (services.domain, WHMCS's
     * tblhosting.domain): listed and searched; a domain staff set on the service is kept.
     */
    protected static function booted(): void
    {
        static::saved(function (self $property) {
            if ($property->key === 'domain' && $property->model_type === Service::class && filled($property->value)) {
                Service::query()->whereKey($property->model_id)->whereNull('domain')->update(['domain' => mb_substr((string) $property->value, 0, 255)]);
            }
        });
    }
}

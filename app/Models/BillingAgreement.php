<?php

namespace App\Models;

use App\Models\Traits\HasProperties;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class BillingAgreement extends Model implements Auditable
{
    use HasProperties, HasUlids, SoftDeletes, Traits\Auditable;

    protected $fillable = [
        'ulid',
        'user_id',
        'gateway_id',
        'name', // e.g. Visa **** 4242
        'external_reference', // e.g. Stripe card id, PayPal billing agreement id
        'type', // e.g. card type
        'expiry',
    ];

    public function uniqueIds()
    {
        return [
            'ulid',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Gateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'billing_agreement_id');
    }

    /**
     * Removed (soft-deleted: the customer took the method off): no service charges it any more (DATA-9). The foreign key
     * only acts on a hard delete, so the services kept it and the cron failed charging a method that was gone.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $agreement) {
            Service::query()->where('billing_agreement_id', $agreement->getKey())->update(['billing_agreement_id' => null]);
        });
    }
}

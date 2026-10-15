<?php

namespace App\Models;

use App\Models\Traits\HasProperties;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $client_id
 */
class BillingAgreement extends Model implements Auditable
{
    use HasProperties, HasUlids, SoftDeletes, Traits\Auditable;

    protected $fillable = [
        'ulid',
        'client_id',
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

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Gateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    /**
     * Removed (soft-deleted: the customer took the method off): no renewal charges it any more (DATA-9). The foreign key
     * only acts on a hard delete. The account is left with no default rather than another card it did not choose; the next
     * method it adds becomes it.
     */
    protected static function booted(): void
    {
        // The first method of an account is its default, as in WHMCS: renewals are charged to it.
        static::created(function (self $agreement) {
            Client::query()->whereKey($agreement->client_id)->whereNull('default_billing_agreement_id')
                ->update(['default_billing_agreement_id' => $agreement->getKey()]);
        });
        static::deleting(function (self $agreement) {
            Client::query()->whereKey($agreement->client_id)->where('default_billing_agreement_id', $agreement->getKey())
                ->update(['default_billing_agreement_id' => null]);
        });
    }
}

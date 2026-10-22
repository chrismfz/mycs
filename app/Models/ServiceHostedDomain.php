<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A domain an account hosts, as its server last told it (services:usage).
 *
 * @property int $service_id
 * @property string $domain
 * @property string $type main, addon, parked or sub
 * @property string|null $account
 * @property Carbon $read_at
 */
class ServiceHostedDomain extends Model
{
    public const TYPES = ['main', 'addon', 'parked', 'sub'];

    protected $fillable = ['service_id', 'domain', 'type', 'account', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}

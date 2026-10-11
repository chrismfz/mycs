<?php

namespace App\Models;

use App\Observers\ServiceCancellationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceCancellationObserver::class])]
class ServiceCancellation extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'service_id',
        'reason',
        'type',
    ];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}

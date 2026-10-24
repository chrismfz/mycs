<?php

namespace App\Models;

use App\Observers\ServiceCancellationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Not handled yet: its service still runs (active, suspended or not yet set up). One at the end of the period ends
     * with the cron on its due date; one without a due date, or an immediate one that did not go through, waits for staff.
     *
     * @param  Builder<ServiceCancellation>  $query
     * @return Builder<ServiceCancellation>
     */
    public function scopePending(Builder $query): Builder
    {
        return self::wherePending($query);
    }

    /**
     * Of the pending ones, those due to end by the end of the day $days days from now: the cron's own rule (an end-of-period
     * request on an active or suspended service, CronJob::processCancellations), or past due already (an immediate one
     * still pending: it did not go through). Not a service still pending set-up: the cron does not end it, staff do.
     *
     * @param  Builder<ServiceCancellation>  $query
     * @return Builder<ServiceCancellation>
     */
    public function scopeEndingWithin(Builder $query, int $days): Builder
    {
        return self::wherePending($query)->where(fn (Builder $query) => $query
            ->where('type', 'immediate')
            ->orWhere(fn (Builder $query) => $query->where('type', 'end_of_period')->whereHas('service', fn (Builder $query) => $query
                ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
                ->where('expires_at', '<=', now()->addDays($days)->endOfDay()))));
    }

    /**
     * The rule itself, for a query that is not typed as the requests' (a table filter's).
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function wherePending(Builder $query): Builder
    {
        return $query->whereHas('service', fn (Builder $query) => $query->whereIn('status', Service::PAYING_STATUSES));
    }
}

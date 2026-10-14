<?php

namespace App\Events\Service;

use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * What a renewal of the service charges with tax changed without its price changing (Δ1): its account became tax
 * exempt or not, its country changed, or its product's «taxable» changed. A gateway subscription that bills it by
 * itself (Stripe, PayPal) updates its amount, as the renewal invoices now charge the new one.
 */
class RenewalTaxChanged
{
    use Dispatchable;

    public function __construct(public Service $service) {}

    /**
     * Fire it, after commit, for the services with a gateway subscription among these.
     *
     * @param  Builder<Service>  $services
     */
    public static function forSubscribed(Builder $services): void
    {
        $ids = (clone $services)->whereNotNull('subscription_id')->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        DB::afterCommit(function () use ($ids) {
            foreach (Service::query()->whereKey($ids)->get() as $service) {
                event(new self($service));
            }
        });
    }
}

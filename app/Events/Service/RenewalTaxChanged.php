<?php

namespace App\Events\Service;

use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * What a renewal of the service charges with tax changed without its price changing (Δ1): its account became tax
 * exempt or not, its country or business/individual type changed, its product's «taxable» changed, or a tax rate or
 * tax setting changed (sweep TAX-REPORTS-1). A gateway subscription that bills it by
 * itself (Stripe, PayPal) updates its amount, as the renewal invoices now charge the new one.
 */
class RenewalTaxChanged
{
    use Dispatchable;

    /** Scoped (SettingsProvider): reset for every request and queued job. */
    public const EVERY_DUE = 'renewal-tax.every-due';

    public function __construct(public Service $service) {}

    /**
     * Fire it, after commit, for the services with a gateway subscription among these.
     *
     * @param  Builder<Service>  $services
     */
    public static function forSubscribed(Builder $services): void
    {
        $ids = self::subscribed($services);
        if ($ids === []) {
            return;
        }
        DB::afterCommit(fn () => self::fire($ids));
    }

    /**
     * Every subscribed service, once per commit however many rates or settings it changed (a seed of the EU rates
     * saves dozens): what any account pays may have changed. The listeners price with the committed rates: the
     * per-request rate cache is emptied for them, then given back, so the rest of this request or cron run keeps
     * the rates it started with (SettingsTaxRateAdversarialTest). A rolled-back save leaves the mark, and the next
     * committed one fires.
     */
    public static function forEverySubscribed(): void
    {
        $due = app(self::EVERY_DUE);
        $due['due'] = true;
        DB::afterCommit(function () use ($due) {
            if (empty($due['due'])) {
                return;
            }
            $due['due'] = false;
            $ids = self::subscribed(Service::query());
            if ($ids === []) {
                return;
            }
            $rates = app('settings.tax-rates');
            $kept = $rates->getArrayCopy();
            $rates->exchangeArray([]);
            try {
                self::fire($ids);
            } finally {
                $rates->exchangeArray($kept);
            }
        });
    }

    /**
     * @param  Builder<Service>  $services
     * @return list<int>
     */
    private static function subscribed(Builder $services): array
    {
        return (clone $services)->whereNotNull('subscription_id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @param  list<int>  $ids */
    private static function fire(array $ids): void
    {
        foreach (Service::query()->whereKey($ids)->get() as $service) {
            event(new self($service));
        }
    }
}

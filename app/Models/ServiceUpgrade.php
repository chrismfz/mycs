<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\ServiceUpgradeObserver;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use MyCS\Pricing\LinePricer;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy([ServiceUpgradeObserver::class])]
class ServiceUpgrade extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $guarded = [];

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return MorphMany<ServiceConfig, $this> */
    public function configs(): MorphMany
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    /**
     * What the upgrade charges now, net (Δ1), or gives back when negative (SVC-14, as WHMCS): the service's new recurring
     * amount less the one it has (`services.price`: a staff price or a coupon's counts), times its quantity, for the days
     * left to its expiry, of its real period (a year of 365 or 366 days; more than one period when a renewal was paid
     * ahead). One whose period ended pays nothing now (its next renewal charges the new amount). A service without an
     * expiry (one-time, free) pays the whole difference of an upgrade and gets nothing back for a downgrade (it was not
     * bought by the day).
     */
    public function calculatePrice(): Price
    {
        $service = $this->service;
        $difference = BigDecimal::of($this->newRecurringPrice())
            ->minus(number_format((float) $service->price, 2, '.', ''))
            ->multipliedBy(max(1, (int) $service->quantity));

        $start = $this->service->currentPeriodStart();
        if ($start === null) {
            return $this->makePrice((float) (string) BigDecimal::max($difference, BigDecimal::zero())->toScale(2, RoundingMode::HalfUp));
        }

        return $this->makePrice((float) $service->remainingOf((string) $difference, $start, Carbon::now()));
    }

    /**
     * What the upgrade charges (or gives back, when negative) with tax: what the upgrade page shows and what a downgrade
     * gives as credit.
     *
     * - An upgrade: its invoice line, the net difference with the new product's tax (Δ1, F2 of the Σ7b review).
     * - A downgrade (decided 2026-10-14, F6 of the SVC-14 review): each side with its own tax, the service as it renews
     *   now (with the old product's) less as it renews after (with the new one's), for the days left. Never more than
     *   what was paid for those days: from a product sold without tax to a taxed one, 10.00 − 2.48, not 8.00 × 1.24.
     */
    public function grossPrice(): Price
    {
        $net = $this->calculatePrice();
        if ($net->price >= 0) {
            $line = LinePricer::invoiceLine(number_format((float) $net->price, 2, '.', ''), 1,
                OrderPricing::taxRate($this->service->client, $this->product ?? $this->service->product));

            return $this->makePrice((float) $line['total']);
        }

        $service = $this->service;
        $quantity = max(1, (int) $service->quantity);
        $before = LinePricer::invoiceLine(number_format((float) $service->price, 2, '.', ''), $quantity,
            OrderPricing::taxRate($service->client, $service->product))['total'];
        $after = LinePricer::invoiceLine($this->newRecurringPrice(), $quantity,
            OrderPricing::taxRate($service->client, $this->product ?? $service->product))['total'];
        $start = $this->service->currentPeriodStart();
        $difference = (string) BigDecimal::of($after)->minus($before);

        // A downgrade has an expiry (one without is never negative, calculatePrice).
        return $this->makePrice($start === null ? 0.0
            : (float) $service->remainingOf($difference, $start, Carbon::now()));
    }

    /**
     * The options the service has once the upgrade is applied, [config_option_id => config_value_id]
     * (`ServiceUpgradeService`): all it has, less, on a product change, those the new product does not link (unless the
     * upgrade sets them), with the upgrade's values over them.
     *
     * @return array<int, int|null>
     */
    public function resultingOptions(): array
    {
        $service = $this->service;
        $options = $service->configs->pluck('config_value_id', 'config_option_id')->all();
        if ((int) $service->product_id !== (int) $this->product_id) {
            $keep = ConfigOptionProduct::query()->where('product_id', $this->product_id)->pluck('config_option_id')
                ->merge($this->configs->pluck('config_option_id'))->unique()->all();
            $options = array_intersect_key($options, array_flip($keep));
        }
        foreach ($this->configs as $config) {
            $options[$config->config_option_id] = $config->config_value_id;
        }

        return $options;
    }

    /**
     * The plan the upgrade moves to: its own, or (an upgrade being priced) the service's own when the product stays, else
     * the new product's for the service's cycle, priced in the service's currency (F1 of the review: one priced only in
     * another currency read as free).
     */
    public function targetPlan(): ?Plan
    {
        if ($this->plan !== null) {
            return $this->plan;
        }
        $service = $this->service;
        $current = $service->plan;
        if ($current === null || $this->product === null) {
            return null;
        }
        if ((int) $this->product->id === (int) $service->product_id) {
            return $current;
        }

        return $this->product->availablePlans($service->currency_code)
            ->where('billing_period', $current->billing_period)->where('billing_unit', $current->billing_unit)->first();
    }

    /** Only options change: the same product and plan (WHMCS «configoptions» upgrade). */
    public function changesOptionsOnly(): bool
    {
        $service = $this->service;

        return (int) ($this->product_id ?? $this->product?->id) === (int) $service->product_id
            && (int) $this->targetPlan()?->id === (int) $service->plan_id;
    }

    /**
     * The net recurring price per unit the service has after the upgrade, what `ServiceUpgradeService` stores.
     *
     * - Options only: the price it has, plus what the changed options cost more or less (as WHMCS: its staff price
     *   stays, and an option that costs more never lowers it; F2 of the review).
     * - Another product or plan: its catalog price with the options it keeps and those the upgrade sets, and its coupon
     *   where that covers the new product, for the coupon cycle its price is in now (a coupon's last discounted cycle is
     *   not charged back: F3, code review 1). A staff price is not carried over (decided 2026-10-14).
     */
    public function newRecurringPrice(): string
    {
        $service = $this->service;
        if ($this->changesOptionsOnly()) {
            $change = BigDecimal::of($this->optionsPrice($this->resultingOptions()))
                ->minus($this->optionsPrice($service->configs->pluck('config_value_id', 'config_option_id')->all()));
            $net = OrderPricing::toNet((string) $change, $service->client, $service->product);

            return (string) BigDecimal::of(number_format((float) $service->price, 2, '.', ''))->plus($net)->toScale(2);
        }

        $after = $service->replicate();
        $after->id = $service->id;
        $after->exists = true;
        $after->product_id = $this->product_id ?? $this->product->id ?? $service->product_id;
        $after->setRelation('product', $this->product ?? $service->product);
        $plan = $this->targetPlan() ?? $service->plan;
        $after->plan_id = $plan?->id;
        $after->setRelation('plan', $plan);
        $after->setRelation('configs', collect($this->resultingOptions())->map(fn ($value, $option) => new ServiceConfig([
            'config_option_id' => $option,
            'config_value_id' => $value,
        ]))->values());

        return (string) $after->calculatePrice(cycle: $service->priceCycle());
    }

    /**
     * What a set of option values costs for the service's cycle and currency, catalog amounts.
     *
     * @param  array<int, int|null>  $options
     */
    protected function optionsPrice(array $options): string
    {
        $service = $this->service;
        $plan = $service->plan;
        $values = ConfigOption::query()->whereIn('id', array_filter($options))->get();
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(number_format((float) $value->price(null, $plan->billing_period, $plan->billing_unit, $service->currency_code)->price, 2, '.', ''));
        }

        return (string) $total;
    }

    protected function makePrice(float $amount = 0): Price
    {
        return new Price([
            'price' => $amount,
            'currency' => $this->service->currency,
        ]);
    }
}

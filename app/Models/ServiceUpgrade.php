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

/**
 * @property int|null $credit_cents what a downgrade gave back as credit (DowngradeCredit)
 * @property \Illuminate\Support\Carbon|null $credit_until the end of the days it was given for
 */
#[ObservedBy([ServiceUpgradeObserver::class])]
class ServiceUpgrade extends Model implements Auditable
{
    /**
     * Its invoice's due date: within the week, but never after the service's expiry (sweep 2026-10-24). Past the expiry
     * the cron gives the upgrade up (its price is for the days left), so a later due date told the customer to pay
     * what would be cancelled before then.
     */
    public static function invoiceDueAt(Service $service): \Illuminate\Support\Carbon
    {
        $week = \Illuminate\Support\Carbon::now()->addDays(7);
        if ($service->expires_at === null) {
            return $week;
        }
        $expiry = \Illuminate\Support\Carbon::parse($service->expires_at)->startOfDay();

        return $expiry->lt($week) ? $expiry->max(\Illuminate\Support\Carbon::today()) : $week;
    }

    use HasFactory, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // A downgrade's credit and the day it was given for (DowngradeCredit).
        return ['credit_cents' => 'integer', 'credit_until' => 'date'];
    }

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
     * What the days left of the period cost on the new plan, with tax: the customer keeps the service for them, so a
     * downgrade gives back what was paid less this (DowngradeCredit, G1 of #326).
     */
    public function newPriceForDaysLeft(): string
    {
        $service = $this->service;
        $start = $service->currentPeriodStart();
        if ($start === null) {
            return '0.00';
        }
        $after = LinePricer::invoiceLine($this->newRecurringPrice(), max(1, (int) $service->quantity),
            OrderPricing::taxRate($service->client, $this->product ?? $service->product))['total'];

        return (string) BigDecimal::max(BigDecimal::of($service->remainingOf($after, $start, Carbon::now())), BigDecimal::zero())->toScale(2);
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
     *   stays, and an option that costs more never lowers it; F2 of the review). Never below zero: then as another plan.
     * - Another product or plan: its catalog price with the options it keeps and those the upgrade sets, and its coupon
     *   where that covers the new product, for the coupon cycle its price is in now (a coupon's last discounted cycle is
     *   not charged back: F3, code review 1). A staff price is not carried over (decided 2026-10-14).
     */
    public function newRecurringPrice(): string
    {
        $service = $this->service;
        if ($this->changesOptionsOnly()) {
            $kept = $this->keptPrice();
            // Never zero or below (sweep 9γ, owner 2026-10-30, as Blesta; exactly zero too, ADV-551-2): a price under the
            // catalog's (staff's, or one imported from WHMCS) less an option's catalog price can be; then it is the
            // catalog's with the options it keeps, as a change of plan prices it.
            if ($kept->isGreaterThan(0)) {
                return (string) $kept->toScale(2);
            }
            // A plan without a price in the service's currency would read as free (ADV-551-1, as F1 of SVC-14): the
            // price it has, and the change is refused (needsStaff).
            if (!$this->catalogPricesIt()) {
                return number_format((float) $service->price, 2, '.', '');
            }
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

        // A decimal string already (LinePricer never prices below zero): no float on the way (code review of #551).
        return (string) BigDecimal::max(BigDecimal::of((string) $after->calculatePrice(cycle: $service->priceCycle())), BigDecimal::zero())
            ->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * An options-only change whose kept price would be below zero: priced from the catalog instead (newRecurringPrice).
     * Its staff price is gone, so its lock goes too (ServiceUpgradeService; code review 3 of #551).
     */
    public function repricesFromCatalog(): bool
    {
        if (!$this->changesOptionsOnly()) {
            return false;
        }

        return $this->catalogPricesIt() && $this->keptPrice()->isLessThanOrEqualTo(0);
    }

    /**
     * A change the catalog cannot price in the service's currency, refused on the customer's page and, once paid, not
     * applied but left to staff (ServiceUpgradeService): an option value it sets priced only in other currencies
     * (ADV-551-R2-1); a new product's plan without the currency (ADV-551-R3-1); an options-only change falling back to
     * a plan without it (ADV-551-1).
     */
    public function needsStaff(): bool
    {
        if (!$this->chosenValuesPriced()) {
            return true;
        }
        if (!$this->changesOptionsOnly()) {
            return !$this->catalogPricesIt();
        }

        return !$this->catalogPricesIt() && $this->keptPrice()->isLessThanOrEqualTo(0);
    }

    /** An options-only change's price: the one it has plus the options' difference (newRecurringPrice). */
    private function keptPrice(): BigDecimal
    {
        return BigDecimal::of(number_format((float) $this->service->price, 2, '.', ''))->plus($this->optionsDifference());
    }

    /**
     * Every option value the upgrade sets has a price in the service's currency for its cycle, or is free: a value
     * priced for that cycle only in other currencies is not.
     */
    private function chosenValuesPriced(): bool
    {
        $service = $this->service;
        $plan = $this->targetPlan() ?? $service->plan;
        if ($plan === null) {
            return true;
        }
        $values = ConfigOption::query()->whereIn('id', $this->configs->pluck('config_value_id')->filter()->all())->with('plans.prices')->get();
        foreach ($values as $value) {
            $forCycle = $value->plans->filter(fn (Plan $valuePlan) => $valuePlan->type === 'free'
                || ((int) $valuePlan->billing_period === (int) $plan->billing_period && $valuePlan->billing_unit === $plan->billing_unit));
            if ($forCycle->isEmpty() || $forCycle->contains(fn (Plan $valuePlan) => $valuePlan->type === 'free')) {
                continue; // not priced for the cycle at all: free in every currency, as before
            }
            if (!$forCycle->contains(fn (Plan $valuePlan) => $valuePlan->prices->contains('currency_code', $service->currency_code))) {
                return false;
            }
        }

        return true;
    }

    /** The plan it ends on has a price in the service's currency. */
    private function catalogPricesIt(): bool
    {
        $plan = $this->targetPlan() ?? $this->service->plan;

        // A free plan has no price row: it prices at 0.00 (Plan::price), not unpriced (ADV-551-R3-2).
        return $plan !== null && ($plan->type === 'free' || $plan->prices()->where('currency_code', $this->service->currency_code)->exists());
    }

    /**
     * What the changed options cost more (or less), net, at the group discount its price holds (G1/F1 of #272): an
     * option added is not full price for a group's member; a price staff locked follows no group.
     */
    protected function optionsDifference(): BigDecimal
    {
        $service = $this->service;
        $change = BigDecimal::of($this->optionsPrice($this->resultingOptions()))
            ->minus($this->optionsPrice($service->configs->pluck('config_value_id', 'config_option_id')->all()));
        $net = BigDecimal::of(OrderPricing::toNet((string) $change, $service->client, $service->product));
        if (!$service->price_locked && (float) $service->group_percent > 0) {
            $net = $net->multipliedBy(BigDecimal::of('100')->minus(number_format((float) $service->group_percent, 2, '.', '')))
                ->dividedBy(100, 2, RoundingMode::HalfUp);
        }

        return $net;
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

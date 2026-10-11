<?php

namespace App\Models;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Traits\HasProperties;
use App\Observers\ServiceObserver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use MyCS\BillingPeriod\BillingPeriod;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $formatted_price
 * @property-read mixed $formattedPrice
 * @property-read mixed $label
 * @property-read mixed $base_label
 * @property-read mixed $baseLabel
 * @property-read mixed $description
 * @property-read mixed $cancellable
 * @property-read mixed $upgradable
 */
#[ObservedBy([ServiceObserver::class])]
class Service extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'order_id',
        'product_id',
        'plan_id',
        'quantity',
        'price',
        'expires_at',
        'subscription_id',
        'status',
        'coupon_id',
        'user_id',
        'currency_code',
        'billing_agreement_id',
        'suspend_hold_until',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'suspend_hold_until' => 'date',
    ];

    /**
     * Get the order that owns the service.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the coupon that owns the service.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get the currency corresponding to the service.
     *
     * @return HasOne<Currency, $this>
     */
    public function currency(): HasOne
    {
        return $this->hasOne(Currency::class, 'code', 'currency_code');
    }

    /**
     * Get the user that owns the service.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Price of the service.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price * $this->quantity, 'currency' => $this->currency])
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function label(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?: $this->baseLabel
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function baseLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product?->name . ' #' . $this->id
        );
    }

    /**
     * Get the description for the next invoice item (the next period to bill).
     *
     * @return Attribute<mixed, mixed>
     */
    public function description(): Attribute
    {
        if ($this->plan->type == 'free' || $this->plan->type == 'one-time') {
            return Attribute::make(
                get: fn () => $this->product->name
            );
        }

        return Attribute::make(
            get: function () {
                $start = $this->nextPeriodStart();

                return $this->describePeriod($start, $this->periodEnd($start));
            }
        );
    }

    /** Invoice line text for one billing period. */
    public function describePeriod(CarbonInterface $start, ?CarbonInterface $end): string
    {
        if ($end === null) {
            return $this->product->name;
        }

        $format = __('services.invoice_period_date_format');

        return __('services.invoice_period', [
            'product' => $this->product->name,
            'start' => $start->format($format),
            'end' => $end->format($format),
        ]);
    }

    /**
     * End of one period from the expiry (active or suspended service, decision A), or from today for
     * a service that has not started. Used by the Cart for a free first period; renewals of paid
     * lines go through RenewServiceService (docs/billing-cursor.md).
     */
    public function calculateNextDueDate()
    {
        if ($this->plan->type == 'one-time' || $this->plan->type == 'free') {
            return null;
        }
        if ($this->expires_at && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
            return $this->periodEnd($this->expires_at);
        }

        // A fresh start: the period starts today and today is the billing day.
        return BillingPeriod::end(now(), $this->plan->billing_unit, (int) $this->plan->billing_period);
    }

    /**
     * End of the billing period starting on $start, keeping the service's billing day.
     *
     * @param  Collection<int, InvoiceItem>|null  $lines  livePeriodLines(), if already loaded
     */
    public function periodEnd(CarbonInterface $start, ?Collection $lines = null): ?CarbonImmutable
    {
        if ($this->plan->type == 'one-time' || $this->plan->type == 'free') {
            return null;
        }

        return BillingPeriod::end($start, $this->plan->billing_unit, (int) $this->plan->billing_period, $this->billingDay($start, $lines));
    }

    /**
     * The billing day for a period starting on $start: its own day, unless $start is the last day of a
     * short month (the 28th/29th/30th a later day was clamped to). Then it is the day of the latest
     * earlier period that is not ambiguous, so a service on the 31st goes back to the 31st, and one
     * staff moved to another day follows that day.
     *
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function billingDay(CarbonInterface $start, ?Collection $lines = null): int
    {
        $start = CarbonImmutable::instance($start)->startOfDay();
        if (!BillingPeriod::isAmbiguousDay($start)) {
            return $start->day;
        }
        if ($this->billing_day !== null) {
            return max((int) $this->billing_day, $start->day);
        }

        // Ambiguous earlier days still say «at least this day» (Nov 30 -> Feb 28 -> May 30, not May 28).
        // Every period line counts here, a cancelled one too: it still shows the day the service billed on.
        $floor = $start->day;
        $history = $this->invoiceItems()->whereNotNull('period_start')->get(['id', 'period_start'])
            ->sortBy(fn (InvoiceItem $line) => $line->period_start->timestamp);
        foreach ($history->reverse() as $line) {
            $day = $line->period_start;
            if ($day === null || !$day->lessThan($start)) {
                continue;
            }
            if (!BillingPeriod::isAmbiguousDay($day)) {
                return max($day->day, $floor);
            }
            $floor = max($floor, $day->day);
        }

        // No history with a clear day (e.g. imported from WHMCS): the day the service started.
        $registered = $this->created_at ? CarbonImmutable::parse($this->created_at)->startOfDay() : null;
        if ($registered !== null && $registered->lessThan($start)) {
            $floor = max($floor, $registered->day);
        }

        return $floor;
    }

    /**
     * Remember the billing day when a period starts on an unambiguous day (not saved here).
     */
    public function rememberBillingDay(CarbonInterface $periodStart): void
    {
        if (!BillingPeriod::isAmbiguousDay(CarbonImmutable::instance($periodStart)) && (int) $this->billing_day !== $periodStart->day) {
            $this->billing_day = $periodStart->day;
        }
    }

    /**
     * Where the next period to bill starts: the end of the live (paid or pending) periods that follow the expiry.
     *
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function nextPeriodStart(?Collection $lines = null): CarbonImmutable
    {
        return self::chain($this->paidUntilBase(), $lines ?? $this->livePeriodLines());
    }

    /**
     * Paid-until over the paid periods that follow $from. $exact: a period must start exactly on the
     * cursor (staff moved the date: only periods that pick up there count); otherwise a period that
     * covers the cursor counts too (a payment).
     *
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function paidThrough(CarbonInterface $from, bool $exact = false, ?Collection $lines = null): CarbonImmutable
    {
        $paid = ($lines ?? $this->livePeriodLines())->filter(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID);

        return self::chain(CarbonImmutable::instance($from)->startOfDay(), $paid, $exact);
    }

    /**
     * Follow contiguous periods from $from: a period that covers (or, if $exact, starts on) the cursor
     * moves it to its end. Stops at the first hole, so an unpaid or cancelled period is never jumped.
     *
     * @param  iterable<InvoiceItem>  $lines
     */
    public static function chain(CarbonImmutable $from, iterable $lines, bool $exact = false): CarbonImmutable
    {
        $cursor = $from->startOfDay();
        do {
            $moved = false;
            foreach ($lines as $line) {
                $start = $line->period_start;
                $end = $line->period_end;
                if ($start === null || $end === null || !$end->greaterThan($cursor)) {
                    continue;
                }
                if ($exact ? $start->equalTo($cursor) : $start->lessThanOrEqualTo($cursor)) {
                    $cursor = $end;
                    $moved = true;
                }
            }
        } while ($moved);

        return $cursor;
    }

    /**
     * Which billing cycle (1 = the first) the period starting on $periodStart is, counted in periods
     * from the service's first period (or its registration). Free periods count too: they leave no
     * invoice, so invoices cannot be counted (coupons «for N cycles»).
     *
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function cycleNumber(CarbonInterface $periodStart, ?Collection $lines = null): int
    {
        $lines ??= $this->livePeriodLines();
        $cursor = $lines->first()->period_start
            ?? CarbonImmutable::parse($this->created_at ?? $periodStart)->startOfDay();
        $target = CarbonImmutable::instance($periodStart)->startOfDay();

        for ($cycle = 1; $cycle < 1200 && $cursor->lessThan($target); $cycle++) {
            $cursor = $this->periodEnd($cursor, $lines);
            if ($cursor === null) {
                return $cycle;
            }
        }

        return $cycle;
    }

    /** The paid-until date the chains start from (today for a service that never started). */
    public function paidUntilBase(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->expires_at ?? now())->startOfDay();
    }

    /**
     * This service's period lines on invoices that are not cancelled, oldest period first.
     * Dates are compared in PHP: SQLite stores the date casts with a time part.
     *
     * @return Collection<int, InvoiceItem>
     */
    public function livePeriodLines(): Collection
    {
        return $this->invoiceItems()
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_CANCELLED))
            ->with('invoice:id,status')
            ->get()
            ->sortBy(fn (InvoiceItem $line) => $line->period_start->timestamp)
            ->values();
    }

    /**
     * Get the product corresponding to the service.
     */
    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the plan corresponding to the service.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the service's configurations.
     *
     * @return MorphMany<ServiceConfig, $this>
     */
    public function configs(): MorphMany
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    /**
     * Get invoiceItems
     *
     * @return MorphMany<InvoiceItem, $this>
     */
    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * Get invoices
     *
     * @return HasManyThrough<Invoice, InvoiceItem, $this>
     */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'reference_id', 'id', 'id', 'invoice_id')->where('reference_type', Service::class);
    }

    /**
     * Get cancellation requests
     *
     * @return HasOne<ServiceCancellation, $this>
     */
    public function cancellation(): HasOne
    {
        return $this->hasOne(ServiceCancellation::class);
    }

    /** @return Attribute<mixed, mixed> */
    public function cancellable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status !== 'cancelled' && $this->plan->type != 'free' && $this->plan->type != 'one-time' && !$this->cancellation?->exists()
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function upgradable(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->productUpgrades()->count() > 0 || $this->product->upgradableConfigOptions()->count() > 0) && $this->status == 'active' && $this->upgrade->where('status', ServiceUpgrade::STATUS_PENDING)->count() == 0
        );
    }

    public function productUpgrades()
    {
        return $this->product->upgrades->filter(function ($product) {
            // Check stock
            if ($product->stock !== null && ($product->stock - $this->quantity) < 0) {
                return null;
            }
            $plan = $product->plans()->where('billing_unit', $this->plan->billing_unit)->where('billing_period', $this->plan->billing_period)->get();
            // Only get the upgrades that have the exact same billing cycle as the service
            if ($plan->count() > 0) {
                $product->plan = $plan->first();

                return $product;
            }

            return null;
        });
    }

    public function calculatePrice(bool $applyCoupon = true)
    {
        // Calculate the price based on the plan and config options
        $price = $this->plan->price($this->currency_code)->price;

        $this->configs->each(function ($config) use (&$price) {
            $configValue = $config->configValue;
            if ($configValue) {
                $price += $configValue->price(null, $this->plan->billing_period, $this->plan->billing_unit, $this->currency_code)->price;
            }
        });

        // Add coupon discount if applicable
        if ($applyCoupon && $this->coupon) {
            $invoices = $this->invoices()->where('status', 'paid')->count() + 1;
            // If it already used for the recurring period, do not apply the discount
            if ($this->coupon->recurring == 0 || $invoices <= $this->coupon->recurring) {
                $discount = $this->coupon->calculateDiscount($price);
                $price -= $discount;
            }
        }

        $price = (new Price([
            'price' => $price,
            'currency' => $this->currency,
        ], apply_exclusive_tax: true, tax: Settings::tax($this->user)))->price;

        return number_format($price, 2, '.', '');
    }

    /** @return HasMany<ServiceUpgrade, $this> */
    public function upgrade(): HasMany
    {
        return $this->hasMany(ServiceUpgrade::class);
    }

    /** @return BelongsTo<BillingAgreement, $this> */
    public function billingAgreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class, 'billing_agreement_id');
    }
}

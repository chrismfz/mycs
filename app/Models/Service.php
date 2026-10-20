<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Traits\HasProperties;
use App\Observers\ServiceObserver;
use App\Redactors\FullRedactor;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\LineTax;
use App\Services\Order\OrderPricing;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Brick\Math\BigDecimal;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use MyCS\BillingPeriod\BillingPeriod;
use MyCS\BillingPeriod\Prorata;
use MyCS\Pricing\LinePricer;
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
 * @property int $client_id
 */
#[ObservedBy([ServiceObserver::class])]
class Service extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    /** Ended because the customer asked (a cancellation request) or an unpaid order was given up on: WHMCS's «Cancelled». */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    /** Ended for non-payment by the cron, or by staff's «Terminate»: WHMCS's «Terminated». */
    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_TERMINATED, self::STATUS_CANCELLED];

    /** Over, whichever way it ended: never billed, renewed or revived. */
    public const ENDED_STATUSES = [self::STATUS_CANCELLED, self::STATUS_TERMINATED];

    /** What staff and customers read for a status: WHMCS's words, lang/*\/services.php. */
    public static function statusLabel(?string $status): string
    {
        $key = 'services.statuses.' . $status;

        return $status !== null && Lang::has($key) ? __($key) : ucfirst((string) $status);
    }

    /** @return array<string, string> status => label, for selects and filters */
    public static function statusOptions(): array
    {
        return array_combine(self::STATUSES, array_map(self::statusLabel(...), self::STATUSES));
    }

    /** The badge color of a status in the admin. */
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_SUSPENDED => 'warning',
            self::STATUS_PENDING, self::STATUS_CANCELLED => 'gray',
            default => 'danger',
        };
    }

    public function isEnded(): bool
    {
        return in_array($this->status, self::ENDED_STATUSES, true);
    }

    protected $fillable = [
        'order_id',
        'product_id',
        'plan_id',
        'quantity',
        'price',
        'price_locked',
        'expires_at',
        'subscription_id',
        'status',
        'coupon_id',
        'client_id',
        'contact_id',
        'currency_code',
        'suspend_hold_until',
        // WHMCS's tblhosting fields (2026_11_02 migration)
        'server_id',
        'domain',
        'username',
        'password',
        'dedicated_ip',
        'assigned_ips',
        'notes',
        'suspend_reason',
    ];

    /** The account password is never in a serialized service (API, logs); staff and the customer see it on their page. */
    protected $hidden = ['password'];

    /** Never readable from a template staff edit (emails, notifications, product snippets): staff-only data. */
    public const TEMPLATE_HIDDEN = ['password', 'notes'];

    /** Audited, never in clear (FullRedactor), as a secret setting. */
    protected $attributeModifiers = [
        'password' => FullRedactor::class,
    ];

    protected $casts = [
        'group_percent' => 'decimal:2',
        'price_locked' => 'boolean',
        'expires_at' => 'date',
        'activated_at' => 'datetime',
        'suspend_hold_until' => 'date',
        'started_unpaid_at' => 'datetime',
        'dunning_suspended_at' => 'immutable_datetime',
        'coupon_started_at' => 'immutable_date',
        'coupon_expired_at' => 'immutable_datetime',
        'password' => 'encrypted',
    ];

    /**
     * The contact of its account it concerns (decided 2026-10-14): information only, the invoice stays the account's.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The plan it moves to when its next period is billed (a change of cycle «from the next renewal», SVC-14b).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'next_plan_id');
    }

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
     * The account the service belongs to.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Price of the service.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            // With tax (Δ1: the price is net): what a renewal charges.
            get: fn () => new Price(['price' => $this->recurringGrossCents() / 100, 'currency' => $this->currency], tax: 0)
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
        // No plan (deleted before BIL-6, an import with none): no period to describe until staff set one.
        if ($this->plan === null || $this->plan->type == 'free' || $this->plan->type == 'one-time') {
            return Attribute::make(
                get: fn () => $this->product->name ?? ''
            );
        }

        return Attribute::make(
            get: function () {
                $start = $this->nextPeriodStart();

                return $this->describePeriod($start, $this->periodEnd($start));
            }
        );
    }

    /**
     * Its options for an invoice line (PR C, as WHMCS writes them in the line's description): «Option: Value», one a line.
     * Hidden ones (staff's, not on the order form) are left out, as their price is (modules sweep §3). Read as an
     * attribute: from a model, `->hidden` is Eloquent's own protected property.
     */
    public function describeOptions(): string
    {
        return $this->configs()->with(['configOption', 'configValue'])->get()
            ->filter(fn (ServiceConfig $config) => $config->configOption !== null && !$config->configOption->getAttribute('hidden') && $config->configValue !== null)
            // In the order the product shows them.
            ->sortBy(fn (ServiceConfig $config) => [$config->configOption->sort ?? PHP_INT_MAX, $config->configOption->id])
            ->map(fn (ServiceConfig $config) => $config->configOption->name . ': ' . $config->configValue->name)
            ->implode("\n");
    }

    /** A renewal line's text: the period, then the service's options (PR C, as WHMCS). */
    public function describeLine(CarbonInterface $start, ?CarbonInterface $end): string
    {
        return trim($this->describePeriod($start, $end) . "\n" . $this->describeOptions());
    }

    /** Invoice line text for one billing period. */
    public function describePeriod(CarbonInterface $start, ?CarbonInterface $end): string
    {
        if ($end === null) {
            return $this->product->name ?? '';
        }

        $format = __('services.invoice_period_date_format');

        return __('services.invoice_period', [
            'product' => $this->product->name ?? '',
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
        if ($this->plan === null || $this->plan->type == 'one-time' || $this->plan->type == 'free') {
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
        // A pro rata product's new service bills on its day (PR D), whatever day the first, partial period started on; one
        // that already has a day (it renewed before the product turned pro rata) keeps it.
        if ($this->billing_day === null && $this->product?->proratesPlan($this->plan)) {
            $this->billing_day = (int) $this->product->prorata_day;

            return;
        }
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

    /** Find a whole vacant period; a paid full cycle must never be clipped to a smaller hole.
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function uncoveredPeriodStart(?Collection $lines = null): CarbonImmutable
    {
        $lines ??= $this->livePeriodLines();
        $start = $this->nextPeriodStart($lines);
        foreach ($lines as $line) {
            $end = $this->periodEnd($start, $lines);
            if ($end !== null && $line->period_start->lessThan($end) && $line->period_end->greaterThan($start)) {
                $start = $line->period_end;
            }
        }

        return $start;
    }

    /** Decimal price for the actual interval, using the same rounding in cron and upgrades. */
    public function priceForPeriod(CarbonInterface $start, CarbonInterface $end): string
    {
        $fullEnd = $this->periodEnd($start);

        return $fullEnd === null ? (string) $this->price : BillingPeriod::prorate((string) $this->price, $start, $end, $fullEnd);
    }

    /**
     * Apply explicit staff changes on the same row lock as payment and billing. Only the fields that
     * differ from what the caller saw are written ($baseline: the raw attributes the admin form was
     * filled from; else this model as loaded): a payment, the cron or an upgrade may have changed the
     * row since, and a form that sends back every field must not revert them. Suspending (a real
     * status change) is a manual hold: a later payment does not lift it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $baseline
     */
    public function applyStaffChanges(array $attributes, ?array $baseline = null): void
    {
        $probe = (new self)->setRawAttributes($baseline ?? $this->getAttributes(), true);
        $probe->fill($attributes);
        $changes = $probe->getDirty();
        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($changes) {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            // The probe's dirty values are stored values (cast already: an encrypted password is its ciphertext): put
            // them as they are, not through the casts again (review R1/F1: the password was encrypted twice).
            $locked->setRawAttributes(array_merge($locked->getAttributes(), $changes));
            if (($changes['status'] ?? null) === self::STATUS_SUSPENDED) {
                $locked->dunning_suspended_at = null;
            }
            $locked->save();
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->unsetRelations();
        });
    }

    /**
     * Staff's explicit «suspend» (the server action): a manual hold, also on a service already
     * suspended for non-payment, so a payment no longer lifts it. Pending and cancelled services keep
     * their status.
     */
    public function placeOnHold(): void
    {
        DB::transaction(function () {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
                return;
            }
            $locked->status = self::STATUS_SUSPENDED;
            // A staff hold: not the cron's «Overdue on Payment» any more (review R5); staff write their own reason.
            if ($locked->dunning_suspended_at !== null) {
                $locked->suspend_reason = null;
            }
            $locked->dunning_suspended_at = null;
            $locked->save();
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->unsetRelations();
        });
    }

    /**
     * Paid until $paidUntil, would the cron suspend it again? Then a payment does not unsuspend it (Δ3a, sweep Π4.1):
     * unsuspended, the next run would suspend it again, with two mails and two server jobs. The cron's rule: suspension
     * on, no hold in force, no payment on its way, Suspend Days past the date, and with «suspend only with an overdue
     * invoice» an unpaid invoice of its own due Suspend Days ago. With nothing left to pay, the cron bills its next
     * period while it stays suspended (paid since its suspension, CronJob::billRenewal), so the customer can pay it.
     */
    public function stillOverdue(CarbonInterface $paidUntil): bool
    {
        if (!config('settings.cronjob_suspend_enabled', true)) {
            return false;
        }
        $today = CarbonImmutable::today();
        if ($this->suspend_hold_until !== null && !CarbonImmutable::parse($this->suspend_hold_until)->startOfDay()->lessThan($today)) {
            return false;
        }
        $cutoff = $today->subDays(max(0, (int) config('settings.cronjob_order_suspend', 8)));
        if (!CarbonImmutable::instance($paidUntil)->startOfDay()->lessThan($cutoff)) {
            return false;
        }

        // A payment on its way (a SEPA debit processing) on one of its open invoices: the cron does not suspend it (PAY-9).
        if ($this->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)
            ->whereHas('transactions', fn ($payments) => $payments->where('status', InvoiceTransactionStatus::Processing))->exists()) {
            return false;
        }

        if (!config('settings.cronjob_suspend_requires_invoice', false)) {
            return true;
        }

        return $this->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)
            ->whereRaw('DATE(COALESCE(invoices.due_at, invoices.created_at)) < ?', [$cutoff->toDateString()])
            ->whereDate('invoices.created_at', '<', $cutoff->toDateString())->exists();
    }

    /**
     * Staff's «Terminate» (the server action) succeeded: terminated, as WHMCS's module Terminate, and the rest as the
     * cron's termination does: pending upgrades and open invoices cancelled (one holding gateway money stays open for
     * staff), stock back. A running service only; pending and ended ones keep their status.
     *
     * @return array<string, \Throwable> what was left open, as CancelOpenInvoices reports it
     */
    public function markTerminated(): array
    {
        return DB::transaction(function () {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
                return [];
            }
            $locked->status = self::STATUS_TERMINATED;
            $locked->save();
            // Service, upgrades, then invoices: the order ServiceUpgradeService and the cron take.
            $reason = __('invoices.cancellation_reasons.service_terminated');
            $left = CancelPendingUpgrades::forService($locked, $reason);
            $left += CancelOpenInvoices::handle(CancelOpenInvoices::onServiceEnd($locked->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()), $reason, $locked);
            if ($locked->product?->stock !== null) {
                $locked->product->increment('stock', $locked->quantity);
            }
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->unsetRelations();

            return $left;
        });
    }

    /** Rebuild open renewal periods and prices from the current recurring amount. */
    /** @param  bool  $retax  its product changed: the lines' tax is decided again (Δ1), else each keeps its rate */
    public function refreshOpenPeriods(bool $retax = false): void
    {
        if ($this->plan === null || in_array($this->plan->type, ['one-time', 'free'], true)) {
            return;
        }
        $lines = $this->livePeriodLines();
        foreach ($lines as $line) {
            if (!in_array($line->invoice?->status, Invoice::OPEN_STATUSES, true)) {
                continue;
            }
            $end = $this->periodEnd($line->period_start, $lines);
            $next = $lines->first(fn ($other) => $other->id !== $line->id && $other->period_start->greaterThan($line->period_start))?->period_start;
            if ($end !== null && $next !== null && $next->lessThan($end)) {
                $end = $next;
            }
            if ($end !== null) {
                // Under the payment locks; an invoice with a payment on it keeps its line (R9 of #68).
                ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->forceFill([
                    'period_end' => $end,
                    'description' => $this->describeLine($locked->period_start, $end),
                    'price' => $this->priceForPeriod($locked->period_start, $end),
                ] + ($retax ? LineTax::decide($locked) : [])));
            }
        }
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
     * from the persisted coupon origin (activation, or first billed period/registration for legacy data).
     * Free periods count too: they leave
     * no invoice, so invoices cannot be counted (coupons «for N cycles»). A cancelled first period
     * still counts: cancelling it does not restart the count.
     *
     * @param  Collection<int, InvoiceItem>|null  $lines
     */
    public function cycleNumber(CarbonInterface $periodStart, ?Collection $lines = null): int
    {
        $lines ??= $this->livePeriodLines();
        if ($this->coupon_started_at === null) {
            $first = $this->invoiceItems()->whereNotNull('period_start')->get(['id', 'period_start'])
                ->min(fn (InvoiceItem $line) => $line->period_start);
            $this->coupon_started_at = $first !== null ? CarbonImmutable::instance($first)
                : CarbonImmutable::parse($this->created_at ?? $periodStart)->startOfDay();
        }
        $cursor = $this->coupon_started_at;
        $target = CarbonImmutable::instance($periodStart)->startOfDay();
        // Billed periods count as they were billed (a cycle change keeps the monthly ones monthly);
        // periods with no line (free renewals) are counted in the current plan.
        $billed = $this->invoiceItems()->whereNotNull('period_start')->whereNotNull('period_end')
            ->get(['id', 'period_start', 'period_end'])->keyBy(fn (InvoiceItem $line) => $line->period_start->toDateString());

        for ($cycle = 1; $cycle < 1200 && $cursor->lessThan($target); $cycle++) {
            $line = $billed->get($cursor->toDateString());
            $cursor = $line !== null && $line->period_end->greaterThan($cursor)
                ? $line->period_end
                : $this->periodEnd($cursor, $lines);
            if ($cursor === null) {
                return $cycle;
            }
        }

        return $cycle;
    }

    /**
     * Its dates when it starts (pending → active: its account made, staff's Accept or status; Σ1-b). Paid while it
     * waited (a server that failed, a dedicated server built by hand for ten days): the period paid counts from today,
     * the paid-until date moving by the days waited, as staff's grace would (its invoice lines stay). Not paid yet (set
     * up on order, or by staff before payment): due from the order date, as WHMCS's next due date = registration date,
     * so its unpaid first invoice suspends and terminates it as any overdue one. One-time and free plans have none.
     */
    public function datesOnStart(): void
    {
        if ($this->plan === null || in_array($this->plan->type, ['one-time', 'free'], true)) {
            return;
        }
        if ($this->expires_at === null) {
            $ordered = CarbonImmutable::parse($this->created_at ?? now())->startOfDay();
            $this->expires_at = $ordered->toMutable();
            // Not suspended before its first invoice is due (adversarial A3: staff may give an order 30 days): a hold
            // through its due date, as staff would set one; Suspend Days count as usual after it.
            $due = $this->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->min('invoices.due_at');
            if ($due !== null && CarbonImmutable::parse($due)->startOfDay()->greaterThan($ordered)
                && ($this->suspend_hold_until === null || CarbonImmutable::parse($due)->greaterThan($this->suspend_hold_until))) {
                $this->suspend_hold_until = CarbonImmutable::parse($due)->startOfDay()->toMutable();
            }

            return;
        }
        // A pro rata product's service renews on the product's day (PR D): its first period already ends there. Started
        // after that day (paid late), it is not suspended the day it starts (adversarial D-ADV-4): Suspend Days from today.
        if ($this->product?->proratesPlan($this->plan)) {
            $today = CarbonImmutable::today();
            if (CarbonImmutable::parse($this->expires_at)->startOfDay()->lessThan($today)) {
                $hold = $today->addDays(max(0, (int) config('settings.cronjob_order_suspend', 8)));
                if ($this->suspend_hold_until === null || $hold->greaterThan($this->suspend_hold_until)) {
                    $this->suspend_hold_until = $hold->toMutable();
                }
            }

            return;
        }
        // Its first period began when it was paid, or, with nothing to pay (a free first period), when it was ordered
        // (adversarial A6).
        $paidFrom = $this->livePeriodLines()->filter(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID)
            ->min(fn (InvoiceItem $line) => $line->period_start) ?? $this->created_at;
        if ($paidFrom === null) {
            return;
        }
        $waited = (int) CarbonImmutable::parse($paidFrom)->startOfDay()->diffInDays(CarbonImmutable::today(), false);
        if ($waited > 0) {
            $this->expires_at = CarbonImmutable::parse($this->expires_at)->addDays($waited)->toMutable();
            // A coupon's cycles move with the periods (review R3): else «for 2 cycles» ends a cycle early.
            if ($this->coupon_started_at !== null) {
                $this->coupon_started_at = CarbonImmutable::parse($this->coupon_started_at)->addDays($waited);
            }
        }
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
            // A cancelled or refunded invoice bills nothing: its period is a hole to bill again (code review of 2c).
            ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]))
            ->with('invoice:id,status')
            ->get()
            ->sortBy(fn (InvoiceItem $line) => $line->period_start->timestamp)
            ->values();
    }

    /**
     * The periods billed, for the cron's next period (Δ3), with the live ones, read once: [live, billed]. Billed are the
     * live lines and those given up on (a renewal cancelled or refunded, forgone_at): a period given up on is not billed
     * again; reopening its invoice makes it live again.
     *
     * @return array{0: Collection<int, InvoiceItem>, 1: Collection<int, InvoiceItem>}
     */
    public function billedPeriodLines(): array
    {
        $billed = $this->invoiceItems()
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->where(fn ($line) => $line->whereNotNull('forgone_at')
                ->orWhereHas('invoice', fn ($q) => $q->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])))
            ->whereHas('invoice')
            ->with('invoice:id,status')
            ->get()
            ->sortBy(fn (InvoiceItem $line) => $line->period_start->timestamp)
            ->values();
        $live = $billed->reject(fn (InvoiceItem $line) => in_array($line->invoice?->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true))->values();

        return [$live, $billed];
    }

    /** @return BelongsTo<Server, $this> the server this account lives on (WHMCS's tblhosting.server) */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * The server its module actions go to: its own; its product's while it has none (a service not created yet, or one
     * made before servers were the service's). Its own deleted: none, never the product's, another machine where the
     * same username may be someone else's account (review F1).
     */
    public function provisioningServer(): ?Server
    {
        return $this->server_id !== null ? $this->server : $this->product?->server;
    }

    /** @return HasManyThrough<LicenseAccessLog, License, $this> its license's access log */
    public function licenseAccessLogs(): HasManyThrough
    {
        return $this->hasManyThrough(LicenseAccessLog::class, License::class);
    }

    /** @return HasOne<License, $this> its nixpal license (NixpalLicensing), if it has one */
    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }

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
            get: fn () => !$this->isEnded() && $this->plan !== null && $this->plan->type != 'free' && $this->plan->type != 'one-time' && !$this->cancellation?->exists()
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function upgradable(): Attribute
    {
        return Attribute::make(
            // Not without a plan or product (nothing to compare with), nor once a cancellation is asked for (an upgrade
            // of a service about to end would only be refunded, CR-10).
            get: fn () => $this->plan !== null && $this->product !== null && $this->cancellation === null
                && ($this->productUpgrades()->count() > 0 || $this->product->upgradableConfigOptions()->count() > 0) && $this->status == 'active' && $this->upgrade->where('status', ServiceUpgrade::STATUS_PENDING)->count() == 0
                // Nor while its order is unpaid (set up before payment, Σ1-b1): an upgrade would reprice the order's line
                // and lose what it owes, the setup fee (adversarial A5). Paid, its line gets its period.
                && !$this->orderUnpaid()
        );
    }

    /** Its order's own line (on its first invoice, no period yet) is still open: set up before it was paid. */
    public function orderUnpaid(): bool
    {
        if ($this->order_id === null) {
            return false;
        }
        $first = $this->invoiceItems()->min('invoice_id');

        return $first !== null && $this->invoiceItems()->where('invoice_id', $first)->whereNull('period_start')
            ->whereHas('invoice', fn ($invoice) => $invoice->whereIn('status', Invoice::OPEN_STATUSES))->exists();
    }

    public function productUpgrades()
    {
        if ($this->product === null || $this->plan === null) {
            return collect();
        }

        // The module of the server its account is on (review R4), else of its product's.
        $module = $this->server_id !== null ? $this->server?->extension : $this->product->server?->extension;

        return $this->product->upgrades->filter(function ($product) use ($module): bool {
            // Only to a product of the same module (sweep §4, as Blesta): another module's would leave the account on the
            // old server, alive, and fail the change.
            if ($product->server?->extension !== $module) {
                return false;
            }
            // Retired (WHMCS «Retired»): not a new package either, whatever its stock (ADV-1 of #216).
            if ($product->retired) {
                return false;
            }
            // Check stock
            if ($product->stock !== null && ($product->stock - $this->quantity) < 0) {
                return false;
            }
            // In the service's currency (F1 of the SVC-14 review: a plan priced only in another one read as free).
            $plan = $product->availablePlans($this->currency_code)->where('billing_unit', $this->plan->billing_unit)
                ->where('billing_period', $this->plan->billing_period)->values();
            // Only get the upgrades that have the exact same billing cycle as the service
            if ($plan->count() > 0) {
                $product->setAttribute('plan', $plan->first());

                return true;
            }

            return false;
        });
    }

    /**
     * What a renewal of it charges with tax, in cents (Δ1: the price is net; the line adds the account's tax for its
     * product, on price × quantity, rounded once). For a gateway that bills it by itself (a Stripe subscription).
     */
    public function recurringGrossCents(): int
    {
        return (int) round((float) $this->recurringLine()['total'] * 100);
    }

    /**
     * A renewal's line: net × quantity, its tax and total (Δ1).
     *
     * @return array{net: string, tax: string, total: string}
     */
    public function recurringLine(): array
    {
        return LinePricer::invoiceLine(number_format((float) $this->price, 2, '.', ''), max(1, (int) $this->quantity),
            OrderPricing::taxRate($this->client, $this->product));
    }

    /**
     * Start of the period it is in (the one ending at its expiry): its period line ending there, else one cycle back
     * (a period clamped to a short month keeps its real length). None without an expiry or a plan of whole days.
     */
    /**
     * What is left on $today of $amount for the period from $start to its expiry (an upgrade's difference, SVC-14): by the
     * day, and for a pro rata product's first period the way it was priced (PR D, adversarial D-ADV-3).
     */
    public function remainingOf(string $amount, CarbonInterface $start, CarbonInterface $today): string
    {
        // Its first period, to the product's day; a service that renewed on its own day before the product turned pro rata
        // keeps its plain periods.
        if ($this->product?->proratesPlan($this->plan) && $this->expires_at?->day === (int) $this->product->prorata_day) {
            return Prorata::remaining($amount, $start, $this->expires_at, $today, (string) $this->plan->billing_unit,
                (int) $this->plan->billing_period, (int) $this->product->prorata_day);
        }

        return BillingPeriod::remaining($amount, $start, $this->expires_at, $today);
    }

    public function currentPeriodStart(): ?CarbonImmutable
    {
        $plan = $this->plan;
        if ($this->expires_at === null || $plan === null || !in_array($plan->billing_unit, BillingPeriod::UNITS, true)) {
            return null;
        }
        $end = $this->expires_at->toDateString();
        $line = $this->livePeriodLines()->last(fn ($line) => $line->period_end->toDateString() === $end);

        return $line !== null && $line->period_start->lessThan($line->period_end)
            ? CarbonImmutable::instance($line->period_start)->startOfDay()
            : BillingPeriod::start($this->expires_at, $plan->billing_unit, max(1, (int) $plan->billing_period));
    }

    /**
     * The coupon cycle its recurring amount (`price`) was set for: the one it is in, never the first (the first
     * invoice is the order's, the recurring amount the renewal's). What an upgrade prices the new amount with, so a
     * coupon's last discounted cycle is not charged back (SVC-14 review).
     */
    public function priceCycle(): int
    {
        return max(2, $this->invoices()->where('status', 'paid')->count());
    }

    /**
     * The price it renews at, from its plan and options now (Σ7, `OrderPricing`): what a recalculation or an upgrade
     * stores. The coupon counts only for a product it covers (CART-2) and for its cycles (CART-1: empty = the first),
     * those of $cycle when given (else of the next renewal).
     */
    public function calculatePrice(bool $applyCoupon = true, ?int $cycle = null, ?string $groupPercent = null)
    {
        // No plan: the price it has (nothing to compute it from until staff set one).
        if ($this->plan === null) {
            return number_format((float) $this->price, 2, '.', '');
        }
        $price = BigDecimal::of(number_format((float) $this->plan->price($this->currency_code)->price, 2, '.', ''));
        foreach ($this->configs as $config) {
            if ($config->configValue) {
                $price = $price->plus(number_format((float) $config->configValue->price(null, $this->plan->billing_period, $this->plan->billing_unit, $this->currency_code)->price, 2, '.', ''));
            }
        }

        $discount = null;
        if ($applyCoupon && $this->coupon && $this->coupon_expired_at === null) {
            // The cycle this price is for: the next renewal, after those already paid, and never the first (its
            // invoice was made with the order): a first-cycle coupon is never in it, even before the first payment (ADV-4).
            $cycle ??= max($this->invoices()->where('status', 'paid')->count(), 1) + 1;
            if ($this->coupon->cycles() === 0 || $cycle <= $this->coupon->cycles()) {
                $discount = OrderPricing::discount($this->coupon, (int) $this->product_id);
            }
        }

        // Net (Δ1): the tax is the invoice line's, decided when it is made.
        // With its account's group discount (ROADMAP 5), or the one given ('0': none; ClientGroupPricing's «before»).
        return OrderPricing::line(['price' => (string) $price, 'setup_fee' => '0.00'], $discount, $this->client, $this->product, $groupPercent)->netPrice;
    }

    /** @return HasMany<ServiceUpgrade, $this> */
    public function upgrade(): HasMany
    {
        return $this->hasMany(ServiceUpgrade::class);
    }

    /** The mails about this service (the mail audit, docs/mail.md). */
    public function emailLogs(): MorphMany
    {
        return $this->morphMany(EmailLog::class, 'related');
    }
}

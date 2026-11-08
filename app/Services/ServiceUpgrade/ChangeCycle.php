<?php

namespace App\Services\ServiceUpgrade;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderPricing;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\Payment\SavedMethodCharge;
use App\Services\Service\AddAddon;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MyCS\BillingPeriod\BillingPeriod;
use MyCS\Pricing\LinePricer;

/**
 * A service's billing cycle changes to another plan of its product (SVC-14b, docs/decisions.md §7). WHMCS has no such
 * flow (its staff edit the service: «Modified Product/Service»); here, two ways:
 *
 * - From the next renewal (staff and customer): the plan is set to come (`services.next_plan_id`) and the cron applies
 *   it when it bills the next period (`CronJob::billRenewal`): until then the plan and the price stay those of the period
 *   paid, which an upgrade or a credit is priced on (code review B3). Nothing is charged or credited; an open renewal of
 *   the old cycle (nothing paid on it) is cancelled, and the cron bills the period again in the new cycle.
 * - Now (staff): the unused part of the period paid becomes account credit, from what its line was paid (with its tax,
 *   code review B2), and that line ends today; the open renewals are cancelled, and a new period in the new cycle starts
 *   today, billed at once (credit and a saved payment method pay it, as a renewal). An addon shares its parent's
 *   billing day (docs/addons.md, owner 2026-11-08): an addon's new period ends on it (AddAddon::alignedEnd), pro rata;
 *   a parent's live addons follow it to its new day, each credited and billed the same way on the same invoice
 *   (refused, all of it, when one could not be changed itself).
 *
 * The new recurring amount is the new plan's catalog price with the service's options and coupon (as an upgrade, decided
 * 2026-10-14: a staff price is not carried over). Refused while a gateway subscription bills the service (it charges its
 * own cycle), an upgrade waits, a cancellation is asked for, an open invoice of it has money on it, or a coupon priced by
 * the cycle runs (one for a number of cycles, or a fixed amount: a cycle change would stretch or shrink it, S3).
 */
final class ChangeCycle
{
    public const NEXT_RENEWAL = 'next_renewal';

    public const NOW = 'now';

    /**
     * The plans the service can move to: its product's other recurring ones, priced in its currency. With a change from
     * the next renewal set, its own plan too (choosing it takes the change back).
     *
     * @return Collection<int, Plan>
     */
    public static function plans(Service $service): Collection
    {
        if ($service->product === null) {
            return collect();
        }

        $plans = $service->product->availablePlans($service->currency_code)
            ->filter(fn (Plan $plan) => $plan->type === 'recurring' && in_array($plan->billing_unit, BillingPeriod::UNITS, true)
                && (int) $plan->billing_period >= 1
                && ((int) $plan->id !== (int) $service->plan_id || $service->next_plan_id !== null))
            ->values();
        // Its own plan with no price in its currency (a cycle no longer sold, an imported one: #628) is still the one to go
        // back to: a change set from it can be taken back, never stuck on the catalog's other cycle (review of #628).
        $own = $service->plan;
        if ($service->next_plan_id !== null && $own !== null && !$plans->contains('id', $own->id) && $own->priceable_type === Product::class
            && (int) $own->priceable_id === (int) $service->product_id) {
            $plans->prepend($own);
        }

        return $plans;
    }

    /** An invoice that also bills other services and holds only credit (no gateway or transfer payment). */
    private static function sharedWithCreditOnly(Invoice $invoice, Service $service): bool
    {
        return $invoice->items()->where(fn ($line) => $line->where('reference_type', '!=', Service::class)->orWhere('reference_id', '!=', $service->id))->exists()
            && !$invoice->transactions()->where('is_credit_transaction', false)
                ->whereIn('status', [InvoiceTransactionStatus::Succeeded, InvoiceTransactionStatus::Processing])->exists();
    }

    /** Why the change cannot be made (a message for whoever asked), or null. */
    public static function refusal(Service $service, Plan $plan, string $mode): ?string
    {
        if (!in_array($mode, [self::NEXT_RENEWAL, self::NOW], true)) {
            return __('This change is not available.');
        }
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) || $service->plan === null) {
            return __('Only a running service can change its billing cycle.');
        }
        if (!self::plans($service)->contains('id', $plan->id) || ($mode === self::NOW && (int) $plan->id === (int) $service->plan_id)) {
            return __('This billing cycle is not available for this service.');
        }
        if (($why = self::blocked($service, $mode)) !== null) {
            return $why;
        }
        if ($mode === self::NOW) {
            // An addon shares its parent's billing day (docs/addons.md, owner 2026-11-08): its new period ends on it
            // (AddAddon::alignedEnd), so its parent needs a due date ahead.
            if ($service->isAddon() && ($service->parent === null || AddAddon::alignedEnd($service->parent, $plan) === null)) {
                return __('services.addons.add.no_due_date');
            }
            // Its live addons follow it to its new billing day, on the same invoice: each as it would be changed itself.
            foreach (self::followers($service) as $addon) {
                if (self::follows($addon) && ($why = self::blocked($addon, $mode)) !== null) {
                    return __('Addon #:id follows this service: :reason', ['id' => $addon->id, 'reason' => $why]);
                }
                // Its new period goes on its parent's invoice: its own «Do not charge automatically» or its own way of
                // payment would hold, or mix, the whole invoice (the cron bills such a one apart, REV3-KISS-2).
                if (self::follows($addon) && ((bool) $addon->no_auto_charge !== (bool) $service->no_auto_charge
                    || ($addon->payment_gateway_id === null ? null : (int) $addon->payment_gateway_id) !== ($service->payment_gateway_id === null ? null : (int) $service->payment_gateway_id))) {
                    return __('Addon #:id is paid another way than this service: change it from the next renewal, or set the addon\'s payment as the service\'s first.', ['id' => $addon->id]);
                }
            }
            if (BigDecimal::of(self::quote($service, $plan, $mode)['credit'])->isPositive() && !config('settings.credits_enabled', false)) {
                return __('Account credit is off: change it from the next renewal.');
            }
        }

        return null;
    }

    /** Why this service's money stops a change (its own invoices, payments, period), or null: the parent's and each addon's. */
    private static function blocked(Service $service, string $mode): ?string
    {
        if ((string) $service->subscription_id !== '') {
            return __('A subscription bills this service: cancel it first.');
        }
        if ($service->upgrade()->where('status', ServiceUpgrade::STATUS_PENDING)->exists()) {
            return __('An upgrade of this service is waiting for payment.');
        }
        if ($service->cancellation()->exists()) {
            return __('This service is to be cancelled.');
        }
        // Credit on an invoice it shares with other services (a grouped renewal, PR C) does not stop it: only its line goes,
        // and the credit stays on what is left (CancelOpenInvoices, partial).
        if ($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()->unique('id')->contains(fn (Invoice $invoice) => $invoice->hasPayments()
            && !self::sharedWithCreditOnly($invoice, $service))) {
            return __('An open invoice of this service has a payment on it.');
        }
        // A saved-card charge of an open invoice of it, its outcome not here yet: cancelled for the new cycle, the period
        // would be billed and charged again while the money that lands becomes credit (sweep2 REV2-SAM-1).
        if ($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()->unique('id')->contains(fn (Invoice $invoice) => SavedMethodCharge::onItsWay($invoice))) {
            return __('A card payment of an open invoice of this service is on its way or not settled: wait for it first.');
        }
        // An open invoice of it kept from the automatic charge (the customer's «I'll pay another way», or staff's «Do not
        // auto-charge»: a payment agreed another way, a transfer maybe on its way) would go, and the period it bills come
        // back on an invoice the cron charges to the saved method: paid twice (ADV-623-1). Pay it, or settle it, first.
        if (!$service->no_auto_charge && $service->client?->auto_charge && $service->client->billingAgreements()->exists()
            && $service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->where('invoices.no_auto_charge', true)->exists()) {
            return __('An open invoice of this service is to be paid another way: pay it first, or ask us.');
        }
        $coupon = $service->coupon;
        if ($coupon !== null && $service->coupon_expired_at === null && ($coupon->cycles() > 1 || in_array($coupon->type, ['fixed', 'override'], true))) {
            return __('A discount for its billing cycle runs on this service: ask us to change it.');
        }
        if ($mode === self::NOW) {
            $today = CarbonImmutable::today();
            // Overdue: the unpaid days would never be billed (S1). From the next renewal, the overdue period is billed again.
            if ($service->expires_at === null || CarbonImmutable::instance($service->expires_at)->startOfDay()->lessThan($today)
                || $service->currentPeriodStart() === null) {
                return __('This service has no paid period to change now: change it from the next renewal.');
            }
            // Days already used that an open invoice still bills (a period staff gave on credit, a grace): cancelling it
            // would give them away (adversarial F5). Pay or settle it first.
            $lines = $service->livePeriodLines();
            if ($lines->contains(fn (InvoiceItem $line) => in_array($line->invoice?->status, Invoice::OPEN_STATUSES, true)
                && $line->period_start->startOfDay()->lessThan($today))) {
                return __('An open invoice of this service bills days already used: settle it first.');
            }
            // A period paid that starts today or later (a renewal paid ahead, or on its day): change it from the next renewal (B1).
            if ($lines->contains(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID
                && $line->period_start->startOfDay()->greaterThanOrEqualTo($today))) {
                return __('A renewal is paid ahead: change it from the next renewal.');
            }
        }

        return null;
    }

    /**
     * What the change does, before it is made: the new recurring amount (net, per unit) and with tax for the quantity; now,
     * also the credit for the unused days (as they were paid), the new period, its line's net amount per unit and what it
     * is billed (with tax). An addon's new period ends on its parent's billing day, its amount pro rata (AddAddon::alignedEnd).
     * A parent's live addons follow it to its new day (owner 2026-11-08): each one's credit and new period as its own
     * change would be, in `addons` (by id; free or one-time ones only move their date), and in the credit and charge.
     *
     * @return array{price: string, gross: string, credit: string, start: ?CarbonImmutable, end: ?CarbonImmutable, line: string, charge: string, day: ?int, addons: array<int, array{end: ?CarbonImmutable, line: ?string}>}
     */
    public static function quote(Service $service, Plan $plan, string $mode): array
    {
        $after = self::withPlan($service, $plan);
        $price = (string) $after->calculatePrice();
        $gross = self::gross($service, $price);
        if ($mode !== self::NOW || $service->expires_at === null) {
            return ['price' => $price, 'gross' => $gross, 'credit' => '0.00', 'start' => null, 'end' => null, 'line' => $price, 'charge' => '0.00', 'day' => null, 'addons' => []];
        }

        $today = CarbonImmutable::today();
        $credit = BigDecimal::of(self::credit($service, $today));
        if ($service->isAddon() && $service->parent !== null) {
            [$end, $line] = self::aligned($after, $service->parent, $today, $price);
            $day = $service->parent->billingDay(CarbonImmutable::parse($service->parent->expires_at));
        } else {
            [$end, $line] = [$after->periodEnd($today), $price];
            $after->rememberBillingDay($today);
            $day = $end === null ? null : $after->forceFill(['expires_at' => $end])->billingDay($end);
        }
        $charge = BigDecimal::of(self::gross($service, $line));
        $addons = [];
        foreach ($end === null || $service->isAddon() ? [] : self::followers($service) as $addon) {
            $addons[$addon->id] = ['end' => $end, 'line' => null];
            if (self::follows($addon)) {
                [$until, $amount] = self::aligned($addon, $after, $today, number_format((float) $addon->price, 2, '.', ''));
                $addons[$addon->id] = ['end' => $until, 'line' => $amount];
                $credit = $credit->plus(self::credit($addon, $today));
                $charge = $charge->plus(self::gross($addon, $amount));
            }
        }

        return [
            'price' => $price,
            'gross' => $gross,
            'credit' => (string) $credit->toScale(2),
            'start' => $today,
            'end' => $end,
            'line' => $line,
            'charge' => (string) $charge->toScale(2),
            'day' => $day,
            'addons' => $addons,
        ];
    }

    /** The days left as they were paid: the period's lines, and the upgrades paid within it, less what a downgrade in it gave back already (sweep UPGRADES-1, as DowngradeCredit counts them). */
    private static function credit(Service $service, CarbonImmutable $today): string
    {
        [$upgrades, $given] = DowngradeCredit::withinPeriod($service, $today);

        return (string) BigDecimal::max(BigDecimal::of(self::unused($service, $today))->plus($upgrades)->minus($given), BigDecimal::zero())->toScale(2);
    }

    /** Its line with tax, for its quantity. */
    private static function gross(Service $service, string $net): string
    {
        return LinePricer::invoiceLine($net, max(1, (int) $service->quantity), OrderPricing::taxRate($service->client, $service->product))['total'];
    }

    /**
     * An addon's new period from $today, on its parent's billing day (AddAddon::alignedEnd for its plan), and its amount per
     * unit: $price pro rata of its whole cycle, as «Add addon» bills one.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private static function aligned(Service $addon, Service $parent, CarbonImmutable $today, string $price): array
    {
        $end = AddAddon::alignedEnd($parent, $addon->plan, $today) ?? throw new DisplayException(__('services.addons.add.no_due_date'));
        $full = AddAddon::fullStart($parent, $addon->plan, $end);

        return [$end, (string) BigDecimal::of(BillingPeriod::remaining($price, $full, $end, $today))->toScale(2, RoundingMode::HalfUp)];
    }

    /**
     * A parent's live addons (active, suspended, pending), which share its billing day and so follow a change «now».
     *
     * @return Collection<int, Service>
     */
    private static function followers(Service $service, bool $lock = false): Collection
    {
        if ($service->isAddon()) {
            return collect();
        }
        $query = $service->addons()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /** Billed by period (a recurring plan): its money follows. A free or one-time one only moves its date. */
    private static function follows(Service $addon): bool
    {
        return $addon->plan?->type === 'recurring' && in_array($addon->plan->billing_unit, BillingPeriod::UNITS, true) && (int) $addon->plan->billing_period >= 1;
    }

    /**
     * Make the change. Returns the invoice of the new period (now), else null.
     *
     * @param  string  $source  MoneyTrail::SOURCE_* of the credit (staff, client)
     *
     * @throws DisplayException when it is refused
     */
    public static function apply(Service $service, Plan $plan, string $mode, string $source): ?Invoice
    {
        $invoice = DB::transaction(function () use ($service, $plan, $mode, $source) {
            // Parent first, then the addon(s): the order every money path takes (AddonCascade). Its parent from the model
            // passed in, not a plain read: on MariaDB the first plain read fixes the transaction's snapshot, and every read
            // after the locks would miss a payment committed while it waited for them (REV2-KISS-2).
            $parentId = $service->parent_service_id;
            $parent = $parentId === null ? null : Service::query()->whereKey($parentId)->lockForUpdate()->first();
            $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->parent_service_id !== (int) $parentId) {
                throw new DisplayException(__('This service changed meanwhile: try again.'));
            }
            if ($parent !== null) {
                $locked->setRelation('parent', $parent);
            }
            $followers = $mode === self::NOW ? self::followers($locked, lock: true) : collect();
            // Then the customer, before any plain read (docs/money-trail.md §2: service → upgrade → customer → invoice): a
            // payment, a credit or the cron's finish of its invoices takes it first, so none commits between the refusal's
            // reads and the cancellation below (REV3-KISS-1).
            Client::lock((int) $locked->client_id);
            if (($why = self::refusal($locked, $plan, $mode)) !== null) {
                throw new DisplayException($why);
            }
            $quote = self::quote($locked, $plan, $mode);

            // The open renewals were for the old cycle (nothing paid on them: refusal), its following addons' too, cancelled
            // as one: their lines go together, so credit on an invoice of only them goes back, never pays what is left of
            // the group (REV2-KISS-1). An invoice CancelOpenInvoices keeps open (a line it cannot tell, staff to look at)
            // stops the change, all of it rolled back (B4).
            $back = (int) $plan->id === (int) $locked->plan_id;
            if (!$back) {
                $group = $followers->filter(fn (Service $addon) => self::follows($addon))->values();
                $open = collect([$locked, ...$group])->flatMap(fn (Service $each) => $each->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get())
                    ->unique('id')->sortBy('id');
                $left = CancelOpenInvoices::handle($open, __('invoices.cancellation_reasons.cycle_changed'), $locked, array_values($group->all()));
                if ($left !== []) {
                    throw new DisplayException(__('An open invoice of this service needs staff first: :reason', ['reason' => collect($left)->first()->getMessage()]));
                }
                // One paid meanwhile, its lines of the group still on it (CancelOpenInvoices leaves it be; one it cut down
                // to the others' lines may be paid by the credit on it, CancelOpenInvoices::cancel): that period stands.
                $paid = $open->isEmpty() ? collect() : Invoice::query()->whereKey($open->pluck('id'))->where('status', Invoice::STATUS_PAID)->lockForUpdate()->pluck('id');
                if ($paid->isNotEmpty() && InvoiceItem::query()->whereIn('invoice_id', $paid)->where('reference_type', Service::class)
                    ->whereIn('reference_id', collect([$locked, ...$group])->pluck('id'))->exists()) {
                    throw new DisplayException(__('An open invoice of this service was paid meanwhile: try again.'));
                }
            }

            if ($mode === self::NEXT_RENEWAL) {
                // The cron applies it when it bills the next period; its own plan takes the change back.
                $locked->next_plan_id = $back ? null : $plan->id;
                $locked->save();

                return null;
            }

            $start = $quote['start'] ?? CarbonImmutable::today();
            $end = $quote['end'] ?? $start;
            $locked->plan_id = $plan->id;
            $locked->next_plan_id = null;
            $locked->setRelation('plan', $plan);
            $locked->price = (float) $quote['price'];
            $locked->price_locked = false; // the new plan's catalog price, not staff's (sweep UPGRADES-5)
            // An addon keeps its parent's billing day (docs/addons.md); a parent takes today's, and its addons with it.
            if ($locked->isAddon()) {
                $locked->billing_day = $quote['day'];
            } else {
                $locked->rememberBillingDay($start);
            }

            $cents = RecordPaymentService::toCents((float) $quote['credit']);
            if ($cents > 0) {
                $with = $followers->isEmpty() ? '' : ' and its addons #' . $followers->pluck('id')->implode(', #');
                (new CreditLedger)->add((int) $locked->client_id, $locked->currency_code, $cents, MoneyTrail::CAUSE_CYCLE_CHANGE, $source,
                    note: "Service #{$locked->id}{$with}: the unused days of their period, on a change of billing cycle");
            }

            $invoice = $locked->invoices()->make([
                'client_id' => $locked->client_id,
                'status' => Invoice::STATUS_UNPAID,
                'due_at' => $start,
                'currency_code' => $locked->currency_code,
            ] + (($own = ItemPaymentMethod::apart($locked)) !== null ? ['preferred_gateway_id' => $own] : []));
            $invoice->save();
            self::newPeriod($locked, $invoice, $start, $end, $quote['line']);

            // Its addons follow it to its new billing day, each from today to its aligned end (owner 2026-11-08); a free or
            // one-time one only moves its date.
            foreach ($followers as $addon) {
                $addon->billing_day = $quote['day'];
                ['end' => $until, 'line' => $amount] = $quote['addons'][$addon->id];
                if ($amount !== null && $until !== null) {
                    self::newPeriod($addon, $invoice, $start, $until, $amount);
                } elseif ($addon->expires_at !== null) {
                    $addon->expires_at = $until?->toMutable();
                    $addon->save();
                }
            }

            return $invoice->refresh();
        });

        if ($invoice === null) {
            return null;
        }
        // As a renewal (CronJob::billRenewal): the credit pays what it covers, then a saved payment method the rest (S2).
        // The account's saved method (decision 6), or its method of the service's own gateway (#568), read again after the
        // credit (a method removed meanwhile is not charged): AutoCharge's rule.
        (new CreditAutoApply)->toInvoice($invoice, notify: AutoCharge::agreementFor($invoice) === null);
        $invoice->refresh();
        $agreement = AutoCharge::agreementFor($invoice->setRelation('client', $invoice->client?->fresh()));
        if ($agreement !== null && $invoice->status === Invoice::STATUS_UNPAID) {
            // One charge at a time, with its idempotency key (PR H); a declined one tells the customer. One of unknown
            // outcome (maybe taken, held and sent again with its key) does not, as AutoCharge (sweep 3, L-FAILURES-3).
            try {
                $attempt = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CHANGE);
                if (in_array($attempt->status, [ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_ACTION_REQUIRED], true)) {
                    NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
                }
            } catch (DisplayException) {
                // Not payable now, or a payment already on its way: nothing was sent.
            }
        }

        return $invoice->refresh();
    }

    /**
     * The period paid ends today: the rest of it is the credit. Its line keeps its price (what was paid), only the days it
     * covers end, so the next payment counts from today (RenewServiceService chains paid lines). Its new period, $start to
     * $end at $price net per unit, is a line of $invoice.
     */
    private static function newPeriod(Service $service, Invoice $invoice, CarbonImmutable $start, CarbonImmutable $end, string $price): void
    {
        foreach (self::paidCovering($service, $start) as $line) {
            $line->forceFill(['period_end' => $start])->save();
        }
        $service->expires_at = $start->toMutable();
        $service->save();
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $price,
            'quantity' => $service->quantity,
            'description' => $service->describeLine($start, $end),
            'period_start' => $start,
            'period_end' => $end,
            'payment_gateway_id' => ItemPaymentMethod::live($service), // its way as billed (#541)
        ]);
    }

    /**
     * What is left of the period paid on $today, as it was paid (with its line's tax, B2; without its setup fee or what its
     * invoice gave back, PaidForPeriod: sweep 2): each paid line covering today,
     * for its days after today; nothing when none is paid (adversarial F5). A service with no period line at all
     * (imported without one): its price, with tax, for its days left that were paid before the cut-over, up to its expiry
     * or to the first day a cancelled or refunded line holds, whichever comes first: the days on such a line were not paid
     * for, nothing for them (sweep 4 E-PAYMENT-CREDIT-1: a refund in full, then a downgrade or a cycle change «now», gave
     * the refunded days back as credit), but the days before it still were (REV4-E-1: an imported service whose first
     * renewal here was paid early, then refunded, keeps the days paid in WHMCS). The period's part of what a downgrade gives
     * back (DowngradeCredit) and of a cycle change's credit (quote, with the upgrades): never more than was paid.
     */
    public static function unused(Service $service, CarbonImmutable $today): string
    {
        $lines = self::paidCovering($service, $today);
        if ($lines->isEmpty() && $service->livePeriodLines()->isEmpty()) {
            [$start, $end] = self::importedPeriod($service, $today);
            if ($start === null || $end === null || !$end->greaterThan($today->startOfDay())) {
                return '0.00';
            }
            $paid = LinePricer::invoiceLine(number_format((float) $service->price, 2, '.', ''), max(1, (int) $service->quantity),
                OrderPricing::taxRate($service->client, $service->product))['total'];

            return (string) BigDecimal::max(BigDecimal::of(BillingPeriod::remaining($paid, $start, $end, $today)), BigDecimal::zero())->toScale(2);
        }
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            // What it paid for its days: not a setup fee on it, not what its invoice gave back (sweep 2, PaidForPeriod).
            $total = $total->plus(BillingPeriod::remaining(PaidForPeriod::of($line), $line->period_start, $line->period_end, $today));
        }

        return (string) $total->toScale(2);
    }

    /**
     * The period paid before the cut-over (imported without its lines) whose days left are priced, as [start, end]: up to
     * the expiry, or to the start of the first cancelled or refunded period line that holds any of the days from $today to
     * the expiry, if earlier (those days were billed here and given up on: their money refunded, or never paid). A line
     * that holds $today itself leaves no paid day (end <= today). A renewal ahead given up on (from the expiry on) changes
     * nothing. [null, null] when it cannot be priced (no expiry, or a plan with no billing unit).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private static function importedPeriod(Service $service, CarbonImmutable $today): array
    {
        if ($service->expires_at === null) {
            return [null, null];
        }
        $today = $today->startOfDay();
        $expiry = CarbonImmutable::instance($service->expires_at)->startOfDay();
        $end = $service->invoiceItems()
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]))
            ->get()
            ->filter(fn (InvoiceItem $line) => $line->period_end->startOfDay()->greaterThan($today)
                && $line->period_start->startOfDay()->lessThan($expiry))
            ->map(fn (InvoiceItem $line) => CarbonImmutable::instance($line->period_start)->startOfDay())
            ->push($expiry)
            ->min();
        if ($end->equalTo($expiry)) {
            return [$service->currentPeriodStart(), $expiry];
        }
        if ($end->lessThanOrEqualTo($today)) {
            return [$end, $end];
        }
        $plan = $service->plan;
        if ($plan === null || !in_array($plan->billing_unit, BillingPeriod::UNITS, true)) {
            return [null, null];
        }

        return [BillingPeriod::start($end, $plan->billing_unit, max(1, (int) $plan->billing_period), $service->billingDay($end)), $end];
    }

    /** @return Collection<int, InvoiceItem> the service's paid period lines that run past $day (they started before it) */
    private static function paidCovering(Service $service, CarbonImmutable $day): Collection
    {
        return $service->livePeriodLines()->filter(fn (InvoiceItem $line) => $line->invoice?->status === Invoice::STATUS_PAID
            && $line->period_start->startOfDay()->lessThan($day) && $line->period_end->startOfDay()->greaterThan($day))->values();
    }

    /** The service as it is on the other plan (in memory, never saved): what prices it. */
    private static function withPlan(Service $service, Plan $plan): Service
    {
        $after = $service->replicate();
        $after->id = $service->id;
        $after->exists = true;
        $after->plan_id = $plan->id;
        $after->setRelation('plan', $plan);
        $after->setRelation('configs', $service->configs);
        $after->setRelation('product', $service->product);

        return $after;
    }
}

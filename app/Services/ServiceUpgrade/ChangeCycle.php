<?php

namespace App\Services\ServiceUpgrade;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
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
use Brick\Math\BigDecimal;
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
 *   today, billed at once (credit and a saved payment method pay it, as a renewal).
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

        return $service->product->availablePlans($service->currency_code)
            ->filter(fn (Plan $plan) => $plan->type === 'recurring' && in_array($plan->billing_unit, BillingPeriod::UNITS, true)
                && (int) $plan->billing_period >= 1
                && ((int) $plan->id !== (int) $service->plan_id || $service->next_plan_id !== null))
            ->values();
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
            if (BigDecimal::of(self::quote($service, $plan, $mode)['credit'])->isPositive() && !config('settings.credits_enabled', false)) {
                return __('Account credit is off: change it from the next renewal.');
            }
        }

        return null;
    }

    /**
     * What the change does, before it is made: the new recurring amount (net, per unit) and with tax for the quantity; now,
     * also the credit for the unused days (as they were paid), the new period and what it is billed (with tax).
     *
     * @return array{price: string, gross: string, credit: string, start: ?CarbonImmutable, end: ?CarbonImmutable, charge: string}
     */
    public static function quote(Service $service, Plan $plan, string $mode): array
    {
        $after = self::withPlan($service, $plan);
        $price = (string) $after->calculatePrice();
        $gross = LinePricer::invoiceLine($price, max(1, (int) $service->quantity), OrderPricing::taxRate($service->client, $service->product))['total'];
        if ($mode !== self::NOW || $service->expires_at === null) {
            return ['price' => $price, 'gross' => $gross, 'credit' => '0.00', 'start' => null, 'end' => null, 'charge' => '0.00'];
        }

        $today = CarbonImmutable::today();

        return [
            'price' => $price,
            'gross' => $gross,
            'credit' => self::unused($service, $today),
            'start' => $today,
            'end' => $after->periodEnd($today),
            'charge' => $gross,
        ];
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
            $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            if (($why = self::refusal($locked, $plan, $mode)) !== null) {
                throw new DisplayException($why);
            }
            $quote = self::quote($locked, $plan, $mode);

            // The open renewals were for the old cycle (nothing paid on them: refusal); an invoice CancelOpenInvoices
            // keeps open (a line it cannot tell, staff to look at) stops the change, all of it rolled back (B4).
            $back = (int) $plan->id === (int) $locked->plan_id;
            if (!$back) {
                $left = CancelOpenInvoices::handle($locked->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()->unique('id'),
                    __('invoices.cancellation_reasons.cycle_changed'), $locked);
                if ($left !== []) {
                    throw new DisplayException(__('An open invoice of this service needs staff first: :reason', ['reason' => collect($left)->first()->getMessage()]));
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
            // The period paid ends today: the rest of it is the credit. Its line keeps its price (what was paid), only the
            // days it covers end, so the next payment counts from today (RenewServiceService chains paid lines).
            foreach (self::paidCovering($locked, $start) as $line) {
                $line->forceFill(['period_end' => $start])->save();
            }

            $locked->plan_id = $plan->id;
            $locked->next_plan_id = null;
            $locked->setRelation('plan', $plan);
            $locked->price = (float) $quote['price'];
            $locked->expires_at = $start->toMutable();
            $locked->rememberBillingDay($start);
            $locked->save();

            $cents = RecordPaymentService::toCents((float) $quote['credit']);
            if ($cents > 0) {
                (new CreditLedger)->add((int) $locked->client_id, $locked->currency_code, $cents, MoneyTrail::CAUSE_CYCLE_CHANGE, $source,
                    note: "Service #{$locked->id}: the unused days of its period, on a change of billing cycle");
            }

            $invoice = $locked->invoices()->make([
                'client_id' => $locked->client_id,
                'status' => Invoice::STATUS_UNPAID,
                'due_at' => $start,
                'currency_code' => $locked->currency_code,
            ] + (($own = ItemPaymentMethod::apart($locked)) !== null ? ['preferred_gateway_id' => $own] : []));
            $invoice->forceFill(['no_auto_charge' => $own !== null]); // paid another way: not the card's (#541)
            $invoice->save();
            $invoice->items()->create([
                'reference_id' => $locked->id,
                'reference_type' => Service::class,
                'price' => $quote['price'],
                'quantity' => $locked->quantity,
                'description' => $locked->describeLine($start, $end),
                'period_start' => $start,
                'period_end' => $end,
            ]);

            return $invoice->refresh();
        });

        if ($invoice === null) {
            return null;
        }
        // As a renewal (CronJob::billRenewal): the credit pays what it covers, then a saved payment method the rest (S2).
        // The account's saved method (decision 6), read again after the credit (a method removed meanwhile is not charged).
        // AutoCharge's rule: not one paid another way (the service's own payment method, code review of #541).
        (new CreditAutoApply)->toInvoice($invoice, notify: AutoCharge::agreementFor($invoice) === null);
        $invoice->refresh();
        $agreement = AutoCharge::agreementFor($invoice->setRelation('client', $invoice->client?->fresh()));
        if ($agreement !== null && $invoice->status === Invoice::STATUS_UNPAID) {
            // One charge at a time, with its idempotency key (PR H); a declined one tells the customer.
            try {
                $attempt = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CHANGE);
                if ($attempt->status !== ChargeAttempt::STATUS_SUCCEEDED) {
                    NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
                }
            } catch (DisplayException) {
                // Not payable now, or a payment already on its way: nothing was sent.
            }
        }

        return $invoice->refresh();
    }

    /**
     * What is left of the period paid on $today, as it was paid (with its line's tax, B2): each paid line covering today,
     * for its days after today; nothing when none is paid (adversarial F5). A service with no period line at all
     * (imported without one): its price, with tax, for the days to its expiry. Also the most a downgrade gives back
     * (Livewire\Services\Upgrade): never more than was paid.
     */
    public static function unused(Service $service, CarbonImmutable $today): string
    {
        $lines = self::paidCovering($service, $today);
        if ($lines->isEmpty() && $service->livePeriodLines()->isEmpty()) {
            $start = $service->currentPeriodStart();
            if ($start === null || $service->expires_at === null) {
                return '0.00';
            }
            $paid = LinePricer::invoiceLine(number_format((float) $service->price, 2, '.', ''), max(1, (int) $service->quantity),
                OrderPricing::taxRate($service->client, $service->product))['total'];

            return (string) BigDecimal::max(BigDecimal::of(BillingPeriod::remaining($paid, $start, $service->expires_at, $today)), BigDecimal::zero())->toScale(2);
        }
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $total = $total->plus(BillingPeriod::remaining(number_format((float) $line->line_total, 2, '.', ''),
                $line->period_start, $line->period_end, $today));
        }

        return (string) $total->toScale(2);
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

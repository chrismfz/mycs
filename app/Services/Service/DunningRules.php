<?php

namespace App\Services\Service;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Which services the cron suspends and terminates for non-payment, as of a day: the cron's own rules (CronJob, today), and
 * the forecast's (DunningForecast, the days to come). One place, so the forecast never drifts from what the cron does.
 */
final class DunningRules
{
    /**
     * Active services past Suspend Days, not held, not of a client exempt from it (its own setting or its group's), not on
     * a free or one-time plan (no period to pay; a service whose plan is gone is not exempt), with no payment on its way.
     * With $overdueInvoice: and an open invoice billing them (a charge alone is not one, #507: as Service::stillOverdue
     * reads it, sweep CRON-BILLING-1) due and issued Suspend Days ago (a missing due date counts from its issue). With $holdDay: those held until that day instead, suspendable the day after it (code review 3).
     *
     * @return Builder<Service>
     */
    public static function suspendable(Carbon $asOf, bool $overdueInvoice = false, ?Carbon $holdDay = null): Builder
    {
        $asOf = $holdDay !== null ? $holdDay->copy()->addDay() : $asOf->copy()->startOfDay();
        $cutoff = $asOf->copy()->subDays(self::days('cronjob_order_suspend', 8))->toDateString();
        $query = self::unheldWithNoPaymentOnItsWay(Service::query()->where('status', Service::STATUS_ACTIVE), $asOf, $holdDay)
            ->whereDate('expires_at', '<', $cutoff)
            ->tap(self::notExempt(...));

        return !$overdueInvoice ? $query : $query->whereHas('renewalInvoices', fn ($invoices) => $invoices
            ->where('invoices.status', Invoice::STATUS_UNPAID)
            ->whereRaw('DATE(COALESCE(invoices.due_at, invoices.created_at)) < ?', [$cutoff])
            ->whereDate('invoices.created_at', '<', $cutoff));
    }

    /**
     * Suspended for non-payment (the cron's mark on it) at least «minimum days suspended» ago, and its termination due
     * (terminationDue: the part the job reads again before the server is asked).
     *
     * @return Builder<Service>
     */
    public static function terminable(Carbon $asOf): Builder
    {
        $asOf = $asOf->copy()->startOfDay();

        return self::terminationDue(Service::query()->where('status', Service::STATUS_SUSPENDED)
            ->whereNotNull('dunning_suspended_at')
            ->whereDate('dunning_suspended_at', '<=', $asOf->copy()->subDays(self::days('cronjob_terminate_min_suspended_days', 7))->toDateString()), $asOf);
    }

    /**
     * What a termination for non-payment needs besides the suspension, and what can change between the cron's decision
     * and the server being asked (TerminateJob, a Retry days later): past Termination Days, not held, not exempt (as a
     * suspension: its client, a free or one-time plan), no payment on its way, no later period already paid (ending it
     * would keep that money without the period: staff decide), and no money for it since its due date (moneySinceDue).
     * $money false: all but that last one (the cron's notice of those the money holds back).
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public static function terminationDue(Builder $query, Carbon $asOf, bool $money = true): Builder
    {
        $asOf = $asOf->copy()->startOfDay();
        $query = self::unheldWithNoPaymentOnItsWay($query, $asOf)
            ->tap(self::notExempt(...))
            ->whereDate('expires_at', '<', $asOf->copy()->subDays(self::days('cronjob_order_terminate', 60))->toDateString())
            ->whereNotExists(fn ($line) => $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
                ->whereColumn('invoice_items.period_start', '>=', 'services.expires_at')
                ->where('invoices.status', Invoice::STATUS_PAID));

        return $money ? $query->whereNotExists(fn ($line) => self::moneySinceDue($line)) : $query->whereExists(fn ($line) => self::moneySinceDue($line));
    }

    /** The termination the cron decided for this service still stands today: the switch on, and terminationDue. */
    public static function terminationStands(Service $service): bool
    {
        return (bool) config('settings.cronjob_terminate_enabled', true)
            && self::terminationDue(Service::query()->whereKey($service->getKey()), Carbon::today())->exists();
    }

    /** Money came for this service since its due date (moneySinceDue). */
    public static function paidSinceDue(Service $service): bool
    {
        return Service::query()->whereKey($service->getKey())->whereExists(fn ($line) => self::moneySinceDue($line))->exists();
    }

    /**
     * Money for the service (services.id in the outer query) since its due date that gave it nothing yet: a line of it
     * not applied (applied_at empty: a payment's renewal sets it; whatever its period, an imported line has none and a
     * moved due date makes it earlier, REV-773-1/2), on an invoice paid on or after that date, or with a payment
     * succeeded or still processing received on or after it, whatever the invoice's status now (open and part paid, kept
     * as a debt, cancelled with the money turned to credit). Such a service is never terminated on its own: staff decide.
     * The date keeps out the history an import brings (its paid lines are never marked applied).
     *
     * @param  \Illuminate\Database\Query\Builder  $line
     */
    public static function moneySinceDue($line): void
    {
        $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
            ->whereNull('invoice_items.applied_at')
            ->where(fn ($money) => $money
                ->where(fn ($paid) => $paid->where('invoices.status', Invoice::STATUS_PAID)->whereColumn('invoices.paid_at', '>=', 'services.expires_at'))
                ->orWhereExists(fn ($payment) => $payment->from('invoice_transactions')->whereColumn('invoice_transactions.invoice_id', 'invoices.id')
                    ->whereIn('invoice_transactions.status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value])
                    ->whereColumn('invoice_transactions.created_at', '>=', 'services.expires_at')));
    }

    /**
     * Never dunned (suspended or terminated): a free or one-time plan (no period to pay; a service whose plan is gone
     * is not exempt), a client exempt from it (its own setting or its group's).
     *
     * @param  Builder<Service>  $query
     */
    private static function notExempt(Builder $query): void
    {
        $query->whereDoesntHave('plan', fn ($plan) => $plan->whereIn('type', ['free', 'one-time']))
            ->whereDoesntHave('client', fn ($client) => Client::whereFlag($client, 'dunning_exempt'));
    }

    /** A day count setting; never negative (a negative count would act before the due date). */
    public static function days(string $setting, int $default): int
    {
        return max(0, (int) config("settings.{$setting}", $default));
    }

    /**
     * No hold (a hold holds through its last day), no open invoice of theirs with a processing payment (PAY-9), and no
     * paid invoice of theirs still to be applied (BIL-9): its customer paid, the renewal is on its way. And a plan: one
     * without is never billed (BIL-6), so never dunned either. $holdDay: those held until that day instead (the notice
     * before it ends, CronJob::holdsEnding). An addon whose parent is held is held with it, either way: it follows its
     * parent (ADV-A3-1/2, Service::holdInForce).
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    private static function unheldWithNoPaymentOnItsWay(Builder $query, Carbon $asOf, ?Carbon $holdDay = null): Builder
    {
        $query = $holdDay !== null
            ? Service::whereNoHoldOn($query->whereDate('suspend_hold_until', $holdDay->toDateString()), $asOf->toDateString(), own: false)
            : Service::whereNoHoldOn($query, $asOf->toDateString());

        return $query->whereNotNull('plan_id')
            ->whereDoesntHave('invoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_UNPAID)
                ->whereHas('transactions', fn ($payments) => $payments->where('status', InvoiceTransactionStatus::Processing)))
            ->whereNotExists(fn ($line) => RenewalBilling::paidLineToApply($line));
    }
}

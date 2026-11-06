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
     * With $overdueInvoice: and an open invoice of theirs due and issued Suspend Days ago (a missing due date counts from
     * its issue). With $holdDay: those held until that day instead, suspendable the day after it (code review 3).
     *
     * @return Builder<Service>
     */
    public static function suspendable(Carbon $asOf, bool $overdueInvoice = false, ?Carbon $holdDay = null): Builder
    {
        $asOf = $holdDay !== null ? $holdDay->copy()->addDay() : $asOf->copy()->startOfDay();
        $cutoff = $asOf->copy()->subDays(self::days('cronjob_order_suspend', 8))->toDateString();
        $query = self::unheldWithNoPaymentOnItsWay(Service::query()->where('status', Service::STATUS_ACTIVE), $asOf, $holdDay)
            ->whereDate('expires_at', '<', $cutoff)
            ->whereDoesntHave('plan', fn ($plan) => $plan->whereIn('type', ['free', 'one-time']))
            ->whereDoesntHave('client', fn ($client) => Client::whereFlag($client, 'dunning_exempt'));

        return !$overdueInvoice ? $query : $query->whereHas('invoices', fn ($invoices) => $invoices
            ->where('invoices.status', Invoice::STATUS_UNPAID)
            ->whereRaw('DATE(COALESCE(invoices.due_at, invoices.created_at)) < ?', [$cutoff])
            ->whereDate('invoices.created_at', '<', $cutoff));
    }

    /**
     * Suspended for non-payment, past Termination Days, suspended at least «minimum days suspended» ago, not held, not of
     * a client exempt from it, with no payment on its way (a SEPA payment would land on the invoices the termination
     * cancels), and no later period already paid (ending it would keep that money without the period: staff decide).
     *
     * @return Builder<Service>
     */
    public static function terminable(Carbon $asOf): Builder
    {
        $asOf = $asOf->copy()->startOfDay();

        return self::unheldWithNoPaymentOnItsWay(Service::query()->where('status', Service::STATUS_SUSPENDED), $asOf)
            ->whereNotNull('dunning_suspended_at')
            ->whereDoesntHave('client', fn ($client) => Client::whereFlag($client, 'dunning_exempt'))
            ->whereDate('expires_at', '<', $asOf->copy()->subDays(self::days('cronjob_order_terminate', 60))->toDateString())
            ->whereDate('dunning_suspended_at', '<=', $asOf->copy()->subDays(self::days('cronjob_terminate_min_suspended_days', 1))->toDateString())
            ->whereNotExists(fn ($line) => $line->from('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoice_items.reference_type', Service::class)->whereColumn('invoice_items.reference_id', 'services.id')
                ->whereColumn('invoice_items.period_start', '>=', 'services.expires_at')
                ->where('invoices.status', Invoice::STATUS_PAID));
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

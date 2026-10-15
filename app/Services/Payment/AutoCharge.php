<?php

namespace App\Services\Payment;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The cron's charge of saved payment methods (PR H, decision 5, docs/payments.md «Αυτόματη χρέωση»), as WHMCS here
 * (CCProcessDaysBefore 0, a retry every day): an open invoice of a customer with «Auto-charge» on is charged on its due
 * date (or autocharge_days_before earlier), and a declined charge is tried again every day, or on the days set, until it
 * is paid or its service is suspended. Never before the invoice is due, never twice a day, never while a payment is on its
 * way, never after the bank asked the customer to confirm (they pay it themselves), and not once the method has failed
 * autocharge_max_failures times in a row (staff are told; the method is kept).
 *
 * Only renewal invoices of the customer's services, all active and not paid by a gateway subscription: an order or an
 * invoice staff made is paid by the customer (as before).
 */
final class AutoCharge
{
    /** @var list<string> declined charges of this run, for staff */
    private array $declined = [];

    /**
     * @param  callable(int, Throwable): void|null  $failed  an invoice the run could not handle (reported to staff)
     * @return int the invoices charged
     */
    public function run(?callable $failed = null): int
    {
        $this->declined = [];
        $today = CarbonImmutable::today();
        $charged = 0;
        foreach ($this->due($today)->pluck('id') as $id) {
            try {
                $charged += $this->chargeOne((int) $id, $today) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
                if ($failed !== null) {
                    $failed((int) $id, $e);
                }
            }
        }
        if ($this->declined !== []) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges declined', e(sprintf(
                "%d automatic charge(s) were declined today and will be tried again as set:\n%s", count($this->declined), implode("\n", $this->declined))));
        }

        return $charged;
    }

    /** @return Builder<Invoice> open renewal invoices due by today (+ days before), of customers with «Auto-charge» on */
    private function due(CarbonImmutable $today): Builder
    {
        $notCharged = Service::query()->select('id')->where(fn ($query) => $query->where('status', '!=', Service::STATUS_ACTIVE)
            ->orWhere(fn ($subscribed) => $subscribed->whereNotNull('subscription_id')->where('subscription_id', '!=', '')));

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNull('finish_pending_at')
            ->whereDate('due_at', '<=', $today->addDays(self::daysBefore())->toDateString())
            ->whereHas('client', fn ($client) => $client->where('auto_charge', true)->whereNotNull('default_billing_agreement_id'))
            ->whereHas('items', fn ($items) => $items->where('reference_type', Service::class))
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Service::class)->whereIn('reference_id', $notCharged))
            ->orderBy('due_at')->orderBy('id');
    }

    private function chargeOne(int $id, CarbonImmutable $today): bool
    {
        $invoice = Invoice::query()->with('client')->find($id);
        $agreement = $invoice?->client?->autoChargeAgreement();
        if ($invoice === null || $agreement === null || $invoice->remaining <= 0) {
            return false;
        }
        $max = max(0, (int) config('settings.autocharge_max_failures', 0));
        if ($max > 0 && $agreement->failed_count >= $max) {
            return false;
        }
        $attempts = ChargeAttempt::query()->where('invoice_id', $invoice->id);
        // The bank asked the customer to confirm: they pay it themselves.
        if ((clone $attempts)->latest('id')->value('status') === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            return false;
        }
        $cron = (clone $attempts)->where('source', ChargeAttempt::SOURCE_CRON);
        if ((clone $cron)->where('started_at', '>=', $today)->exists()) {
            return false; // once a day, also when the cron runs twice
        }
        $offset = (int) $invoice->due_at->startOfDay()->diffInDays($today, false);
        if ((clone $cron)->exists() && !self::retryOn($offset)) {
            return false;
        }

        try {
            $attempt = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CRON);
        } catch (DisplayException) {
            return false; // a payment on its way, or nothing left to pay: nothing was sent
        }
        if ($attempt->status === ChargeAttempt::STATUS_SUCCEEDED) {
            return true;
        }

        $this->declined[] = sprintf('Invoice #%d (%s %s, customer #%d): %s', $invoice->id, $attempt->amount, $attempt->currency_code,
            $invoice->client_id, $attempt->error ?? 'declined');
        // The customer hears of it on the first decline, the last try before suspension and when the bank asks them to
        // confirm: not on every daily retry (Π7.7).
        $first = (clone $cron)->whereIn('status', [ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_ACTION_REQUIRED])->count() === 1;
        $suspendDays = (int) config('settings.cronjob_order_suspend', 8);
        $lastTry = (bool) config('settings.cronjob_suspend_enabled', true) && $offset === $suspendDays;
        if ($first || $lastTry || $attempt->status === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
        }
        $failures = (int) $agreement->fresh()?->failed_count;
        if ($max > 0 && $failures >= $max && Cache::add("autocharge-stopped:{$agreement->id}", true, now()->addDay())) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges stopped', e(sprintf(
                'The saved payment method #%d (%s) of customer #%d was declined %d times in a row: it is no longer charged automatically until a charge succeeds or the customer pays with it.',
                $agreement->id, $agreement->name, $invoice->client_id, $failures)));
        }

        return false;
    }

    private static function daysBefore(): int
    {
        return max(0, (int) config('settings.autocharge_days_before', 0));
    }

    /** A retry today, $offset days after the due date: every day (empty setting), or the days set. */
    private static function retryOn(int $offset): bool
    {
        $days = array_values(array_filter(array_map('trim', explode(',', (string) config('settings.autocharge_retry_days', ''))),
            fn (string $day) => $day !== '' && ctype_digit($day)));

        return $days === [] || in_array((string) $offset, $days, true);
    }
}

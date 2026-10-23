<?php

namespace App\Services\Payment;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\DunningWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The cron's charge of saved payment methods (PR H, decision 5, docs/payments.md «Αυτόματη χρέωση»), as WHMCS here
 * (CCProcessDaysBefore 0, a retry every day): an open invoice of a customer with «Auto-charge» on is charged on its due
 * date (or autocharge_days_before earlier), and a declined charge is tried again every day, or on the days set, until it
 * is paid or its service is suspended, and no later than the retry window (the suspension day, or the last retry day
 * set). Never twice a day, never while a payment is on its way, never on a method after the bank asked the customer to
 * confirm with it (they pay it themselves, or save another), and not once the method was declined on
 * autocharge_max_failures days in a row (staff are told; the method is kept).
 *
 * Only renewal invoices of the customer's services, all active and not paid by a gateway subscription: an order or an
 * invoice staff made is paid by the customer (as before).
 */
final class AutoCharge
{
    /**
     * @param  callable(int, Throwable): void|null  $failed  an invoice the run could not handle (reported to staff)
     * @return int the invoices charged
     */
    public function run(?callable $failed = null): int
    {
        $declined = []; // this run's declined charges, for staff
        $today = CarbonImmutable::today();
        $charged = 0;
        foreach ($this->due($today)->pluck('id') as $id) {
            try {
                $charged += $this->chargeOne((int) $id, $today, $declined) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
                if ($failed !== null) {
                    $failed((int) $id, $e);
                }
            }
        }
        if ($declined !== []) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges declined', e(sprintf(
                "%d automatic charge(s) were declined today and will be tried again as set:\n%s", count($declined), implode("\n", $declined))));
        }
        $this->tellOfTakenNotRecorded();

        return $charged;
    }

    /**
     * The method the cron charges this invoice to, or null: the account's (Auto-charge on), its gateway still there, not
     * stopped at autocharge_max_failures, and not one the bank asked the customer to confirm a charge of this invoice
     * with (a new default method is charged; adversarial ADV-7), nor an invoice staff keep from it (PR H2b). Also what
     * the reminder before the due date tells the customer (PaymentReminders::chargedOnItsOwn, adversarial ADV-6).
     */
    public static function agreementFor(Invoice $invoice): ?BillingAgreement
    {
        if ($invoice->no_auto_charge) {
            return null; // staff keep it from being charged automatically (PR H2b)
        }
        $agreement = $invoice->client?->autoChargeAgreement();
        if ($agreement === null || $agreement->gateway === null) {
            return null;
        }
        $max = max(0, (int) config('settings.autocharge_max_failures', 0));
        if ($max > 0 && $agreement->failed_count >= $max) {
            return null;
        }
        $last = ChargeAttempt::query()->where('invoice_id', $invoice->id)->where('billing_agreement_id', $agreement->id)->latest('id')->value('status');

        return $last === ChargeAttempt::STATUS_ACTION_REQUIRED ? null : $agreement;
    }

    /** @return Builder<Invoice> open renewal invoices due in the window, of customers with «Auto-charge» on */
    private function due(CarbonImmutable $today): Builder
    {
        $notCharged = Service::query()->select('id')->where(fn ($query) => $query->where('status', '!=', Service::STATUS_ACTIVE)
            ->orWhere(fn ($subscribed) => $subscribed->whereNotNull('subscription_id')->where('subscription_id', '!=', '')));

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNull('finish_pending_at')->where('no_auto_charge', false)
            ->whereDate('due_at', '<=', $today->addDays(self::daysBefore())->toDateString())
            // Not one overdue past the retry window: an old invoice is not charged at go-live, nor forever (adversarial ADV-5).
            // A suspension window (Settings → Automation) postpones a suspension 6 days at most: those days are in it too.
            ->whereDate('due_at', '>=', $today->subDays(self::window() + (DunningWindow::always() ? 0 : 7))->toDateString())
            ->whereHas('client', fn ($client) => $client->where('auto_charge', true)->whereNotNull('default_billing_agreement_id'))
            // A renewal: of a service, or of a domain (D5b); none of an ended domain.
            ->whereHas('items', fn ($items) => $items->whereIn('reference_type', [Service::class, Domain::class]))
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Service::class)->whereIn('reference_id', $notCharged))
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Domain::class)
                ->whereIn('reference_id', Domain::query()->select('id')->where(fn ($domain) => $domain->whereIn('status', DomainStatus::terminalValues())
                    ->orWhere('auto_renew', false))))
            // Not a new domain's registration or transfer (D8b, code review M1): an order is paid by its customer, as a
            // service's (pending services are not charged either); unpaid it is cancelled.
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Domain::class)
                ->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER]))
            ->orderBy('due_at')->orderBy('id');
    }

    /** @param list<string> $declined */
    private function chargeOne(int $id, CarbonImmutable $today, array &$declined): bool
    {
        $invoice = Invoice::query()->with('client')->find($id);
        $agreement = $invoice === null ? null : self::agreementFor($invoice);
        if ($invoice === null || $agreement === null || $invoice->remaining <= 0) {
            return false;
        }
        $cron = ChargeAttempt::query()->where('invoice_id', $invoice->id)->where('source', ChargeAttempt::SOURCE_CRON);
        if ((clone $cron)->where('sent_at', '>=', $today)->exists()) {
            return false; // once a day, also when the cron runs twice
        }
        $offset = (int) $invoice->due_at->startOfDay()->diffInDays($today, false);
        $postponed = self::postponedSuspendDays($invoice);
        if ($offset > max($postponed, self::window())) {
            return false; // past its window (the query's is wider while a suspension window may postpone it)
        }
        // The days a suspension window adds (no weekend, say) are tried every day, up to the morning it suspends.
        if ((clone $cron)->exists() && !self::retryOn($offset) && !($offset > self::suspendDays() && $offset <= $postponed)) {
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

        $declined[] = sprintf('Invoice #%d (%s %s, customer #%d): %s', $invoice->id, $attempt->amount, $attempt->currency_code,
            $invoice->client_id, $attempt->error ?? 'declined');
        if ($attempt->status === ChargeAttempt::STATUS_UNKNOWN) {
            return false; // maybe taken: sent again with its key, the customer is not told it failed
        }
        // The customer hears of it on the first decline, the last try before suspension and when the bank asks them to
        // confirm: not on every daily retry (Π7.7).
        $first = (clone $cron)->whereIn('status', [ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_ACTION_REQUIRED])->count() === 1;
        if ($first || $offset === self::lastTry($postponed) || $attempt->status === ChargeAttempt::STATUS_ACTION_REQUIRED) {
            NotificationHelper::invoicePaymentFailedNotification($invoice->client, $invoice);
        }
        $max = max(0, (int) config('settings.autocharge_max_failures', 0));
        $failures = (int) $agreement->fresh()?->failed_count;
        if ($max > 0 && $failures >= $max && Cache::add("autocharge-stopped:{$agreement->id}", true, now()->addDay())) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges stopped', e(sprintf(
                'The saved payment method #%d (%s) of customer #%d was declined on %d days in a row: it is no longer charged automatically until a charge succeeds or the customer pays with it.',
                $agreement->id, $agreement->name, $invoice->client_id, $failures)));
        }

        return false;
    }

    /**
     * Charges the gateway took whose payment was not recorded within an hour (Stripe's webhook late, failing, or its
     * secret wrong): the invoice stays held (SavedMethodCharge::TAKEN_HOLD_DAYS) and staff are told once each.
     */
    private function tellOfTakenNotRecorded(): void
    {
        $taken = ChargeAttempt::query()->where('status', ChargeAttempt::STATUS_SUCCEEDED)
            ->whereBetween('sent_at', [now()->subDays(SavedMethodCharge::TAKEN_HOLD_DAYS), now()->subHour()])->get()
            ->reject(fn (ChargeAttempt $attempt) => SavedMethodCharge::recordedSince($attempt))
            ->filter(fn (ChargeAttempt $attempt) => Cache::add("autocharge-unrecorded:{$attempt->id}", true, now()->addDays(SavedMethodCharge::TAKEN_HOLD_DAYS + 1)));
        if ($taken->isNotEmpty()) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges taken but not recorded', e(sprintf(
                "The gateway took these charges but no payment was recorded since (check its webhooks): the invoices are held, not charged again.\n%s",
                $taken->map(fn (ChargeAttempt $attempt) => "Invoice #{$attempt->invoice_id}: {$attempt->amount} {$attempt->currency_code}, attempt {$attempt->key}")->implode("\n"))));
        }
    }

    private static function daysBefore(): int
    {
        return max(0, (int) config('settings.autocharge_days_before', 0));
    }

    /** @return list<int> the retry days set, after the due date; empty: every day */
    private static function retryDays(): array
    {
        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) config('settings.autocharge_retry_days', ''))),
            fn (string $day) => $day !== '' && ctype_digit($day))));
    }

    /** A retry today, $offset days after the due date: every day (no days set), or the days set. */
    private static function retryOn(int $offset): bool
    {
        $days = self::retryDays();

        return $days === [] || in_array($offset, $days, true);
    }

    /** The days after the due date the cron still charges: to the suspension day, or to the last retry day set if later. */
    private static function window(): int
    {
        return max(self::suspendDays(), ...[0, ...self::retryDays()]);
    }

    /**
     * The offset of the day its suspension really comes when a suspension window postpones it (owner 2026-10-23: no
     * weekend), so the card is tried up to that morning; the nominal Suspend Days otherwise (review of #411).
     */
    private static function postponedSuspendDays(Invoice $invoice): int
    {
        $suspend = self::suspendDays();
        if (DunningWindow::always()) {
            return $suspend;
        }
        $due = Carbon::parse($invoice->due_at)->startOfDay();
        $nominal = $due->copy()->addDays($suspend + 1);
        $later = (int) $nominal->diffInDays(DunningWindow::firstDayFrom($nominal));

        return $later === 0 ? $suspend : $suspend + 1 + $later;
    }

    /** The day of the last try before the suspension: the day before it, or the last retry day set before it (ADV-8). */
    private static function lastTry(?int $suspend = null): int
    {
        $suspend ??= self::suspendDays();
        $days = self::retryDays();
        if ($days === []) {
            return $suspend;
        }
        $before = array_filter($days, fn (int $day) => $day <= $suspend);

        return $before === [] ? max($days) : max($before);
    }

    private static function suspendDays(): int
    {
        return max(0, (int) config('settings.cronjob_order_suspend', 8));
    }
}

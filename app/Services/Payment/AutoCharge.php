<?php

namespace App\Services\Payment;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Console\Commands\CronJob;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
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
 * Only renewal invoices of the customer's services, all active, not paid by a gateway subscription and with no
 * cancellation request (ADV-A2-1): an order or an invoice staff made is paid by the customer (as before). One of a
 * service or domain paid with another gateway is charged to the account's method of that gateway, as WHMCS does (wayOf,
 * owner 2026-11-01); one of two ways is not, nor a WHMCS one of a way with no saved method (no_auto_charge, set at
 * import).
 */
final class AutoCharge
{
    /** The last run's charges not taken (declined, or of unknown outcome): counted apart, as «charged 0» hid them. */
    public int $declined = 0;

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
        $this->declined = count($declined);
        if ($declined !== []) {
            // One line each, with the invoice and its customer linked in the admin (owner 2026-11-04: «Invoice #9000010,
            // customer #1» on one line told staff neither which invoice nor whose).
            NotificationHelper::sendSystemEmailNotification('Automatic charges declined', '<p>' . e(sprintf(
                '%d automatic charge(s) were declined today and will be tried again as set:', count($declined))) . '</p><ul><li>'
                . implode('</li><li>', $declined) . '</li></ul>');
        }
        $this->tellOfTakenNotRecorded();
        $this->tellOfHeldByCancellation($today);

        return $charged;
    }

    /**
     * A shared invoice held whole by a service of it with a cancellation request (ADV-A2-1; left open by CancelOpenInvoices,
     * with transfer money on it, say): its other services and domains are not charged either and go overdue (review C7 of
     * #695). Who is charged stays as is; staff are told once of each invoice, the day it is due for a charge, to split it
     * or settle it with the customer.
     */
    private function tellOfHeldByCancellation(CarbonImmutable $today): void
    {
        $held = $this->due($today, heldByCancellation: true)->with('items:id,invoice_id,reference_type,reference_id')->get()
            ->filter(fn (Invoice $invoice) => Cache::add("autocharge-held-by-cancellation:{$invoice->id}", true, now()->addDays(90)));
        if ($held->isEmpty()) {
            return;
        }
        $cancelled = ServiceCancellation::query()->whereIn('service_id', $held->flatMap(fn (Invoice $invoice) => $invoice->items
            ->where('reference_type', Service::class)->pluck('reference_id'))->filter()->unique()->values())->pluck('service_id')->map(fn ($id) => (int) $id)->all();
        NotificationHelper::sendSystemEmailNotification('Automatic charges held by a cancellation request: needs staff attention', e(sprintf(
            "These invoices are not charged automatically: a service on each has a cancellation request (its period is not charged), and the invoice also bills other services or domains, which go overdue unless it is split or paid another way.\n%s",
            $held->map(fn (Invoice $invoice) => sprintf('Invoice #%d, customer #%d: cancellation requested for service #%s', $invoice->id, $invoice->client_id,
                $invoice->items->where('reference_type', Service::class)->pluck('reference_id')->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => in_array($id, $cancelled, true))->unique()->implode(', #')))->implode("\n"))));
    }

    /**
     * The method the cron charges this invoice to, or null: the account's (Auto-charge on) for its lines' gateway (wayOf,
     * Client::autoChargeMethodFor: the default, or its method of another gateway its lines were billed with), its gateway still there and
     * enabled (ExtensionHelper::customerGateways()), not stopped at autocharge_max_failures, and not one the bank asked the
     * customer to confirm a charge of this invoice with (a new default method is charged; adversarial ADV-7), nor an
     * invoice staff keep from it (PR H2b). Also what the reminder before the due date tells the customer
     * (PaymentReminders::chargedOnItsOwn, adversarial ADV-6).
     */
    public static function agreementFor(Invoice $invoice): ?BillingAgreement
    {
        if ($invoice->no_auto_charge) {
            return null; // staff keep it from being charged automatically (PR H2b), or WHMCS made it of another way (#541)
        }
        if (self::itemKeptFromIt($invoice)) {
            // A service or domain of it set «Do not charge automatically», now (#568), or a service of it the customer
            // asked to cancel (ADV-A2-1): read again under the charge's lock.
            return null;
        }
        $client = $invoice->client;
        $way = $client === null ? false : self::wayOf($invoice, $client->autoChargeMethod());
        $agreement = $client === null || $way === false ? null : $client->autoChargeMethodFor($way);
        if ($agreement === null || $agreement->gateway === null) {
            return null;
        }
        // Its gateway disabled by staff: the customer pays the invoice another way. A charge of unknown outcome is still
        // resent with its key, which takes no new money (adversarial ADV-540-2).
        if (!ExtensionHelper::gatewayEnabled($agreement->gateway_id) && SavedMethodCharge::unknownAttempt($invoice->id, $agreement->id) === null) {
            return null;
        }
        if ($agreement->stopped()) {
            return null;
        }
        $last = ChargeAttempt::query()->where('invoice_id', $invoice->id)->where('billing_agreement_id', $agreement->id)->latest('id')->value('status');

        return $last === ChargeAttempt::STATUS_ACTION_REQUIRED ? null : $agreement;
    }

    /**
     * Whether the run picks the invoice up (due()): open, a renewal of active items, of an account with «Auto-charge» on and
     * a saved method; and, $today, due in its window today. agreementFor() then says which method, or none, and
     * chargeOne() may still skip it (tried today, not a retry day, a charge on its way). Read by the ops tools
     * (Ops\Charges), never by the run.
     */
    public static function picks(Invoice $invoice, bool $today = true): bool
    {
        return (new self)->due(CarbonImmutable::today(), $today)->whereKey($invoice->id)->exists();
    }

    /**
     * Whether the cron will charge it on its own (review SW-2 of the payment sweep, 2026-11-02): the run picks it up on some
     * day (a renewal, of active items, not kept from it), it is not past its retry window (chargeOne's own limit), and a
     * method charges it (agreementFor). What the customer is told («nothing to do»), the bank details left out and the
     * reminder's kind follow it: never for an invoice the cron leaves (an upgrade's charge alone, one overdue too long).
     */
    public static function willCharge(Invoice $invoice): bool
    {
        return self::chargeDay($invoice, false) !== null;
    }

    /**
     * The day the run charges it next, or null when it will not, as the invoice's page tells the customer
     * (docs/pay-flow-ux.md, PR 2). A day closer to the run than willCharge (review of #593): not today once today's run is
     * over and no hourly run charges later today, and a charge declined before the due date retried the next day. Neither
     * changes willCharge's answer for the reminders. Both leave out a retry day after the service's suspension.
     */
    public static function nextAttempt(Invoice $invoice): ?CarbonImmutable
    {
        return self::chargeDay($invoice, true);
    }

    private static function chargeDay(Invoice $invoice, bool $asRun): ?CarbonImmutable
    {
        // No due date: the run's dates never pick it (due()), so never charged on its own.
        if ($invoice->due_at === null || $invoice->remaining <= 0 || !self::picks($invoice, false) || self::agreementFor($invoice) === null) {
            return null;
        }
        $today = CarbonImmutable::today();
        $offset = (int) $invoice->due_at->copy()->startOfDay()->diffInDays($today, false);
        $postponed = self::postponedSuspendDays($invoice);
        $limit = max($postponed, self::window());
        // A service's invoice is charged up to the run that suspends the service (charges come first in it), then never:
        // due() leaves a suspended service. A retry day set after that never comes (adversarial ADV-593-2). The nominal
        // suspension comes the day after Suspend Days (suspendable(): expired before the cutoff), a postponed one on its day.
        if (config('settings.cronjob_suspend_enabled', true) && $invoice->items()->where('reference_type', Service::class)->exists()) {
            $limit = min($limit, $postponed > self::suspendDays() ? $postponed : self::suspendDays() + 1);
        }
        if ($offset > $limit) {
            return null;
        }
        $cron = ChargeAttempt::query()->where('invoice_id', $invoice->id)->where('source', ChargeAttempt::SOURCE_CRON);
        $triedToday = (clone $cron)->where('sent_at', '>=', $today)->exists();
        // Today's run is over (tried or not) and no hourly run charges later today: tomorrow at the earliest.
        $notToday = $asRun && ($triedToday || self::chargedToday());
        // Not tried yet: from the day due() first picks it (autocharge_days_before before its due date), or today.
        if (!(clone $cron)->exists()) {
            $day = max($offset + ($notToday ? 1 : 0), -self::daysBefore());

            return $day <= $limit ? $today->addDays($day - $offset) : null;
        }
        // Tried already: a day the run still tries, as chargeOne decides it (the retry days set, or a day a suspension
        // window adds), from tomorrow if it tried today (review 1 of #580: after the last retry it charges nothing more).
        // The run retries before the due date too (autocharge_days_before): the page counts those days (review of #593).
        $from = $notToday || $triedToday ? $offset + 1 : $offset;
        for ($day = $asRun ? $from : max(0, $from); $day <= $limit; $day++) {
            if (self::retryOn($day) || ($day > self::suspendDays() && $day <= $postponed)) {
                return $today->addDays($day - $offset);
            }
        }

        return null;
    }

    /**
     * Today's charges are done: the daily run got past them (DUNNING_READY or last_cron_run today), and no hourly run
     * charges later today (it charges only inside a suspension window, on its days: CronJob::runDunning).
     */
    private static function chargedToday(): bool
    {
        if (!DunningWindow::always() && in_array(CarbonImmutable::today()->isoWeekday(), DunningWindow::days(), true)) {
            return false;
        }

        return Setting::query()->whereIn('key', [CronJob::DUNNING_READY, 'last_cron_run'])->where('settingable_type', CronStat::class)
            ->pluck('value')->contains(function ($time) {
                try {
                    $at = CarbonImmutable::parse((string) $time);
                } catch (Throwable) {
                    return false;
                }

                return $at->isToday() && $at->lte(CarbonImmutable::now());
            });
    }

    /**
     * A line of a service or domain set «Do not charge automatically» (#568, by the customer, staff or the import): read
     * as it is now, not as billed, so turning it off stops the charge of its open invoice too. Such an item is billed on an
     * invoice apart, and turned off it leaves a shared open one (ItemPaymentMethod::keepApart); one it could not leave is
     * held whole, safer than taking money against the customer's choice. Or a line of a service with a cancellation
     * request (cancellationLines), in the same query (review C8 of #695).
     */
    private static function itemKeptFromIt(Invoice $invoice): bool
    {
        return $invoice->items()->where(fn ($lines) => $lines->where(fn ($kept) => ItemPaymentMethod::kept($kept))
            ->orWhere(fn ($cancelled) => self::cancellationLines($cancelled)))->exists();
    }

    /**
     * The lines of a service with a cancellation request (ADV-A2-1, as CreditAutoApply::eligible): its renewal left open
     * (part paid, an Ekdosi document, kept as debt, or before CancellationCreatedListener ran) is not charged for a period
     * the customer cancelled, which would renew the service against the request. A shared invoice is held whole: staff
     * are told (tellOfHeldByCancellation).
     *
     * @param  Builder<InvoiceItem>  $lines
     * @return Builder<InvoiceItem>
     */
    public static function cancellationLines(Builder $lines): Builder
    {
        return $lines->where('reference_type', Service::class)->whereIn('reference_id', ServiceCancellation::query()->select('service_id'));
    }

    /** Lines of two ways (a merge, an item's way changed on a shared open invoice): no method charges it. */
    public static function waysMixed(Invoice $invoice): bool
    {
        return self::wayOf($invoice, $invoice->client?->autoChargeMethod()) === false;
    }

    /**
     * The gateway whose saved method charges the invoice, by the way its lines were billed (InvoiceItem::payment_gateway_id,
     * #541; as billed, not as the item says now: an open invoice keeps the way it was made with), as WHMCS charges an
     * invoice with its own gateway's method: null, the account's (a service's or domain's line of the account's way, of its
     * default's gateway, or of a gateway since deleted); another gateway's id; false when its lines are of two ways (a
     * merge), which no method charges. A line of neither a service nor a domain says nothing unless it records a gateway.
     */
    private static function wayOf(Invoice $invoice, ?BillingAgreement $default): int|false|null
    {
        $account = $default === null ? null : (int) $default->gateway_id;
        $lines = $invoice->items()->get(['reference_type', 'reference_id', 'payment_gateway_id']);
        $recorded = $lines->pluck('payment_gateway_id')->filter()->unique()->values();
        $live = $recorded->isEmpty() ? [] : Gateway::query()->whereKey($recorded->all())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ways = [];
        foreach ($lines as $line) {
            $gateway = $line->payment_gateway_id !== null && in_array((int) $line->payment_gateway_id, $live, true) ? (int) $line->payment_gateway_id : null;
            if ($gateway === null && ($line->reference_id === null || !in_array($line->reference_type, [Service::class, Domain::class], true))) {
                continue;
            }
            $ways[$gateway === null || $gateway === $account ? 'account' : $gateway] = true;
        }
        if (count($ways) > 1) {
            return false;
        }
        $way = array_key_first($ways);

        return $way === null || $way === 'account' ? null : (int) $way;
    }

    /**
     * @return Builder<Invoice> open renewal invoices due in the window (any day, not $dated), of customers with «Auto-charge»
     *                          on. $heldByCancellation: instead, those it would pick but for a service of them with a cancellation request,
     *                          that also bill another service or a domain (C7 of #695: for staff)
     */
    private function due(CarbonImmutable $today, bool $dated = true, bool $heldByCancellation = false): Builder
    {
        $notCharged = Service::query()->select('id')->where(fn ($query) => $query->where('status', '!=', Service::STATUS_ACTIVE)
            ->orWhere(fn ($subscribed) => $subscribed->whereNotNull('subscription_id')->where('subscription_id', '!=', ''))
            // One the customer asked to cancel (ADV-A2-1, as CreditAutoApply::eligible): not charged for the period after.
            ->when(!$heldByCancellation, fn ($cancelled) => $cancelled->orWhereIn('id', ServiceCancellation::query()->select('service_id'))));

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNull('finish_pending_at')->where('no_auto_charge', false)
            ->when($dated, fn (Builder $dates) => $dates->whereDate('due_at', '<=', $today->addDays(self::daysBefore())->toDateString())
                // Not one overdue past the retry window: an old invoice is not charged at go-live, nor forever (adversarial
                // ADV-5). A suspension window (Settings → Automation) postpones a suspension 6 days at most: those days too.
                ->whereDate('due_at', '>=', $today->subDays(self::window() + (DunningWindow::always() ? 0 : 7))->toDateString()))
            // A saved method, the default or the one of its lines' gateway (agreementFor decides which).
            ->whereHas('client', fn ($client) => $client->where('auto_charge', true)->whereHas('billingAgreements'))
            // A renewal: of a service, or of a domain (D5b); none of an ended domain. A service's charge alone (an imported
            // upgrade or addon, #507) is not.
            ->whereHas('items', fn ($items) => $items->where('reference_type', Domain::class)->orWhere(fn ($line) => $line
                ->where('reference_type', Service::class)->where(fn ($kind) => $kind->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))))
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Service::class)->whereIn('reference_id', $notCharged))
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Domain::class)
                ->whereIn('reference_id', Domain::query()->select('id')->where(fn ($domain) => $domain->whereIn('status', DomainStatus::terminalValues())
                    ->orWhere('auto_renew', false))))
            // Not a new domain's registration or transfer (D8b, code review M1): an order is paid by its customer, as a
            // service's (pending services are not charged either); unpaid it is cancelled.
            ->whereDoesntHave('items', fn ($items) => $items->where('reference_type', Domain::class)
                ->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER]))
            ->when($heldByCancellation, fn (Builder $held) => $held->whereHas('items', fn ($items) => self::cancellationLines($items))
                ->whereHas('items', fn ($items) => $items->where(fn ($other) => $other->where('reference_type', Domain::class)
                    ->orWhere(fn ($service) => $service->where('reference_type', Service::class)->whereNotNull('reference_id')
                        ->whereNotIn('reference_id', ServiceCancellation::query()->select('service_id')))))
                ->whereDoesntHave('items', fn ($items) => ItemPaymentMethod::kept($items)))
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

        $declined[] = self::declinedLine($invoice, $attempt);
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
     * A declined charge's line in staff's digest (HTML): the invoice and its customer, each linked in the admin (as the chat
     * cards link them), the amount and the gateway's answer. A link that cannot be made (the panel's routes) leaves the
     * text alone: the customer's notice and the failure count after it never wait on a link (review of #624).
     */
    private static function declinedLine(Invoice $invoice, ChargeAttempt $attempt): string
    {
        $link = function (callable $url, string $text): string {
            try {
                return sprintf('<a href="%s">%s</a>', e($url()), e($text));
            } catch (Throwable $e) {
                report($e);

                return e($text);
            }
        };
        $invoiceLink = $link(fn () => InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin'),
            ($invoice->number ?: '#' . $invoice->id) . ($invoice->number ? ' (#' . $invoice->id . ')' : ''));
        $client = $invoice->client;
        $clientLink = $client === null ? e('customer #' . $invoice->client_id)
            : $link(fn () => ClientResource::getUrl('view', ['record' => $client], panel: 'admin'), trim((string) $client->name) . ' (#' . $client->id . ')');

        return sprintf('%s · %s · %s %s · %s', $invoiceLink, $clientLink, e((string) $attempt->amount), e((string) $attempt->currency_code),
            e($attempt->error ?? 'declined'));
    }

    /**
     * Charges not settled an hour after they were sent (predeploy S1): taken by the gateway with no payment recorded since
     * (Stripe's webhook late, failing, or its secret wrong), of unknown outcome, or sent with no answer (a crash). The
     * invoice stays held for every way of paying until staff settle it (record the payment, or «Resolve charge»: not
     * taken); staff are told of each, again every TAKEN_HOLD_DAYS days while it stays unsettled.
     */
    private function tellOfTakenNotRecorded(): void
    {
        // One query, whatever the history of charges (adversarial ADV-UNRES-6): its payment recorded is checked in SQL.
        $unsettled = SavedMethodCharge::notRecorded(ChargeAttempt::query()
            ->whereIn('status', [ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::STATUS_STARTED])
            ->where('sent_at', '<=', now()->subHour()))->orderBy('id')->get()
            ->filter(fn (ChargeAttempt $attempt) => Cache::add("autocharge-unrecorded:{$attempt->id}", true, now()->addDays(SavedMethodCharge::TAKEN_HOLD_DAYS)));
        if ($unsettled->isNotEmpty()) {
            NotificationHelper::sendSystemEmailNotification('Automatic charges not settled', e(sprintf(
                "These charges are not settled: taken with no payment recorded since (check the gateway's webhooks), or of unknown outcome (check at the gateway). Each invoice is held, never charged or paid again, until staff record its payment, or settle it with «Resolve charge» on it (not taken, or its payment recorded another way).\n%s",
                $unsettled->map(fn (ChargeAttempt $attempt) => "Invoice #{$attempt->invoice_id}: {$attempt->amount} {$attempt->currency_code}, {$attempt->status}, attempt {$attempt->key}")->implode("\n"))));
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
        // A suspension a window postponed is tried every day up to its morning: that morning is the last try (round 4
        // of #411), whatever the retry days.
        if ($suspend !== null && $suspend > self::suspendDays()) {
            return $suspend;
        }
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

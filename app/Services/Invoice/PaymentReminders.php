<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainReminders;
use App\Services\Payment\AutoCharge;
use App\Support\DunningWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Payment reminders, the cron's «reminders_sent» step (audit sweep Σ4; WHMCS's «Invoice Unpaid Reminder», «Credit Card
 * Payment Due» and the overdue notices, simplified to one overdue template sent on the days the company sets).
 *
 * - Before the due date: cronjob_payment_reminder days before (0 = off). An invoice paid automatically (its service has
 *   a saved payment method or a gateway subscription) gets «card_payment_due» instead: what will be charged and when.
 * - After it: invoice_overdue on each day of cronjob_overdue_reminders («1,2»; empty = off), saying how late it is and,
 *   on the last one, when the service is suspended.
 *
 * An invoice that only renews domains with expiry notices gets none of these: their notices name it (D7).
 *
 * Each reminder goes once (invoice_reminders), whatever runs the cron misses (a run missed is caught up within
 * CATCH_UP_DAYS, not later: an old invoice is not reminded of a day long past) or repeats. Only open invoices with
 * something left to pay and a customer; not a proforma draft, nor one with a payment on its way.
 */
class PaymentReminders
{
    public const CATCH_UP_DAYS = 3;

    /** Its «new invoice» mail went (on `due_on`): no «due soon» reminder that day. */
    public const MAILED = 'mailed';

    /** Its «new invoice» mail went today (or went again). */
    public static function mailedToday(Invoice $invoice): void
    {
        DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => self::MAILED, 'days' => 0,
            'due_on' => Carbon::today()->toDateString(), 'sent_at' => now()]);
    }

    /** The latest overdue day the setting takes (a year): more is ignored, never an overflow that stops them all. */
    public const MAX_DAYS = 365;

    /** @param  (callable(int, Throwable): void)|null  $failed  told of an invoice whose reminder failed */
    public function send(?callable $failed = null): int
    {
        // Mail off for everyone (Settings): none is taken as sent, they go once mail is back, within the catch-up days
        // (as BulkMailer keeps its recipients waiting; L-NOTIFICATIONS-3).
        if (config('settings.mail_disable')) {
            return 0;
        }
        $today = Carbon::today();
        $sent = 0;

        $before = (int) config('settings.cronjob_payment_reminder', 6);
        if ($before > 0) {
            // Due within $before days, issued before today and not mailed today (not the day its own «new invoice» mail
            // went: an invoice dated earlier but made, published or sent again today; adversarial R2-1/R2-2 of #367).
            $due = $this->open()
                ->whereDate('due_at', '>', $today)
                ->whereDate('due_at', '<=', $today->copy()->addDays($before))
                ->whereDate('created_at', '<', $today)
                ->whereNotExists(fn ($query) => $query->from('invoice_reminders')->whereColumn('invoice_reminders.invoice_id', 'invoices.id')
                    ->where('kind', self::MAILED)->whereDate('due_on', $today))
                ->get();
            $this->known($due);
            foreach ($due as $invoice) {
                $sent += $this->remind($invoice, 'due', 0, $failed);
            }
        }

        // Overdue: the latest notice day each invoice has reached (within the catch-up days); a run missed sends that
        // one only, the earlier days count as sent.
        $days = self::overdueDays();
        if ($days !== []) {
            $overdue = $this->open()
                ->whereDate('due_at', '<=', $today->copy()->subDays(min($days)))
                ->whereDate('due_at', '>', $today->copy()->subDays(max($days) + self::CATCH_UP_DAYS))
                ->get();
            $this->known($overdue);
            foreach ($overdue as $invoice) {
                // «Disable overdue notices» on the account or its group (WHMCS): the ones before the due date still go.
                if (!$invoice->client->remindedWhenOverdue()) {
                    continue;
                }
                $late = (int) $invoice->due_at->copy()->startOfDay()->diffInDays($today);
                $reached = array_values(array_filter($days, fn (int $day) => $day <= $late));
                $day = end($reached);
                if ($day === false || $late - $day >= self::CATCH_UP_DAYS) {
                    continue;
                }
                $sent += $this->remind($invoice, 'overdue', $day, $failed, last: $day === max($days), skipped: array_slice($reached, 0, -1), late: $late);
            }
        }

        return $sent;
    }

    /** @return list<int> the overdue reminder days, ascending, without repeats («1, 2» → [1, 2]) */
    public static function overdueDays(): array
    {
        $days = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string) config('settings.cronjob_overdue_reminders', '1,2'))) ?: []), fn (int $day) => $day > 0 && $day <= self::MAX_DAYS);
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * Open invoices someone owes: unpaid (not a proforma draft), with an open account and a due date, no payment on its way,
     * and not only an offer to buy credit (an «add funds» invoice left at the gateway: nothing is owed, review R6/A5).
     *
     * @return Builder<Invoice>
     */
    private function open()
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNotNull('client_id')->whereNotNull('due_at')
            // Not a closed account's (WG-8): it is mailed nothing on its own, and its reminders wait should it be opened
            // again (L-NOTIFICATIONS-1).
            ->whereHas('client', fn ($client) => $client->whereNull('closed_at'))
            ->whereDoesntHave('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Processing))
            ->whereHas('items', fn ($items) => $items->where(fn ($line) => $line->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class)))
            ->with(['client.primaryContact', 'client.ownerGrant.user', 'client.group', 'items.reference', 'transactions']);
    }

    /** @var array<string, string> the latest due date each reminder went for, by «id|kind|days» */
    private array $known = [];

    /** @param  Collection<int, Invoice>  $invoices */
    private function known(Collection $invoices): void
    {
        foreach (DB::table('invoice_reminders')->whereIn('invoice_id', $invoices->pluck('id'))->get() as $row) {
            $key = "{$row->invoice_id}|{$row->kind}|{$row->days}";
            $this->known[$key] = max($this->known[$key] ?? '', (string) $row->due_on);
        }
    }

    /** Sent already for this due date or a later one (moved earlier: not again; moved later: again, review R4). */
    private function sentFor(Invoice $invoice, string $kind, int $days, string $dueOn): bool
    {
        return ($this->known["{$invoice->id}|{$kind}|{$days}"] ?? '') >= $dueOn;
    }

    /** Record a reminder sent by hand (the invoice page's «Send Email», review A9): the cron does not send it again. */
    public static function recordSent(Invoice $invoice, string $kind, int $days): void
    {
        if ($invoice->due_at !== null) {
            DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $days,
                'due_on' => $invoice->due_at->toDateString(), 'sent_at' => now()]);
        }
    }

    /** A reminder staff sent with «Send reminder» (on `due_on`, the day sent): once a day per invoice. */
    public const BY_STAFF = 'staff';

    /**
     * Staff's «Send reminder» on the invoice lists (owner 2026-11-18): the reminder the cron would send for it today, by
     * its rules (an open invoice with something left to pay, a mailable account, «Disable overdue notices», the domains'
     * own notices, the template of one charged on its own), whatever the day. Once a day per invoice (a second press, a
     * retry). Counted as the cron's: the due-soon reminder only within its days, so one sent weeks early does not stop it;
     * the overdue days reached, the last one with its suspension date as the cron's last notice.
     */
    public function sendNow(Invoice $invoice): bool
    {
        if (config('settings.mail_disable')) {
            return false;
        }
        $invoice = $this->open()->whereKey($invoice->id)->first();
        if ($invoice === null || $invoice->remaining <= 0 || $invoice->client === null) {
            return false;
        }
        $today = Carbon::today();
        $due = $invoice->due_at->copy()->startOfDay();
        $late = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
        $kind = $late > 0 ? 'overdue' : 'due';
        if (($kind === 'overdue' && !$invoice->client->remindedWhenOverdue()) || DomainReminders::covers($invoice, $kind)) {
            return false;
        }
        // Mailed today already, by the cron, its «new invoice» mail or staff (re-review of #913): not the same mail twice.
        if (DB::table('invoice_reminders')->where('invoice_id', $invoice->id)->where('sent_at', '>=', $today)->exists()) {
            return false;
        }
        $days = self::overdueDays();
        $key = $kind === 'due' ? (self::chargedOnItsOwn($invoice) ? 'card_payment_due' : 'invoice_payment_reminder') : 'invoice_overdue';
        // Sent as the cron sends it, preferences and all (review 4 of #913): an account whose primary takes no invoice
        // emails still has its contacts that do, and the owner's in-app notice.
        $row = ['invoice_id' => $invoice->id, 'kind' => self::BY_STAFF, 'days' => 0, 'due_on' => $today->toDateString()];
        if (DB::table('invoice_reminders')->insertOrIgnore($row + ['sent_at' => now()]) === 0) {
            return false;
        }
        // The cron's own rows, taken before the mail as remind() takes them (rev3 M1): a cron run reaching it meanwhile
        // finds them and sends nothing. The due-soon one only within its days (one sent weeks early does not stop it); the
        // overdue days reached. The latest taken today by the cron first: it is mailing it, staff send nothing.
        $before = (int) config('settings.cronjob_payment_reminder', 6);
        $cron = [];
        if ($kind === 'due' && $due->gt($today) && $before > 0 && $due->lte($today->copy()->addDays($before))) {
            $cron[] = ['kind' => 'due', 'days' => 0];
        }
        foreach ($kind === 'overdue' ? $days : [] as $day) {
            if ($day <= $late) {
                $cron[] = ['kind' => 'overdue', 'days' => $day];
            }
        }
        $taken = [$row];
        foreach ($cron as $i => $claim) {
            $claim += ['invoice_id' => $invoice->id, 'due_on' => $invoice->due_at->toDateString()];
            if (DB::table('invoice_reminders')->insertOrIgnore($claim + ['sent_at' => now()]) === 1) {
                $taken[] = $claim;
            } elseif ($i === array_key_last($cron) && DB::table('invoice_reminders')->where($claim)->where('sent_at', '>=', $today)->exists()) {
                foreach ($taken as $mine) {
                    DB::table('invoice_reminders')->where($mine)->delete();
                }

                return false;
            }
        }
        // The last notice only within the cron's catch-up days; later, a plain overdue notice (no «last» every press).
        $last = $kind === 'overdue' && $days !== [] && $late >= max($days) && $late - max($days) < self::CATCH_UP_DAYS;
        try {
            NotificationHelper::invoiceNotification($invoice->client, $invoice, $key, $kind === 'due' ? [] : ['overdue' => [
                'days' => $late, 'last' => $last, 'suspend_on' => $last ? self::suspendOn($invoice) : null,
            ]]);
        } catch (Throwable $e) {
            foreach ($taken as $mine) {
                DB::table('invoice_reminders')->where($mine)->delete();
            }
            report($e);

            return false;
        }

        return true;
    }

    /** @param  list<int>  $skipped  earlier overdue days a missed run passed: marked sent, not sent */
    private function remind(Invoice $invoice, string $kind, int $days, ?callable $failed, bool $last = false, array $skipped = [], int $late = 0): int
    {
        // Keyed by the due date too (review R4): staff moving it later (an extension) start its reminders again.
        $dueOn = $invoice->due_at->toDateString();
        if ($this->sentFor($invoice, $kind, $days, $dueOn)) {
            return 0;
        }
        // Read again (review A7): a run renders a PDF per mail and lasts; staff may have cancelled it, or a payment
        // started, meanwhile.
        $invoice = $this->open()->whereKey($invoice->id)->first();
        if ($invoice === null || $invoice->due_at->toDateString() !== $dueOn || $invoice->remaining <= 0 || $invoice->client === null) {
            return 0;
        }
        // Only renewals of domains that get their own expiry notices (D7): those name this invoice, one mail not two.
        if (DomainReminders::covers($invoice, $kind)) {
            return 0;
        }
        $row = ['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $days, 'due_on' => $dueOn];
        // Taken first (unique): a second run, or one running at the same time, sends nothing again.
        $taken = DB::table('invoice_reminders')->insertOrIgnore($row + ['sent_at' => now()]);
        if ($taken === 0) {
            return 0;
        }
        foreach ($skipped as $day) {
            DB::table('invoice_reminders')->insertOrIgnore(['days' => $day] + $row + ['sent_at' => now()]);
        }
        try {
            if ($kind === 'due') {
                $key = self::chargedOnItsOwn($invoice) ? 'card_payment_due' : 'invoice_payment_reminder';
                NotificationHelper::invoiceNotification($invoice->client, $invoice, $key);
            } else {
                NotificationHelper::invoiceNotification($invoice->client, $invoice, 'invoice_overdue', ['overdue' => [
                    'days' => max($days, $late), // how late it really is (a missed run, review A4)
                    'last' => $last,
                    'suspend_on' => $last ? self::suspendOn($invoice) : null,
                ]]);
            }
        } catch (Throwable $e) {
            // Not sent: tried again on the next run (within the catch-up days).
            DB::table('invoice_reminders')->where($row)->delete();
            report($e);
            if ($failed !== null) {
                $failed($invoice->id, $e);
            }

            return 0;
        }

        return 1;
    }

    /**
     * Charged on the due date without the customer (review R1, PR H): a gateway subscription charges it on its own
     * schedule, and the account's saved method is charged by the cron on the due date (AutoCharge) when all its services
     * are active. Then the reminder says «it will be charged», not «please pay».
     */
    public static function chargedOnItsOwn(Invoice $invoice): bool
    {
        $services = self::services($invoice);
        if ($services->contains(fn (Service $service) => (string) $service->subscription_id !== '')) {
            return true;
        }

        // The cron's own rules, not a copy of them (review SW-2 of the payment sweep): a renewal of active services and
        // renewing domains (D5b; not a new domain's order, D8b), not an upgrade's charge alone, not past its retry window,
        // with a method to charge (AutoCharge::willCharge).
        return AutoCharge::willCharge($invoice);
    }

    /**
     * The day the cron suspends the first of its services for it (review R2, R3), or null when it would not: the same
     * rules as CronJob::suspendable() (active, a plan that is billed, not held then; suspended the day after Suspend
     * Days past its due date), and only a day still to come.
     */
    public static function suspendOn(Invoice $invoice, bool $renewedOnly = false): ?string
    {
        if (!config('settings.cronjob_suspend_enabled', true)) {
            return null;
        }
        // Never suspended by the cron (its account or its group exempt): no date to tell.
        if ($invoice->client?->dunningExempt()) {
            return null;
        }
        $days = max(0, (int) config('settings.cronjob_order_suspend', 8));
        $services = self::services($invoice);
        if ($renewedOnly) {
            // A mail that says «pay it by …»: only the services whose period it bills; paying a charge on another does not
            // move that one's day (review of #897, REV2-897-1).
            $services = $services->filter(fn (Service $service) => $invoice->items->contains(fn (InvoiceItem $item) => $item->reference_type === Service::class
                && (int) $item->reference_id === (int) $service->id && $item->kind !== InvoiceItem::KIND_CHARGE))->values();
        }
        EloquentCollection::make($services->all())->loadMissing('parent'); // an addon's hold is its parent's too (ADV-A3-1)
        $on = $services
            ->filter(fn (Service $service) => $service->status === Service::STATUS_ACTIVE && $service->plan_id !== null && $service->expires_at !== null
                && !in_array($service->plan?->type, ['free', 'one-time'], true))
            ->map(function (Service $service) use ($days, $invoice) {
                $day = Carbon::parse($service->expires_at)->startOfDay()->addDays($days + 1);
                // «Suspend only with an overdue invoice»: the invoice too must be due and issued Suspend Days ago, as
                // CronJob::suspendable() asks (sweep by simulation 2026-10-24: an invoice issued late told a day too early).
                if (config('settings.cronjob_suspend_requires_invoice', false)) {
                    $day = $day->max(self::billedSuspendDay($invoice, $service, $days));
                }
                // On the first day the suspensions' window allows (Settings → Automation: no weekend, say).
                $day = DunningWindow::firstDayFrom($day);
                $held = $service->holdInForce($day); // its own hold or its parent's (ADV-A3-1)

                return $held || $day->lte(Carbon::today()) ? null : $day;
            })
            ->filter()
            ->min();

        return $on?->format('d/m/Y');
    }

    /**
     * With «suspend only with an overdue invoice»: the first day an invoice billing the service lets the cron suspend it
     * (due and issued Suspend Days ago). A charge alone is not one (renewalInvoices, #507, CRON-BILLING-1): on a charge's
     * notice, the service's own open renewal invoices count instead, or the renewal the cron bills on its next run when
     * none is open (as DunningForecast foretells it: due on the expiry, issued Invoice Generation days before; review REV-CB-2).
     */
    private static function billedSuspendDay(Invoice $invoice, Service $service, int $days): Carbon
    {
        $bills = $invoice->items->contains(fn ($item) => $item->reference_type === Service::class && (int) $item->reference_id === (int) $service->id
            && $item->kind !== InvoiceItem::KIND_CHARGE);
        $dated = $bills ? [[$invoice->due_at ?? $invoice->created_at, $invoice->created_at]]
            : $service->renewalInvoices()->where('invoices.status', Invoice::STATUS_UNPAID)->get(['invoices.due_at', 'invoices.created_at'])
                ->map(fn (Invoice $open) => [$open->due_at ?? $open->created_at, $open->created_at])->all();
        if ($dated === []) {
            $expires = Carbon::parse($service->expires_at)->startOfDay();
            $billedOn = $expires->copy()->subDays(RenewalBilling::serviceInvoiceDays(RenewalBilling::billedPlan($service) ?? $service->plan))->max(Carbon::today());
            $dated = [[$expires, $billedOn]];
        }

        // The cron suspends on the first open invoice that qualifies: the earliest of them.
        return collect($dated)->map(function (array $at) use ($days) {
            $day = Carbon::today()->startOfDay();
            foreach ($at as $one) {
                if ($one !== null) {
                    $day = $day->max(Carbon::parse($one)->startOfDay()->addDays($days + 1));
                }
            }

            return $day;
        })->min();
    }

    /** @return Collection<int, Service> */
    private static function services(Invoice $invoice): Collection
    {
        return $invoice->items->where('reference_type', Service::class)->map->reference->filter(fn ($service) => $service instanceof Service)->values();
    }
}

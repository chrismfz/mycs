<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
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
 * Each reminder goes once (invoice_reminders), whatever runs the cron misses (a run missed is caught up within
 * CATCH_UP_DAYS, not later: an old invoice is not reminded of a day long past) or repeats. Only open invoices with
 * something left to pay and a customer; not a proforma draft, nor one with a payment on its way.
 */
class PaymentReminders
{
    public const CATCH_UP_DAYS = 3;

    /** The latest overdue day the setting takes (a year): more is ignored, never an overflow that stops them all. */
    public const MAX_DAYS = 365;

    /** @param  (callable(int, Throwable): void)|null  $failed  told of an invoice whose reminder failed */
    public function send(?callable $failed = null): int
    {
        $today = Carbon::today();
        $sent = 0;

        $before = (int) config('settings.cronjob_payment_reminder', 6);
        if ($before > 0) {
            // Due within $before days, issued before today (not the day its own «new invoice» mail went).
            $due = $this->open()
                ->whereDate('due_at', '>', $today)
                ->whereDate('due_at', '<=', $today->copy()->addDays($before))
                ->whereDate('created_at', '<', $today)
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
     * Open invoices someone owes: unpaid (not a proforma draft), with a customer and a due date, no payment on its way,
     * and not only an offer to buy credit (an «add funds» invoice left at the gateway: nothing is owed, review R6/A5).
     *
     * @return Builder<Invoice>
     */
    private function open()
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNotNull('user_id')->whereNotNull('due_at')
            ->whereDoesntHave('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Processing))
            ->whereHas('items', fn ($items) => $items->where(fn ($line) => $line->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class)))
            ->with(['user', 'items.reference', 'transactions']);
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
        if ($invoice === null || $invoice->due_at->toDateString() !== $dueOn || $invoice->remaining <= 0 || $invoice->user === null) {
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
                NotificationHelper::invoiceNotification($invoice->user, $invoice, $key);
            } else {
                NotificationHelper::invoiceNotification($invoice->user, $invoice, 'invoice_overdue', ['overdue' => [
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
     * A gateway subscription charges it on its own schedule (review R1): only then is «it will be charged on the due
     * date» true. A saved payment method (billing agreement) is charged once, when the cron makes the invoice: still
     * unpaid days later, that charge failed (the customer was told) and they must pay.
     */
    public static function chargedOnItsOwn(Invoice $invoice): bool
    {
        return self::services($invoice)->contains(fn (Service $service) => (string) $service->subscription_id !== '');
    }

    /**
     * The day the cron suspends the first of its services for it (review R2, R3), or null when it would not: the same
     * rules as CronJob::suspendable() (active, a plan that is billed, not held then; suspended the day after Suspend
     * Days past its due date), and only a day still to come.
     */
    private static function suspendOn(Invoice $invoice): ?string
    {
        if (!config('settings.cronjob_suspend_enabled', true)) {
            return null;
        }
        $days = max(0, (int) config('settings.cronjob_order_suspend', 8));
        $on = self::services($invoice)
            ->filter(fn (Service $service) => $service->status === Service::STATUS_ACTIVE && $service->plan_id !== null && $service->expires_at !== null
                && !in_array($service->plan?->type, ['free', 'one-time'], true))
            ->map(function (Service $service) use ($days) {
                $day = Carbon::parse($service->expires_at)->startOfDay()->addDays($days + 1);
                $held = $service->suspend_hold_until !== null && !Carbon::parse($service->suspend_hold_until)->lt($day);

                return $held || $day->lte(Carbon::today()) ? null : $day;
            })
            ->filter()
            ->min();

        return $on?->format('d/m/Y');
    }

    /** @return Collection<int, Service> */
    private static function services(Invoice $invoice): Collection
    {
        return $invoice->items->where('reference_type', Service::class)->map->reference->filter(fn ($service) => $service instanceof Service)->values();
    }
}

<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
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
            foreach ($overdue as $invoice) {
                $late = (int) $invoice->due_at->copy()->startOfDay()->diffInDays($today);
                $reached = array_values(array_filter($days, fn (int $day) => $day <= $late));
                $day = end($reached);
                if ($day === false || $late - $day >= self::CATCH_UP_DAYS) {
                    continue;
                }
                $sent += $this->remind($invoice, 'overdue', $day, $failed, last: $day === max($days), skipped: array_slice($reached, 0, -1));
            }
        }

        return $sent;
    }

    /** @return list<int> the overdue reminder days, ascending, without repeats («1, 2» → [1, 2]) */
    public static function overdueDays(): array
    {
        $days = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim((string) config('settings.cronjob_overdue_reminders', '1,2'))) ?: []), fn (int $day) => $day > 0);
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /** @return Builder<Invoice> */
    private function open()
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->whereNotNull('user_id')->whereNotNull('due_at')
            ->whereDoesntHave('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Processing));
    }

    /** @param  list<int>  $skipped  earlier overdue days a missed run passed: marked sent, not sent */
    private function remind(Invoice $invoice, string $kind, int $days, ?callable $failed, bool $last = false, array $skipped = []): int
    {
        if ($invoice->remaining <= 0 || $invoice->user === null) {
            return 0;
        }
        // Taken first (unique): a second run, or one running at the same time, sends nothing again.
        $taken = DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $days, 'sent_at' => now()]);
        if ($taken === 0) {
            return 0;
        }
        foreach ($skipped as $day) {
            DB::table('invoice_reminders')->insertOrIgnore(['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $day, 'sent_at' => now()]);
        }
        try {
            if ($kind === 'due') {
                $key = self::paidAutomatically($invoice) ? 'card_payment_due' : 'invoice_payment_reminder';
                NotificationHelper::invoiceNotification($invoice->user, $invoice, $key);
            } else {
                NotificationHelper::invoiceNotification($invoice->user, $invoice, 'invoice_overdue', ['overdue' => [
                    'days' => $days,
                    'last' => $last,
                    'suspend_on' => $last ? self::suspendOn($invoice) : null,
                ]]);
            }
        } catch (Throwable $e) {
            // Not sent: tried again on the next run (within the catch-up days).
            DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => $kind, 'days' => $days])->delete();
            report($e);
            if ($failed !== null) {
                $failed($invoice->id, $e);
            }

            return 0;
        }

        return 1;
    }

    /** Its services are charged without the customer (a saved payment method, a gateway subscription). */
    public static function paidAutomatically(Invoice $invoice): bool
    {
        return self::services($invoice)->contains(fn (Service $service) => $service->billing_agreement_id !== null || $service->subscription_id !== null);
    }

    /** When the first of its services is suspended for it (as the cron does: Suspend Days past the due date), or null. */
    private static function suspendOn(Invoice $invoice): ?string
    {
        if (!config('settings.cronjob_suspend_enabled', true)) {
            return null;
        }
        $expires = self::services($invoice)->where('status', Service::STATUS_ACTIVE)->pluck('expires_at')->filter()->min();

        return $expires === null ? null : Carbon::parse($expires)->addDays((int) config('settings.cronjob_order_suspend', 8))->format('d/m/Y');
    }

    /** @return Collection<int, Service> */
    private static function services(Invoice $invoice): Collection
    {
        return $invoice->items->where('reference_type', Service::class)->map->reference->filter(fn ($service) => $service instanceof Service)->values();
    }
}

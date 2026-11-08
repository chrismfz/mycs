<?php

namespace App\Services\Ops;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Audit;
use App\Models\ChargeAttempt;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the cron did on a day, from what is already kept (owner 2026-11-06, ROADMAP 9β-4: the cut-over's first days, read
 * each morning): its own counts (cron_stats), the invoices issued, the charges of saved methods, the other payments, the
 * services suspended and terminated (their audits), the module actions opened and the mails sent. Read only.
 */
final class CronDay
{
    /** Lines listed per part; the rest counted. */
    public const LIMIT = 50;

    /**
     * The calendar's cells: per day, the cron's own counts, and whether something failed or waits for staff.
     *
     * @return array<string, array{ran: bool, invoices: int, charged: int, declined: int, suspended: int, terminated: int, attention: bool}>
     */
    public static function month(Carbon $month): array
    {
        return self::range($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
    }

    /**
     * The cells of the days from $from to $to.
     *
     * @return array<string, array{ran: bool, invoices: int, charged: int, declined: int, suspended: int, terminated: int, attention: bool}>
     */
    public static function range(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $days = [];
        // whereDate: the date cast stores «Y-m-d 00:00:00» where the column is not a DATE (SQLite), past a one-day range's end.
        $stats = CronStat::query()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('date, `key`, SUM(value) as total')->groupBy('date', 'key')->get();
        foreach ($stats as $stat) {
            $day = Carbon::parse($stat->date)->toDateString();
            $days[$day] ??= self::emptyCell();
            $days[$day]['ran'] = true;
            $field = ['invoices_created' => 'invoices', 'invoice_charged' => 'charged', 'charges_declined' => 'declined',
                'services_suspended' => 'suspended', 'services_terminated' => 'terminated'][$stat->key] ?? null;
            if ($field !== null) {
                $days[$day][$field] += (int) $stat->getAttribute('total');
            }
        }
        // A «check this» task is not a failure (owner 2026-11-08): no red day for it.
        $attention = ModuleAction::query()->whereBetween('created_at', [$from, $to])->where('status', ModuleAction::STATUS_OPEN)->failures()
            ->selectRaw('DATE(created_at) as day')->distinct()->pluck('day')
            ->merge(ChargeAttempt::query()->whereBetween('created_at', [$from, $to])->where('source', ChargeAttempt::SOURCE_CRON)->whereNotIn('status', [ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_SETTLED])
                ->selectRaw('DATE(created_at) as day')->distinct()->pluck('day'));
        foreach ($attention as $day) {
            $day = Carbon::parse($day)->toDateString();
            $days[$day] ??= self::emptyCell();
            $days[$day]['attention'] = true;
        }

        return $days;
    }

    /**
     * One day's detail.
     *
     * @return array{
     *     stats: array<string, int>,
     *     invoices: array{count: int, rows: Collection<int, Invoice>},
     *     charges: array{count: int, rows: Collection<int, ChargeAttempt>},
     *     payments: array{count: int, rows: Collection<int, InvoiceTransaction>},
     *     suspended: array{count: int, rows: Collection<int, Service>}, terminated: array{count: int, rows: Collection<int, Service>},
     *     actions: array{count: int, rows: Collection<int, ModuleAction>},
     *     mails: array<string, int>
     * }
     */
    public static function detail(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $part = function ($query): array {
            return ['count' => (clone $query)->count(), 'rows' => $query->limit(self::LIMIT)->get()];
        };

        return [
            'stats' => CronStat::query()->whereDate('date', $day->toDateString())->selectRaw('`key`, SUM(value) as total')->groupBy('key')
                ->pluck('total', 'key')->map(fn ($total) => (int) $total)->all(),
            'invoices' => $part(Invoice::query()->with(['client', 'items', 'adjustmentNotes', 'currency'])->whereBetween('created_at', [$from, $to])->orderBy('id')),
            'charges' => $part(ChargeAttempt::query()->with(['invoice.client', 'billingAgreement'])->whereBetween('created_at', [$from, $to])->orderBy('id')),
            // Every payment recorded that day, the charges' too (code review of #681: telling them apart by invoice dropped a
            // manual payment beside a charge, and listed a Mass Pay group's other invoices as «another way»).
            'payments' => $part(InvoiceTransaction::query()->with(['invoice.client', 'gateway'])->whereBetween('created_at', [$from, $to])
                ->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false)->orderBy('id')),
            'suspended' => self::changedTo('suspended', $from, $to),
            'terminated' => self::changedTo(Service::STATUS_TERMINATED, $from, $to),
            'actions' => $part(ModuleAction::query()->with(['service.product', 'domain'])->whereBetween('created_at', [$from, $to])->orderBy('id')),
            // Mails that went out (or tried): not the ones held back by a customer's preferences.
            'mails' => EmailLog::query()->whereBetween('created_at', [$from, $to])->where('status', '!=', EmailLog::STATUS_SKIPPED)->selectRaw('COALESCE(template_key, ?) as template, COUNT(*) as total', ['other'])
                ->groupBy('template')->orderByDesc('total')->pluck('total', 'template')->map(fn ($total) => (int) $total)->all(),
        ];
    }

    /** @return array{count: int, rows: Collection<int, Service>} services whose status changed to $status that day (their audits), by whom or the cron */
    private static function changedTo(string $status, Carbon $from, Carbon $to): array
    {
        $ids = Audit::query()->where('auditable_type', Service::class)->where('event', 'updated')->whereBetween('created_at', [$from, $to])
            ->where('new_values', 'like', '%"status":"' . $status . '"%')->distinct()->orderBy('auditable_id')->pluck('auditable_id');

        return ['count' => $ids->count(), 'rows' => Service::query()->with(['client', 'product'])->whereKey($ids->take(self::LIMIT))->orderBy('id')->get()];
    }

    /** @return array{ran: bool, invoices: int, charged: int, declined: int, suspended: int, terminated: int, attention: bool} */
    private static function emptyCell(): array
    {
        return ['ran' => false, 'invoices' => 0, 'charged' => 0, 'declined' => 0, 'suspended' => 0, 'terminated' => 0, 'attention' => false];
    }
}

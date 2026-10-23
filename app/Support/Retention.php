<?php

namespace App\Support;

use App\Models\AdjustmentNote;
use App\Models\BillingAgreement;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\ProductUpgrade;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\TicketQuarantine;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the logs keep, and for how long (Settings → Cronjob → Data Retention; 0 keeps for ever). The daily cron prunes
 * each by its setting and System Cleanup does it by hand, as WHMCS's «System Cleanup». Logs only: the money trail
 * (transactions, money events, gateway events that moved money, charge attempts), invoices, tickets and their messages
 * are never pruned here.
 *
 * Deleted in chunks by id, at most MAX_PER_RUN rows a table a run: a first run on years of logs does not hold the cron
 * (or a lock) for long, and catches up on the next ones.
 */
final class Retention
{
    public const CHUNK = 1000;

    public const MAX_PER_RUN = 100_000;

    /**
     * The money audit: kept for ever (docs/money-trail.md §6), whatever the audit log's retention. MoneyHistory builds an
     * invoice's history from it (review of #410).
     */
    public const MONEY_AUDITS = [Invoice::class, InvoiceItem::class, InvoiceTransaction::class, Credit::class, AdjustmentNote::class,
        BillingAgreement::class, Order::class, Offer::class, ServiceUpgrade::class, ProductUpgrade::class, Price::class, Plan::class,
        TaxRate::class, Coupon::class, Setting::class];

    /**
     * key => [table, date column, setting, default days, label, what it is, and what of it is never a log (`keep`)]. Rows
     * are deleted by their date column. Not here: VIES checks (the proof of a reverse charge, a tax record: review of #410).
     *
     * @return array<string, array{table: string, column: string, setting: string, default: int, label: string, description: string, keep?: Closure(Builder): Builder}>
     */
    public static function logs(): array
    {
        return [
            'email_logs' => ['table' => 'email_logs', 'column' => 'created_at', 'setting' => 'cronjob_delete_email_logs', 'default' => 90,
                'label' => 'Email log', 'description' => 'Every mail sent, with its body: what a customer was sent and when.'],
            'audits' => ['table' => 'audits', 'column' => 'created_at', 'setting' => 'retention_audits_days', 'default' => 730,
                'label' => 'Audit log', 'description' => 'Who changed what in the admin and the client area (the records\' «Audit» tab). The money audit (invoices, payments, credit, prices, taxes) is kept for ever.',
                'keep' => fn (Builder $query) => $query->whereNotIn('auditable_type', self::MONEY_AUDITS)],
            'debug_logs' => ['table' => 'debug_logs', 'column' => 'created_at', 'setting' => 'retention_debug_logs_days', 'default' => 30,
                'label' => 'HTTP and error logs', 'description' => 'Outgoing HTTP calls and application errors (System → HTTP log, Error log).'],
            'domain_registrar_logs' => ['table' => 'domain_registrar_logs', 'column' => 'created_at', 'setting' => 'retention_registrar_logs_days', 'default' => 180,
                'label' => 'Registrar log', 'description' => 'Each call to a domain registrar, with its request and answer. A charge still pending at the registrar is kept (it guards against renewing twice).',
                'keep' => fn (Builder $query) => $query->where('status', '!=', 'pending')],
            'notifications' => ['table' => 'notifications', 'column' => 'created_at', 'setting' => 'retention_notifications_days', 'default' => 180,
                'label' => 'Notifications', 'description' => 'The in-app notices of customers and staff.'],
            'user_authentication_logs' => ['table' => 'user_authentication_logs', 'column' => 'last_used_at', 'setting' => 'retention_login_logs_days', 'default' => 365,
                'label' => 'Customer login history', 'description' => 'The addresses each customer login was used from.'],
            'ticket_quarantine' => ['table' => 'ticket_quarantine', 'column' => 'handled_at', 'setting' => 'retention_ticket_quarantine_days', 'default' => 90,
                'label' => 'Mail quarantine', 'description' => 'Mail the ticket mailboxes held back and staff handled, with its raw message file. Mail still waiting for staff is kept.',
                'keep' => fn (Builder $query) => $query->where('status', '!=', 'pending')],
            'failed_jobs' => ['table' => 'failed_jobs', 'column' => 'failed_at', 'setting' => 'retention_failed_jobs_days', 'default' => 30,
                'label' => 'Failed jobs', 'description' => 'Background jobs that failed (System → Failed jobs).'],
            'cron_stats' => ['table' => 'cron_stats', 'column' => 'created_at', 'setting' => 'retention_cron_stats_days', 'default' => 365,
                'label' => 'Cron statistics', 'description' => 'How much each daily cron step did (Automation status).'],
            'license_check_days' => ['table' => 'license_check_days', 'column' => 'day', 'setting' => 'retention_license_checks_days', 'default' => 400,
                'label' => 'License checks per day', 'description' => 'How many checks each license made a day (a service\'s licensing graph).'],
            'staff_messages' => ['table' => 'staff_messages', 'column' => 'created_at', 'setting' => 'retention_staff_messages_days', 'default' => 0,
                'label' => 'Staff chat', 'description' => 'The staff room and direct messages.'],
        ];
    }

    /** The settings of the logs' retention, for Settings → Cronjob → Data Retention (the email log has its own already). @return list<array<string, mixed>> */
    public static function settings(): array
    {
        $settings = [];
        foreach (self::logs() as $key => $log) {
            if ($key === 'email_logs') {
                continue;
            }
            $settings[] = ['name' => $log['setting'], 'section' => 'Data Retention Settings', 'label' => 'Keep ' . strtolower($log['label']) . ' for (days)',
                'type' => 'number', 'default' => $log['default'], 'required' => true, 'min_value' => 0,
                'description' => $log['description'] . ' 0 keeps it for ever. System → System Cleanup deletes by hand.'];
        }

        return $settings;
    }

    /** The days a log is kept: its setting, or its default (0: for ever). */
    public static function days(string $key): int
    {
        $log = self::logs()[$key];

        return max(0, (int) config('settings.' . $log['setting'], $log['default']));
    }

    /**
     * Each log by its setting: key => rows deleted (only those with a retention and a table).
     *
     * @param  list<string>  $except  logs pruned elsewhere (the cron's email log step)
     * @return array<string, int>
     */
    public static function pruneAll(array $except = []): array
    {
        $deleted = [];
        foreach (array_diff(array_keys(self::logs()), $except) as $key) {
            $days = self::days($key);
            if ($days > 0) {
                $deleted[$key] = self::pruneBefore($key, now()->subDays($days));
            }
        }

        return $deleted;
    }

    /** Rows of one log older than $before (strictly), in chunks; how many went. */
    public static function pruneBefore(string $key, Carbon $before): int
    {
        $log = self::logs()[$key] ?? throw new \InvalidArgumentException("Unknown log: {$key}");
        if (!Schema::hasTable($log['table'])) {
            return 0;
        }
        $cutoff = $log['column'] === 'day' ? $before->toDateString() : $before->toDateTimeString();
        $keep = $log['keep'] ?? fn (Builder $query) => $query;
        $query = fn (): Builder => $keep(DB::table($log['table'])->where($log['column'], '<', $cutoff));
        // Through the model where it does more on delete: the quarantine's raw file, the notifications' events.
        $delete = match ($key) {
            'ticket_quarantine' => fn (array $ids) => TicketQuarantine::query()->whereKey($ids)->get()->each->delete()->count(),
            'notifications' => fn (array $ids) => Notification::query()->whereKey($ids)->get()->each->delete()->count(),
            default => fn (array $ids) => DB::table($log['table'])->whereIn('id', $ids)->delete(),
        };

        return self::inChunks($query, $delete);
    }

    /**
     * @param  Closure(): Builder  $query  the rows to go
     * @param  Closure(list<int>): int  $delete
     */
    private static function inChunks(Closure $query, Closure $delete): int
    {
        $total = 0;
        while ($total < self::MAX_PER_RUN) {
            $ids = $query()->orderBy('id')->limit(self::CHUNK)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                break;
            }
            $total += $delete($ids);
        }

        return $total;
    }

    /** For System Cleanup: rows, the oldest date, and the table's size where the database tells it. @return array{rows: int, estimated: bool, oldest: ?string, bytes: ?int} */
    public static function stats(string $key): array
    {
        $log = self::logs()[$key];
        if (!Schema::hasTable($log['table'])) {
            return ['rows' => 0, 'estimated' => false, 'oldest' => null, 'bytes' => null];
        }
        $bytes = $estimate = null;
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            // The server's own figures: a count of millions of rows would hold the page for seconds.
            $size = DB::selectOne('select data_length + index_length as bytes, table_rows as estimate from information_schema.tables where table_schema = database() and table_name = ?', [$log['table']]);
            [$bytes, $estimate] = $size !== null ? [(int) $size->bytes, (int) $size->estimate] : [null, null];
        }

        return [
            'rows' => $estimate ?? DB::table($log['table'])->count(),
            'estimated' => $estimate !== null,
            // The first row's date (ids grow with time): by the primary key, never a scan of a date without an index.
            'oldest' => ($oldest = DB::table($log['table'])->orderBy('id')->value($log['column'])) !== null ? (string) $oldest : null,
            'bytes' => $bytes,
        ];
    }
}

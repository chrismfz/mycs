<?php

namespace App\Services\Ops;

use App\Console\Commands\Domains\SyncDomains;
use App\Console\Commands\MailSweep;
use App\Enums\DomainStatus;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\License;
use App\Models\ModuleAction;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is MyCS well (docs/ops.md)? Read-only: the build, the scheduler and the daily cron, the queue, the mails, the module
 * actions staff must see, the licenses' checks. Each check says ok / warn / fail and why; the worst is the whole.
 * The same answer for `mycs:health`, the MCP and the health endpoint.
 */
final class Health
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** @return array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>} */
    public static function report(): array
    {
        $checks = [];
        foreach (['build', 'database', 'scheduler', 'cron', 'queue', 'mail', 'module_actions', 'licenses', 'domains'] as $name) {
            try {
                $checks[$name] = call_user_func([self::class, $name]);
            } catch (Throwable $e) {
                $checks[$name] = ['status' => self::FAIL, 'detail' => 'Check failed: ' . Scrub::text($e->getMessage())];
            }
        }
        $statuses = array_column($checks, 'status');

        return [
            'status' => in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::OK),
            'checks' => $checks,
        ];
    }

    private static function build(): array
    {
        $build = json_decode((string) @file_get_contents(storage_path('app/build.json')), true) ?: [];

        return ['status' => self::OK, 'detail' => isset($build['sha']) ? "{$build['sha']} ({$build['ref']}, {$build['committed_at']})" : 'No build.json (not deployed by deploy/update.sh)',
            'data' => $build + ['php' => PHP_VERSION, 'env' => app()->environment()]];
    }

    private static function database(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $pending = array_values(array_diff($files, $ran));

        return ['status' => $pending === [] ? self::OK : self::FAIL,
            'detail' => $pending === [] ? count($ran) . ' migrations, none pending' : count($pending) . ' pending migration(s)', 'data' => ['pending' => $pending]];
    }

    private static function scheduler(): array
    {
        $last = Setting::query()->where('key', 'last_scheduler_run')->where('settingable_type', CronStat::class)->value('value');
        $at = $last ? Carbon::parse($last) : null;

        return ['status' => $at && $at->gt(now()->subMinutes(5)) ? self::OK : self::FAIL,
            'detail' => $at ? 'Last run ' . $at->diffForHumans() : 'Never ran', 'data' => ['last_run' => $at?->toIso8601String()]];
    }

    private static function cron(): array
    {
        $last = Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->value('value');
        $at = $last ? Carbon::parse($last) : null;

        return ['status' => $at && $at->gt(now()->subHours(25)) ? self::OK : self::WARN,
            'detail' => $at ? 'Last daily run ' . $at->diffForHumans() : 'The daily cron never ran', 'data' => ['last_run' => $at?->toIso8601String()]];
    }

    private static function queue(): array
    {
        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->whereNull('reserved_at')->min('available_at') : null;
        $waiting = $oldest ? now()->getTimestamp() - (int) $oldest : 0;
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $status = $waiting > 600 ? self::FAIL : ($failed > 0 || $waiting > 120 ? self::WARN : self::OK);

        return ['status' => $status, 'detail' => "{$jobs} queued (oldest waiting {$waiting}s), {$failed} failed in 24h",
            'data' => ['queued' => $jobs, 'oldest_waiting_seconds' => $waiting, 'failed_24h' => $failed, 'failed_total' => DB::table('failed_jobs')->count()]];
    }

    private static function mail(): array
    {
        $disabled = (bool) config('settings.mail_disable');
        $failed = EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->where('created_at', '>=', now()->subDay())->count();
        $stuck = EmailLog::query()->where(fn ($query) => $query
            ->where(fn ($query) => $query->where('status', EmailLog::STATUS_SENDING)->where('sending_at', '<', now()->subMinutes(MailSweep::SENDING_MINUTES)))
            ->orWhere(fn ($query) => $query->where('status', EmailLog::STATUS_PENDING)->where('created_at', '<', now()->subHour())))->count();
        $sent = EmailLog::query()->where('status', EmailLog::STATUS_SENT)->where('sent_at', '>=', now()->subDay())->count();
        $status = $stuck > 0 ? self::FAIL : ($failed > 0 || $disabled ? self::WARN : self::OK);

        return ['status' => $status, 'detail' => ($disabled ? 'Mail disabled. ' : '') . "{$sent} sent, {$failed} failed in 24h, {$stuck} stuck",
            'data' => ['disabled' => $disabled, 'smtp_configured' => filled(config('settings.mail_host')), 'sent_24h' => $sent, 'failed_24h' => $failed, 'stuck' => $stuck]];
    }

    private static function module_actions(): array
    {
        $open = ModuleAction::query()->where('status', ModuleAction::STATUS_OPEN);
        $givenUp = (clone $open)->whereNotNull('given_up_at')->count();

        return ['status' => $givenUp > 0 ? self::WARN : self::OK, 'detail' => $open->count() . " open, {$givenUp} given up (need staff)",
            'data' => ['open' => $open->count(), 'given_up' => $givenUp]];
    }

    private static function licenses(): array
    {
        if (!Schema::hasTable('licenses')) {
            return ['status' => self::OK, 'detail' => 'No licensing'];
        }
        $today = DB::table('license_check_days')->where('day', now()->toDateString());
        $ok = (int) (clone $today)->sum('ok');
        $failed = (int) (clone $today)->sum('failed');
        $silent = License::query()->where('status', License::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('last_ok_at')->orWhere('last_ok_at', '<', now()->subDays(7)))->count();

        return ['status' => self::OK, 'detail' => "{$ok} good / {$failed} failed checks today, {$silent} active licenses silent 7 days",
            'data' => ['ok_today' => $ok, 'failed_today' => $failed, 'silent' => $silent, 'active' => License::query()->where('status', License::STATUS_ACTIVE)->count()]];
    }

    /** Domains (docs/domains.md D-9): sync errors, pending transfers stuck for 14 days, the oldest sync. */
    private static function domains(): array
    {
        if (!Schema::hasTable('domains')) {
            return ['status' => self::OK, 'detail' => 'No domains'];
        }
        $live = Domain::query()->whereNotIn('status', DomainStatus::terminalValues());
        $errors = (clone $live)->whereNotNull('sync_error')->count();
        $stale = (clone $live)->where('status', DomainStatus::PendingTransfer->value)
            ->where('updated_at', '<', now()->subDays(SyncDomains::STALE_TRANSFER_DAYS))->count();
        $oldest = (clone $live)->whereNotNull('last_synced_at')->min('last_synced_at');
        $status = $errors > 0 || $stale > 0 ? self::WARN : self::OK;

        return ['status' => $status, 'detail' => "{$errors} with a sync error, {$stale} transfers pending 14+ days",
            'data' => ['live' => (clone $live)->count(), 'sync_errors' => $errors, 'stale_transfers' => $stale, 'oldest_sync' => $oldest]];
    }
}

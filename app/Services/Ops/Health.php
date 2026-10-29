<?php

namespace App\Services\Ops;

use App\Classes\Settings;
use App\Console\Commands\Domains\SyncDomains;
use App\Console\Commands\MailSweep;
use App\Enums\DomainStatus;
use App\Models\CronStat;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\License;
use App\Models\ModuleAction;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\TicketDepartment;
use App\Models\TicketQuarantine;
use App\Services\Mcp\McpOauth;
use App\Services\Tax\EuVatRates;
use App\Support\StaffTwoFactor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    /** The checks without which the instance does not work (GET /health's 503, review H1 of #259); the rest need staff. */
    public const CRITICAL = ['database', 'scheduler'];

    /**
     * For a menu badge on every admin page: the report of the last minute, never read by two at once (one reads it
     * again, the others keep the last one meanwhile; review R2 of #258).
     *
     * @return array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>}
     */
    public static function cached(): array
    {
        $last = Cache::get('ops.health');
        if (is_array($last) && ($last['at'] ?? 0) > now()->getTimestamp() - 60) {
            return $last['report'];
        }
        $lock = Cache::lock('ops.health:reading', 30);
        if (!$lock->get()) {
            if (is_array($last)) {
                return $last['report'];
            }
            // None yet (a flushed cache) and another reading it: wait for that one, never all at once (review H2 of #259).
            try {
                $lock->block(5);
            } catch (LockTimeoutException) {
                return self::report();
            }
            $last = Cache::get('ops.health');
            if (is_array($last)) {
                $lock->release();

                return $last['report'];
            }
        }
        try {
            return self::keep(self::report());
        } finally {
            $lock->release();
        }
    }

    /**
     * For the Automation Status page: read now (staff opened it to see it, review R1 of #258), once per request for its
     * widgets, and kept for the badge.
     *
     * @return array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>}
     */
    public static function fresh(): array
    {
        $memo = request()->attributes->get('ops.health');
        if (is_array($memo)) {
            return $memo;
        }
        $report = self::keep(self::report());
        request()->attributes->set('ops.health', $report);

        return $report;
    }

    /**
     * @param  array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>}  $report
     * @return array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>}
     */
    private static function keep(array $report): array
    {
        Cache::put('ops.health', ['at' => now()->getTimestamp(), 'report' => $report], 600);

        return $report;
    }

    /** @return array{status: string, checks: array<string, array{status: string, detail: string, data?: array<string, mixed>}>} */
    public static function report(): array
    {
        $checks = [];
        foreach (['build', 'runtime', 'database', 'scheduler', 'cron', 'queue', 'mail', 'module_actions', 'licenses', 'domains', 'ticket_mail', 'tax', 'vat_rates'] as $name) {
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

    /**
     * The PHP that serves the site (security audit SEC-13): in production no debug output and no dev packages (Debugbar,
     * Ignition: queries, sessions and requests to whoever asks), and PHP settings that keep the other sites of a
     * shared server out (`opcache.validate_permission`: another pool cannot include a script cached from this one).
     * The PHP settings are the web's own: read from the command line they would be the CLI's, so they are left out.
     */
    private static function runtime(): array
    {
        $production = app()->isProduction();
        $web = PHP_SAPI !== 'cli';
        $opcache = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $found = [];
        if ($production && config('app.debug')) {
            $found[] = [self::FAIL, 'APP_DEBUG is on in production: error pages show code, queries and settings'];
        }
        $dev = array_keys(array_filter([
            'barryvdh/laravel-debugbar' => class_exists('Fruitcake\\LaravelDebugbar\\ServiceProvider'),
            'spatie/laravel-ignition' => class_exists('Spatie\\LaravelIgnition\\IgnitionServiceProvider'),
        ]));
        if ($production && $dev !== []) {
            $found[] = [self::WARN, 'Dev packages installed in production (' . implode(', ', $dev) . '): deploy with composer --no-dev'];
        }
        if ($web && filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN)) {
            $found[] = [self::WARN, 'expose_php is on: every answer names the PHP version'];
        }
        if ($web && $opcache && !filter_var(ini_get('opcache.validate_permission'), FILTER_VALIDATE_BOOLEAN)) {
            $found[] = [self::WARN, 'opcache.validate_permission is off: another site on this server can run scripts cached from this one'];
        }
        if (McpOauth::grantDays() === 0) {
            $found[] = [self::WARN, 'MCP grants never expire (Settings: MCP grant lifetime 0): a forgotten connector keeps working until revoked'];
        }
        if (!StaffTwoFactor::required()) {
            $found[] = [self::WARN, 'Staff 2FA is optional (Settings: Require 2FA for staff): a stolen staff password alone opens the admin'];
        }
        $statuses = array_column($found, 0);

        return [
            'status' => in_array(self::FAIL, $statuses, true) ? self::FAIL : ($found !== [] ? self::WARN : self::OK),
            'detail' => $found !== [] ? implode('; ', array_column($found, 1)) : ($web ? 'Production settings as expected' : 'Production settings as expected (PHP settings: read from the web only)'),
            'data' => ['sapi' => PHP_SAPI, 'env' => app()->environment(), 'debug' => (bool) config('app.debug'), 'dev_packages' => $dev]
                + ($web ? ['expose_php' => (bool) filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'opcache' => $opcache,
                    'opcache_validate_permission' => (bool) filter_var(ini_get('opcache.validate_permission'), FILTER_VALIDATE_BOOLEAN),
                    'opcache_validate_root' => (bool) filter_var(ini_get('opcache.validate_root'), FILTER_VALIDATE_BOOLEAN)] : []),
        ];
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
        // The queue the app uses (AUD8-4): a redis or other one read through it, or failed when it cannot be reached.
        $connection = (string) config('queue.default');
        if (!in_array($connection, ['database', 'sync'], true)) {
            try {
                $size = Queue::connection($connection)->size();
            } catch (Throwable $e) {
                return ['status' => self::FAIL, 'detail' => "The {$connection} queue cannot be read: " . Scrub::text($e->getMessage())];
            }
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

            return ['status' => $failed > 0 ? self::WARN : self::OK, 'detail' => "{$size} queued ({$connection}), {$failed} failed in 24h",
                'data' => ['queued' => $size, 'connection' => $connection, 'failed_24h' => $failed, 'failed_total' => DB::table('failed_jobs')->count()]];
        }
        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->whereNull('reserved_at')->min('available_at') : null;
        $waiting = $oldest ? now()->getTimestamp() - (int) $oldest : 0;
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $status = $waiting > 600 ? self::FAIL : ($failed > 0 || $waiting > 120 ? self::WARN : self::OK);

        return ['status' => $status, 'detail' => "{$jobs} queued (oldest waiting {$waiting}s), {$failed} failed in 24h",
            'data' => ['queued' => $jobs, 'oldest_waiting_seconds' => $waiting, 'failed_24h' => $failed, 'failed_total' => DB::table('failed_jobs')->count()]];
    }

    /**
     * With tax on, an account without a country pays the «All» rate or the company's (AUD5-1): without either, its
     * invoices would be 0% with no reason. The company country is required (owner, 2026-10-29).
     */
    private static function tax(): array
    {
        if (!config('settings.tax_enabled')) {
            return ['status' => self::OK, 'detail' => 'Tax off'];
        }
        $all = TaxRate::query()->where('country', 'all')->exists();
        $home = Settings::configuredHomeCountry();
        $status = $home !== null ? self::OK : ($all || Settings::onlyRateCountry() !== null ? self::WARN : self::FAIL);
        $detail = match ($status) {
            self::OK => 'Company country ' . $home,
            self::WARN => 'Set the Company country (Settings, Tax): accounts without a country pay ' . ($all ? 'the «All» rate' : 'the only rate') . ' until then',
            default => 'Tax is on with no Company country and no «All» rate: an account without a country is invoiced at 0%',
        };

        return ['status' => $status, 'detail' => $detail, 'data' => ['company_country' => $home, 'all_rate' => $all]];
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
        // A paid domain renewal waiting for staff (D5b-2, adversarial ADV-8): no automatic give-up, always theirs.
        $domains = (clone $open)->whereIn('action', ModuleAction::DOMAIN_ACTIONS)->count();

        return ['status' => $givenUp + $domains > 0 ? self::WARN : self::OK, 'detail' => $open->count() . " open, {$givenUp} given up, {$domains} domain actions (need staff)",
            'data' => ['open' => $open->count(), 'given_up' => $givenUp, 'domain_renewals' => $domains]];
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
        $stale = SyncDomains::stalePending((clone $live)->where('status', DomainStatus::PendingTransfer->value))->count();
        $oldest = (clone $live)->whereNotNull('last_synced_at')->min('last_synced_at');
        // The batched sync (#204): a pause forgotten, or a schedule stopped, is seen (review ADV-5).
        $paused = (float) config('settings.domain_sync_hours', 2) <= 0;
        $unsynced = (clone $live)->whereNotNull('last_synced_at')->where('last_synced_at', '<', now()->subDays(3))->count();
        $status = $errors > 0 || $stale > 0 || $paused || $unsynced > 0 ? self::WARN : self::OK;
        $detail = "{$errors} with a sync error, {$stale} transfers pending 14+ days" . ($paused ? ', the sync is paused' : '')
            . ($unsynced > 0 ? ", {$unsynced} not synced for 3+ days" : '');

        return ['status' => $status, 'detail' => $detail,
            'data' => ['live' => (clone $live)->count(), 'sync_errors' => $errors, 'stale_transfers' => $stale, 'oldest_sync' => $oldest,
                'sync_paused' => $paused, 'not_synced_3_days' => $unsynced]];
    }

    /** Mail to tickets (docs/tickets.md A2): each mailbox read in the last half hour, and the quarantine kept up with. */
    private static function ticket_mail(): array
    {
        if (!Schema::hasTable('ticket_poll_runs')) {
            return ['status' => self::OK, 'detail' => 'No ticket mail'];
        }
        $departments = TicketDepartment::query()->where('polling', true)->get();
        if ($departments->isEmpty()) {
            return ['status' => self::OK, 'detail' => 'No mailbox is read', 'data' => ['mailboxes' => 0]];
        }
        $failing = [];
        $erring = [];
        foreach ($departments as $department) {
            $run = $department->pollRuns()->latest('started_at')->first();
            if ($run === null || $run->started_at->lt(now()->subMinutes(30)) || !$run->connected) {
                $failing[] = $department->name;
            } elseif ($run->errors) {
                $erring[] = $department->name . ': ' . $run->errors[0];
            }
        }
        $waiting = TicketQuarantine::query()->where('status', TicketQuarantine::PENDING)->where('reason', '!=', 'automated')
            ->where('created_at', '<', now()->subDay())->count();
        $status = $failing !== [] ? self::FAIL : ($waiting > 0 || $erring !== [] ? self::WARN : self::OK);

        return ['status' => $status, 'detail' => ($failing !== [] ? 'Not read in 30 minutes: ' . implode(', ', $failing) . '. ' : '')
            . ($erring !== [] ? 'Errors: ' . Scrub::text(implode('; ', $erring)) . '. ' : '')
            . "{$departments->count()} mailboxes read, {$waiting} quarantined mails waiting over a day",
            'data' => ['mailboxes' => $departments->count(), 'not_read' => $failing, 'quarantine_waiting' => $waiting, 'errors' => $erring]];
    }

    /**
     * The weekly comparison of Tax Rates with TEDB (docs/eu-vat.md): a difference found, TEDB not answering, or no
     * check for over a week. Off, or not run yet since turned on, is ok.
     */
    private static function vat_rates(): array
    {
        if (!EuVatRates::enabled()) {
            return ['status' => self::OK, 'detail' => 'The weekly check is off (Settings → Tax)'];
        }
        $rates = app(EuVatRates::class);
        $state = $rates->state();
        $checked = isset($state['checked_at']) ? Carbon::parse($state['checked_at']) : null;
        $failed = isset($state['failed_at']) ? Carbon::parse($state['failed_at']) : null;
        // Against Tax Rates as they are now: a rate staff fixed after the check is ok at once (code review).
        $differences = isset($state['tedb']) ? $rates->compare($state['tedb'])['differences'] : ($state['differences'] ?? []);
        $found = [];
        if ($differences !== []) {
            $found[] = 'Differs from TEDB: ' . implode(', ', array_map(fn (array $difference) => "{$difference['country']} {$difference['ours']}% here, {$difference['tedb']}% in TEDB", $differences));
        }
        if ($failed !== null && ($checked === null || $failed->gt($checked))) {
            $found[] = 'TEDB did not answer ' . $failed->diffForHumans() . ': ' . Scrub::text((string) ($state['error'] ?? ''));
        }
        if ($checked !== null && $checked->lt(now()->subDays(EuVatRates::STALE_DAYS))) {
            $found[] = 'Last checked ' . $checked->diffForHumans();
        }

        return ['status' => $found !== [] ? self::WARN : self::OK,
            'detail' => $found !== [] ? implode('; ', $found) : ($checked ? ($state['compared'] ?? 0) . ' rates as in TEDB, checked ' . $checked->diffForHumans() : 'Not checked yet (weekly, Mondays)'),
            'data' => ['checked_at' => $checked?->toIso8601String(), 'failed_at' => $failed?->toIso8601String(), 'differences' => $differences]];
    }
}

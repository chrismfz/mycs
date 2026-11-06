<?php

namespace App\Services\Ops;

use App\Admin\Pages\CronStats;
use App\Admin\Pages\Settings as SettingsPage;
use App\Admin\Resources\ServiceResource;
use App\Helpers\NotificationHelper;
use App\Models\ChargeAttempt;
use App\Models\CronStat;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Service\DunningForecast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * The daily summary email (owner 2026-11-06, ROADMAP 9β-4): the Cron stats calendar by mail. The days since the last
 * one (yesterday; Monday's covers the weekend with «working days only»), and what the cron suspends and terminates
 * today and in the next two days if nothing is paid, so staff can tell a customer in time. Off by default; «only when
 * something failed» sends it for a day the cron did not run, a card charge that did not go through or a module action
 * left open (the calendar's red). Read only: it changes nothing but the day it covered last.
 */
final class CronSummary
{
    /** The last day a summary covered (whether it was sent or nothing had failed): the next one starts after it. */
    public const COVERED = 'cron_summary_covered';

    /** At most this many days in one (it was off for a while, or the scheduler was down). */
    public const MAX_DAYS = 7;

    /** Services listed per day of what is due; the rest counted. */
    public const LIMIT = 30;

    public static function mode(): string
    {
        $mode = (string) config('settings.cron_summary_mode', 'off');

        return in_array($mode, ['failures', 'daily'], true) ? $mode : 'off';
    }

    /**
     * Sends today's summary as the settings say. $force: sent now whatever the mode and the day, and the days it covers
     * stay to be covered (a test from the console).
     *
     * @return string what happened, for the console
     */
    public static function run(?Carbon $today = null, bool $force = false): string
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        if (!$force) {
            if (self::mode() === 'off') {
                return 'Off (Settings → Automation → Daily Summary Email).';
            }
            if (config('settings.cron_summary_workdays') && $today->isWeekend()) {
                return 'Not on a weekend (working days only): Monday\'s covers it.';
            }
        }

        [$from, $to] = self::period($today, $force);
        if ($from === null) {
            return 'Already sent for the days up to yesterday.';
        }

        $data = self::build($today, $from, $to);
        if (!$force && self::mode() === 'failures' && !$data['failed']) {
            self::covered($to);

            return 'Nothing failed from ' . $from->toDateString() . ' to ' . $to->toDateString() . ': not sent.';
        }

        $recipients = self::recipients();
        if ($recipients === []) {
            return 'No one to send it to: choose staff or set the System Email Address.';
        }

        $locale = (string) config('settings.admin_language', 'en'); // the admin panel's language (AdminLocale)
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            $subject = self::subject($data);
            $body = view('admin.mail.cron-summary', $data)->render();
        } finally {
            App::setLocale($previous);
        }
        foreach ($recipients as $address) {
            NotificationHelper::sendSystemEmailNotification($subject, $body, email: $address);
        }
        if (!$force) {
            self::covered($to);
        }

        return 'Sent to ' . implode(', ', $recipients) . ': ' . $subject;
    }

    /**
     * The days to cover: from the day after the last one covered (at most MAX_DAYS back) to yesterday; yesterday alone the
     * first time. [null, null] when they are covered already.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function period(Carbon $today, bool $force = false): array
    {
        $to = $today->copy()->subDay();
        $last = $force ? null : Setting::query()->where('settingable_type', CronStat::class)->where('key', self::COVERED)->value('value');
        $from = $last ? Carbon::parse((string) $last)->addDay()->startOfDay() : $to->copy();
        // Working days only: Monday's goes back to Friday even the first time (Friday's covered Thursday).
        if ($last === null && config('settings.cron_summary_workdays') && $today->isMonday()) {
            $from = $today->copy()->subDays(3);
        }
        $from = $from->max($to->copy()->subDays(self::MAX_DAYS - 1));
        if ($from->gt($to)) {
            return [null, null];
        }

        return [$from, $to];
    }

    /**
     * What goes in the mail.
     *
     * @return array<string, mixed>
     */
    public static function build(Carbon $today, Carbon $from, Carbon $to): array
    {
        $cells = CronDay::range($from, $to);
        $days = [];
        $failed = false;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $cell = $cells[$key] ?? null;
            $ran = (bool) ($cell['ran'] ?? false);
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $charges = ChargeAttempt::query()->with('invoice.client')->whereBetween('created_at', [$start, $end])
                ->where('source', ChargeAttempt::SOURCE_CRON)->whereNotIn('status', [ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_SETTLED])
                ->orderBy('id')->get();
            $actions = ModuleAction::query()->with(['service.client', 'service.product', 'domain'])->whereBetween('created_at', [$start, $end])
                ->where('status', ModuleAction::STATUS_OPEN)->orderBy('id')->get();
            $failed = $failed || !$ran || $charges->isNotEmpty() || $actions->isNotEmpty();
            $days[] = [
                'date' => $day->copy(), 'url' => self::dayUrl($key), 'ran' => $ran, 'cell' => $cell,
                'charges' => $charges, 'actions' => $actions,
            ];
        }

        try {
            $forecast = DunningForecast::upcoming($today);
            $forecastError = null;
        } catch (Throwable $e) {
            report($e);
            $forecast = null;
            $forecastError = $e->getMessage();
        }
        $due = [];
        if ($forecast !== null) {
            $due[] = ['label' => !$forecast['suspend_on'] || !$forecast['terminate_on'] ? __('Waiting (safety net)') : __('Due now'), 'url' => self::dayUrl($today->toDateString()),
                'suspend' => self::services($forecast['now']['suspend']), 'terminate' => self::services($forecast['now']['terminate'])];
            for ($d = 1; $d <= 2; $d++) {
                $day = $today->copy()->addDays($d);
                $lists = $forecast['days'][$day->toDateString()] ?? ['suspend' => [], 'terminate' => []];
                $due[] = ['label' => $day->translatedFormat('l j/n'), 'url' => self::dayUrl($day->toDateString()),
                    'suspend' => self::services($lists['suspend']), 'terminate' => self::services($lists['terminate'])];
            }
        }

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'days' => $days, 'failed' => $failed,
            'due' => $due, 'forecastError' => $forecastError,
            'netOn' => $forecast !== null && (!$forecast['suspend_on'] || !$forecast['terminate_on']),
            'calendarUrl' => self::dayUrl($to->toDateString()),
            'settingsUrl' => SettingsPage::getUrl(panel: 'admin') . '?tab=cronjob%3A%3Adata%3A%3Atab',
            'serviceUrl' => fn (Service $service): string => ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin'),
        ];
    }

    /**
     * The addresses: the chosen staff that are still active and may see Cron stats; none chosen, the System Email Address.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        $ids = array_filter(array_map('intval', (array) config('settings.cron_summary_recipients', [])));
        if ($ids === []) {
            $system = trim((string) config('settings.system_email_address'));

            return $system === '' ? [] : [$system];
        }

        return Staff::query()->whereKey($ids)->where('active', true)->with('role')->orderBy('id')->get()
            ->filter(fn (Staff $staff) => $staff->hasPermission('admin.cron_stats.view') && trim((string) $staff->email) !== '')
            ->map(fn (Staff $staff) => (string) $staff->email)->unique()->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    private static function subject(array $data): string
    {
        /** @var Carbon $from */
        $from = $data['from'];
        /** @var Carbon $to */
        $to = $data['to'];
        $period = $from->eq($to) ? $to->translatedFormat('D j/n') : $from->translatedFormat('D j/n') . '–' . $to->translatedFormat('D j/n');
        $soon = collect($data['due'])->sum(fn (array $row) => $row['suspend']['count'] + $row['terminate']['count']);

        return __('Cron summary :period', ['period' => $period])
            . ($data['failed'] ? ' · ' . __('something to look at') : '')
            . ($soon > 0 ? ' · ' . trans_choice(':count service due|:count services due', $soon) : '');
    }

    /**
     * @param  list<int>  $ids
     * @return array{count: int, rows: Collection<int, Service>}
     */
    private static function services(array $ids): array
    {
        return ['count' => count($ids), 'rows' => Service::query()->with(['client', 'product'])->whereKey(array_slice($ids, 0, self::LIMIT))->orderBy('id')->get()];
    }

    private static function covered(Carbon $day): void
    {
        Setting::updateOrCreate(['key' => self::COVERED, 'settingable_type' => CronStat::class], ['value' => $day->toDateString(), 'type' => 'string']);
    }

    private static function dayUrl(string $day): string
    {
        return CronStats::getUrl(['day' => $day, 'month' => substr($day, 0, 7)], panel: 'admin');
    }
}

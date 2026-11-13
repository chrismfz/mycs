<?php

namespace App\Services\Ops;

use App\Admin\Pages\CronStats;
use App\Admin\Pages\Settings as SettingsPage;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\ServiceResource;
use App\Helpers\NotificationHelper;
use App\Models\ChargeAttempt;
use App\Models\CronStat;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Service\DunningForecast;
use App\Support\StaffRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The daily summary email (owner 2026-11-06, ROADMAP 9β-4): the Cron stats calendar by mail. The days since the last
 * one (yesterday; Monday's covers the weekend with «working days only»), and what the cron suspends and terminates
 * today and in the next two days (up to the next summary's day: Friday's to Monday with «working days only», ADV-A3-5)
 * if nothing is paid, so staff can tell a customer in time. Off by default; «only when
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
        if (config('settings.mail_disable')) {
            return 'Mail is disabled (Settings → Mail): not sent, the days stay to be covered.';
        }
        // One at a time: by hand while the scheduled one runs, the covered day is read by both (code review of #689).
        $lock = Cache::lock('cron-summary', 300);
        if (!$lock->get()) {
            return 'Another summary is being sent: try again in a few minutes.';
        }
        try {
            return self::runLocked(($today ?? Carbon::today())->copy()->startOfDay(), $force);
        } finally {
            $lock->release();
        }
    }

    private static function runLocked(Carbon $today, bool $force): string
    {
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
        // The daily run, not the hourly one (whose suspensions and charges also count): it always writes these, 0 or not
        // (code review of #689). A day with only the hourly run's counts did not have its daily run.
        $daily = CronStat::query()->whereIn('key', ['invoices_created', 'logs_pruned'])
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->all();
        $days = [];
        $failed = false;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $cell = $cells[$key] ?? null;
            $ran = in_array($key, $daily, true);
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $charges = ChargeAttempt::query()->with('invoice.client')->whereBetween('created_at', [$start, $end])
                ->where('source', ChargeAttempt::SOURCE_CRON)->whereNotIn('status', [ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_SETTLED])
                ->orderBy('id')->get();
            $actions = ModuleAction::query()->with(['service.client', 'service.product', 'domain'])->whereBetween('created_at', [$start, $end])
                ->where('status', ModuleAction::STATUS_OPEN)->failures()->orderBy('id')->get(); // a check is not a failure: counted below
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
            $due[] = ['label' => !$forecast['suspend_on'] ? __('Waiting (safety net)') : __('Due now'), 'url' => self::dayUrl($today->toDateString()),
                'suspend' => self::services($forecast['now']['suspend']), 'terminate' => self::services($forecast['now']['terminate'])];
            for ($d = 1; $d <= self::ahead($today); $d++) {
                $day = $today->copy()->addDays($d);
                $lists = $forecast['days'][$day->toDateString()] ?? ['suspend' => [], 'terminate' => []];
                $due[] = ['label' => $day->translatedFormat('l j/n'), 'url' => self::dayUrl($day->toDateString()),
                    'suspend' => self::services($lists['suspend']), 'terminate' => self::services($lists['terminate'])];
            }
        }

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'days' => $days, 'failed' => $failed,
            // The open «check this» tasks now (owner 2026-11-08): a count, never a failure, no mail of their own.
            'checks' => ModuleAction::openChecks(),
            'checksUrl' => ModuleActionResource::getUrl('index', ['filters' => ['checks' => ['isActive' => true]]], panel: 'admin'),
            'due' => $due, 'forecastError' => $forecastError,
            'suspendOff' => $forecast !== null && !$forecast['suspend_on'],
            'terminateOff' => $forecast !== null && $forecast['suspend_on'] && !$forecast['terminate_on'],
            'calendarUrl' => self::dayUrl($to->toDateString()),
            'settingsUrl' => SettingsPage::getUrl(panel: 'admin') . '?tab=cronjob%3A%3Adata%3A%3Atab',
            'serviceUrl' => fn (Service $service): string => ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin'),
        ];
    }

    /**
     * The days after today it looks ahead to: two, and up to the day of the next summary, so what is due before it is in
     * this one (ADV-A3-5: with «working days only», Friday's goes on to Monday, whose own mail goes after its suspensions).
     */
    public static function ahead(Carbon $today): int
    {
        $next = $today->copy()->addDay();
        while (config('settings.cron_summary_workdays') && $next->isWeekend()) {
            $next->addDay();
        }

        return max(2, (int) $today->copy()->startOfDay()->diffInDays($next->startOfDay()));
    }

    /**
     * The addresses: the chosen staff that are still active and may see Cron stats; none chosen (or none left), those
     * of the system notices (StaffRecipients::systemNotices), not silence (code review of #689).
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        return StaffRecipients::chosen('cron_summary_recipients', 'admin.cron_stats.view') ?: StaffRecipients::systemNotices();
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

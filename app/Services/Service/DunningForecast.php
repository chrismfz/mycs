<?php

namespace App\Services\Service;

use App\Models\Service;
use App\Support\DunningWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the cron will suspend and terminate for non-payment in the days to come, if nothing is paid (owner 2026-11-06:
 * «what is suspended tomorrow or the day after, so we can tell them first when it is urgent»). Asked of the cron's own
 * rules (DunningRules) day by day, so it never drifts from them; a service is listed on the first day it qualifies, moved
 * to the dunning window's next open day. «Now»: those that qualify today, which the cron suspends at its next run, or
 * holds back while suspensions are off (the cut-over's safety net, ROADMAP 9β-4). What it cannot know: a payment, a hold
 * or a date staff set before then, and a card charge on its way at that moment (the cron looks again then).
 */
final class DunningForecast
{
    public const DAYS = 14;

    /**
     * @return array{
     *     now: array{suspend: list<int>, terminate: list<int>},
     *     days: array<string, array{suspend: list<int>, terminate: list<int>}>,
     *     suspend_on: bool, terminate_on: bool
     * } service ids, the days keyed Y-m-d from tomorrow, only those with something
     */
    public static function upcoming(?Carbon $today = null, int $days = self::DAYS): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return Cache::remember('dunning-forecast:' . $today->toDateString() . ':' . $days, 600, fn () => self::compute($today, $days));
    }

    /** @return array{now: array{suspend: list<int>, terminate: list<int>}, days: array<string, array{suspend: list<int>, terminate: list<int>}>, suspend_on: bool, terminate_on: bool} */
    public static function compute(Carbon $today, int $days = self::DAYS): array
    {
        $requiresInvoice = (bool) config('settings.cronjob_suspend_requires_invoice', false);
        $terminateDays = DunningRules::days('cronjob_order_terminate', 60);
        $minSuspended = DunningRules::days('cronjob_terminate_min_suspended_days', 1);
        $last = $today->copy()->addDays($days);

        // The first day each service qualifies, then the window's first open day from it.
        $suspendFrom = [];
        $terminateFrom = [];
        for ($d = 0; $d <= $days; $d++) {
            $day = $today->copy()->addDays($d);
            foreach (DunningRules::suspendable($day, $requiresInvoice)->pluck('id') as $id) {
                $suspendFrom[(int) $id] ??= $day;
            }
            foreach (DunningRules::terminable($day)->pluck('id') as $id) {
                $terminateFrom[(int) $id] ??= $day;
            }
        }

        $now = ['suspend' => [], 'terminate' => []];
        $byDay = [];
        $place = function (string $what, int $id, Carbon $from) use (&$now, &$byDay, $today, $last) {
            if ($from->lessThanOrEqualTo($today)) {
                $now[$what][] = $id;

                return;
            }
            $on = DunningWindow::firstDayFrom($from);
            if ($on->lessThanOrEqualTo($last)) {
                $byDay[$on->toDateString()][$what][] = $id;
            }
        };
        foreach ($suspendFrom as $id => $from) {
            $place('suspend', $id, $from);
        }
        foreach ($terminateFrom as $id => $from) {
            $place('terminate', $id, $from);
        }

        // Those still to be suspended, terminated in turn once Termination Days are past and they have been suspended the
        // minimum days (DunningRules::terminable, counted from the day they will be).
        $expires = Service::query()->whereKey(array_keys($suspendFrom))->pluck('expires_at', 'id');
        foreach ($suspendFrom as $id => $from) {
            if (isset($terminateFrom[$id]) || !isset($expires[$id])) {
                continue;
            }
            $suspendedOn = DunningWindow::firstDayFrom($from->greaterThan($today) ? $from : $today);
            $after = Carbon::parse($expires[$id])->startOfDay()->addDays($terminateDays + 1)->max($suspendedOn->copy()->addDays($minSuspended));
            $place('terminate', $id, $after);
        }

        ksort($byDay);
        foreach ($byDay as &$lists) {
            $lists += ['suspend' => [], 'terminate' => []];
            sort($lists['suspend']);
            sort($lists['terminate']);
        }
        sort($now['suspend']);
        sort($now['terminate']);

        return ['now' => $now, 'days' => $byDay,
            'suspend_on' => (bool) config('settings.cronjob_suspend_enabled', true), 'terminate_on' => (bool) config('settings.cronjob_terminate_enabled', true)];
    }
}

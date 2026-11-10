<?php

namespace App\Services\Service;

use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Support\DunningWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the cron will suspend and terminate for non-payment in the days to come, if nothing is paid (owner 2026-11-06:
 * «what is suspended tomorrow or the day after, so we can tell them first when it is urgent»). Asked of the cron's own
 * rules (DunningRules) day by day, so it never drifts from them; a service is listed on the first day it qualifies, moved
 * to the dunning window's next open day. «Now»: those that qualify today, which the cron suspends at its next run, or
 * holds back while suspensions are off (the cut-over's safety net, ROADMAP 9β-4). With «only with an overdue invoice», a
 * renewal the cron bills before then counts as billed. Addons on their parent's day, as they
 * follow it (AddonCascade). What it cannot know: a payment, a hold
 * or a date staff set before then, and a card charge on its way at that moment (the cron looks again then).
 */
final class DunningForecast
{
    public const DAYS = 14;

    /** «Now» among the days: before any date, so the earlier of two is min(). */
    private const NOW = '0000-00-00';

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

        // Keyed by the settings it reads (code review of #681: suspensions switched back on at the cut-over are seen at
        // once); two minutes, so a service paid or suspended since drops out soon. The switches are read fresh.
        $settings = collect(['cronjob_order_suspend', 'cronjob_order_terminate', 'cronjob_terminate_min_suspended_days', 'cronjob_suspend_requires_invoice',
            'cronjob_suspend_enabled', 'cronjob_terminate_enabled', 'cronjob_dunning_days', 'cronjob_dunning_from'])->map(fn (string $key) => config("settings.{$key}"))->all();
        // And by the last change of a service or an invoice (ADV-681-5): one suspended, paid or held since is a new answer.
        $changed = [Service::query()->max('updated_at'), Invoice::query()->max('updated_at')];
        $forecast = Cache::remember('dunning-forecast:' . $today->toDateString() . ':' . $days . ':' . md5((string) json_encode([$settings, $changed])), 120,
            fn () => self::compute($today, $days));

        return ['suspend_on' => (bool) config('settings.cronjob_suspend_enabled', true), 'terminate_on' => (bool) config('settings.cronjob_terminate_enabled', true)] + $forecast;
    }

    /** @return array{now: array{suspend: list<int>, terminate: list<int>}, days: array<string, array{suspend: list<int>, terminate: list<int>}>, suspend_on: bool, terminate_on: bool} */
    public static function compute(Carbon $today, int $days = self::DAYS): array
    {
        $requiresInvoice = (bool) config('settings.cronjob_suspend_requires_invoice', false);
        $terminateDays = DunningRules::days('cronjob_order_terminate', 60);
        $minSuspended = DunningRules::days('cronjob_terminate_min_suspended_days', 7);
        $last = $today->copy()->addDays($days);

        // The first day each service qualifies, then the window's first open day from it.
        $suspendFrom = [];
        $terminateFrom = [];
        for ($d = 0; $d <= $days; $d++) {
            $day = $today->copy()->addDays($d);
            foreach (DunningRules::suspendable($day, $requiresInvoice)->pluck('id') as $id) {
                $suspendFrom[(int) $id] ??= $day;
            }
            // «Only with an overdue invoice»: a renewal the cron bills before then counts too (ADV-681-3). Its invoice is
            // due on the due date and issued «invoice days» before it, so it is overdue as soon as the service is.
            if ($requiresInvoice) {
                // An open charge is not a bill (renewalInvoices, as suspendable() reads it: review REV-CB-1).
                $unbilled = DunningRules::suspendable($day)->whereDoesntHave('renewalInvoices', fn ($invoices) => $invoices->where('invoices.status', Invoice::STATUS_UNPAID))
                    ->whereKeyNot(array_keys($suspendFrom))->with(['plan', 'nextPlan'])->get();
                foreach ($unbilled as $service) {
                    $billedOn = Carbon::parse($service->expires_at)->startOfDay()->subDays(RenewalBilling::serviceInvoiceDays(RenewalBilling::billedPlan($service) ?? $service->plan));
                    if ($billedOn->greaterThan($today)) {
                        $suspendFrom[(int) $service->id] ??= $day;
                    }
                }
            }
            foreach (DunningRules::terminable($day)->pluck('id') as $id) {
                $terminateFrom[(int) $id] ??= $day;
            }
        }

        // Each service's day: «now» (qualifies today), a day within the forecast, or none past it.
        $dayOf = ['suspend' => [], 'terminate' => []];
        // Today out of the dunning window: what qualifies now waits for its next open day (code review of #681).
        $todayOpen = DunningWindow::firstDayFrom($today)->isSameDay($today);
        $place = function (string $what, int $id, Carbon $from) use (&$dayOf, $today, $last, $todayOpen) {
            $from = $from->lessThan($today) ? $today : $from;
            $day = $from->isSameDay($today) && $todayOpen ? self::NOW : DunningWindow::firstDayFrom($from)->toDateString();
            if ($day <= $last->toDateString()) {
                $dayOf[$what][$id] = isset($dayOf[$what][$id]) ? min($dayOf[$what][$id], $day) : $day;
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
        // Not while suspensions are off (the safety net): a service never suspended is never terminated (code review of #681).
        $suspendOn = (bool) config('settings.cronjob_suspend_enabled', true);
        $expires = $suspendOn ? Service::query()->whereKey(array_keys($suspendFrom))->pluck('expires_at', 'id') : collect();
        foreach ($suspendOn ? $suspendFrom : [] as $id => $from) {
            if (isset($terminateFrom[$id]) || !isset($expires[$id])) {
                continue;
            }
            $suspendedOn = DunningWindow::firstDayFrom($from->greaterThan($today) ? $from : $today);
            $after = Carbon::parse($expires[$id])->startOfDay()->addDays($terminateDays + 1)->max($suspendedOn->copy()->addDays($minSuspended));
            $place('terminate', $id, $after);
        }

        // Addons follow their parent (AddonCascade), on its day when that comes first: the running ones are suspended with
        // it, the live ones end with it.
        foreach (['suspend' => [Service::STATUS_ACTIVE], 'terminate' => Service::PAYING_STATUSES] as $what => $statuses) {
            $parents = $dayOf[$what];
            if ($parents === []) {
                continue;
            }
            foreach (Service::query()->whereIn('parent_service_id', array_keys($parents))->whereIn('status', $statuses)->get(['id', 'parent_service_id']) as $addon) {
                $day = $parents[(int) $addon->parent_service_id];
                $own = $dayOf[$what][(int) $addon->id] ?? null;
                $dayOf[$what][(int) $addon->id] = $own === null ? $day : min($own, $day);
            }
        }

        $now = ['suspend' => [], 'terminate' => []];
        $byDay = [];
        foreach ($dayOf as $what => $days) {
            ksort($days);
            foreach ($days as $id => $day) {
                if ($day === self::NOW) {
                    $now[$what][] = (int) $id;
                } else {
                    $byDay[$day][$what][] = (int) $id;
                }
            }
        }
        ksort($byDay);
        foreach ($byDay as &$lists) {
            $lists += ['suspend' => [], 'terminate' => []];
        }
        unset($lists);

        return ['now' => $now, 'days' => $byDay,
            'suspend_on' => (bool) config('settings.cronjob_suspend_enabled', true), 'terminate_on' => (bool) config('settings.cronjob_terminate_enabled', true)];
    }
}

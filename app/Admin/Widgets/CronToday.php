<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\CronStats;
use App\Services\Ops\CronDay;
use App\Services\Service\DunningForecast;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The cron on the dashboard (owner 2026-11-06): what it did yesterday, and what it suspends and terminates today and in
 * the next two days if nothing is paid; each a link to that day on the calendar (CronStats).
 */
class CronToday extends Widget
{
    // In the right column, under Needs attention (Dashboard::RIGHT).
    protected static ?int $sort = 5;

    protected string $view = 'admin.widgets.cron-today';

    public static function canView(): bool
    {
        return Auth::user()?->hasPermission('admin.cron_stats.view') ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();
        $cell = CronDay::range($yesterday, $yesterday)[$yesterday->toDateString()] ?? null;
        try {
            $forecast = DunningForecast::upcoming($today);
        } catch (Throwable $e) {
            report($e);
            $forecast = null;
        }
        $next = [];
        for ($d = 1; $d <= 2; $d++) {
            $day = $today->copy()->addDays($d)->toDateString();
            $next[] = ['label' => $today->copy()->addDays($d)->translatedFormat('l j/n'), 'url' => self::url($day),
                'suspend' => count($forecast['days'][$day]['suspend'] ?? []), 'terminate' => count($forecast['days'][$day]['terminate'] ?? [])];
        }

        return [
            'yesterday' => $cell, 'yesterdayUrl' => self::url($yesterday->toDateString()),
            'now' => $forecast === null ? null : ['suspend' => count($forecast['now']['suspend']), 'terminate' => count($forecast['now']['terminate'])],
            'nowUrl' => self::url($today->toDateString()),
            'next' => $next, 'netOn' => $forecast !== null && (!$forecast['suspend_on'] || !$forecast['terminate_on']),
        ];
    }

    private static function url(string $day): string
    {
        return CronStats::getUrl(['day' => $day, 'month' => substr($day, 0, 7)]);
    }
}

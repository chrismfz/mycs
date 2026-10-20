<?php

namespace App\Admin\Widgets\CronStat;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\FailedJobResource;
use App\Admin\Resources\LicenseResource;
use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\TicketQuarantineResource;
use App\Services\Ops\Health;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Automation Status (as WHMCS's): each of Health's checks (docs/ops.md), its state and why, and where to look.
 */
class HealthChecks extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '60s';

    /** Where staff look when a check is not ok. */
    private const LINKS = [
        'queue' => FailedJobResource::class,
        'mail' => EmailLogResource::class,
        'module_actions' => ModuleActionResource::class,
        'licenses' => LicenseResource::class,
        'domains' => DomainResource::class,
        'ticket_mail' => TicketQuarantineResource::class,
    ];

    protected function getHeading(): ?string
    {
        return __('Checks');
    }

    protected function getStats(): array
    {
        $stats = [];
        foreach (Health::cached()['checks'] as $name => $check) {
            $resource = self::LINKS[$name] ?? null;
            $stats[] = Stat::make(__('health.' . $name), self::label($check['status']))
                ->description($check['detail'])
                ->descriptionIcon($check['status'] === Health::OK ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color(self::color($check['status']))
                ->url($resource !== null && $resource::canViewAny() ? $resource::getUrl('index') : null);
        }

        return $stats;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            Health::OK => __('Ok'),
            Health::WARN => __('Attention'),
            default => __('Failing'),
        };
    }

    public static function color(string $status): string
    {
        return match ($status) {
            Health::OK => 'success',
            Health::WARN => 'warning',
            default => 'danger',
        };
    }
}

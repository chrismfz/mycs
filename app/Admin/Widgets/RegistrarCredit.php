<?php

namespace App\Admin\Widgets;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\RegistrarBalances;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * «Registrar credit» (G8-1, owner 2026-10-19, as WHMCS shows it): what is left with each registrar, as last read by
 * `domains:balances` (hourly); a page never calls a registrar. Red when staff are told about it (low, suspended, unread).
 */
class RegistrarCredit extends StatsOverviewWidget
{
    protected static ?int $sort = 15;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Registrar credit';

    protected function getStats(): array
    {
        return app(RegistrarBalances::class)->connections()->map(function (DomainRegistrarConnection $connection) {
            $balance = $connection->balance ?? [];
            $amount = is_string($balance['amount'] ?? null) ? number_format((float) $balance['amount'], 2, ',', '.') . ' ' . match ($balance['currency'] ?? null) {
                'EUR' => '€',
                null => '',
                default => $balance['currency'],
            } : '—';
            $problem = RegistrarBalances::problem($connection);
            $read = is_string($balance['read_at'] ?? null) ? __('Read :when', ['when' => Carbon::parse($balance['read_at'])->diffForHumans()]) : __('Not read yet');
            $reserved = is_string($balance['reserved'] ?? null) && (float) $balance['reserved'] !== 0.0 ? ' · ' . __('reserved :amount', ['amount' => $balance['reserved']]) : '';

            return Stat::make($connection->label, trim($amount))
                ->description($problem ?? $read . $reserved)
                ->descriptionIcon($problem !== null ? 'heroicon-m-exclamation-triangle' : null)
                ->color($problem !== null ? 'danger' : 'success')
                ->url(DomainRegistrarConnectionResource::canViewAny() ? DomainRegistrarConnectionResource::getUrl('index') : null);
        })->all();
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.registrar_credit') && app(RegistrarBalances::class)->connections()->isNotEmpty();
    }
}

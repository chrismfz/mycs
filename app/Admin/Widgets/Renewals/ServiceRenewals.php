<?php

namespace App\Admin\Widgets\Renewals;

use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Services (hosting, servers, licenses) due soon (owner 2026-10-17, as myip's dashboard hack): who, what, cycle and due
 * date. The next 30 days by default (7, 60, 90); recurring plans only.
 */
class ServiceRenewals extends TableWidget
{
    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Upcoming service renewals'))
            ->query(Service::query()->with(['client', 'product', 'plan'])
                ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
                ->whereNotNull('expires_at')
                ->whereHas('plan', fn ($plan) => $plan->where('type', 'recurring')))
            ->defaultSort('expires_at')
            ->filters([
                SelectFilter::make('within')->label(__('Within'))->default('30')
                    ->options(['7' => __('7 days'), '30' => __('30 days'), '60' => __('60 days'), '90' => __('90 days')])
                    ->query(fn ($query, array $data) => $query->whereDate('expires_at', '<=', today()->addDays((int) ($data['value'] ?: 30)))),
            ])
            ->columns([
                TextColumn::make('label')->label(__('Service')),
                TextColumn::make('client.name')->label(__('Client')),
                TextColumn::make('cycle')->label(__('Billing cycle'))
                    ->state(fn (Service $record) => $record->plan === null ? '—'
                        : $record->plan->billing_period . ' ' . $record->plan->billing_unit),
                TextColumn::make('expires_at')->label(__('Due'))->date('d/m/Y')->sortable()
                    ->color(fn (Service $record) => $record->expires_at?->isPast() ? 'danger' : null),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => Service::statusLabel($state))
                    ->color(fn (string $state) => Service::statusColor($state)),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->paginated([10, 25, 50]);
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals');
    }
}

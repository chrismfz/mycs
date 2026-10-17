<?php

namespace App\Admin\Widgets\Renewals;

use App\Admin\Resources\ServiceResource;
use App\Models\Service;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Services to look at (owner 2026-10-17, as myip's «Next Due Date Zeros»): an active or suspended recurring service
 * without a due date is never billed nor suspended.
 */
class ServicesToCheck extends TableWidget
{
    protected static ?int $sort = 23;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Services to check'))
            ->query(self::query())
            ->columns([
                TextColumn::make('label')->label(__('Service')),
                TextColumn::make('client.name')->label(__('Client')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => Service::statusLabel($state))
                    ->color(fn (string $state) => Service::statusColor($state)),
                TextColumn::make('why')->label(__('Why'))->state(__('No due date: never billed nor suspended')),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading(__('Nothing to check'))
            ->paginated([10, 25]);
    }

    /** @return Builder<Service> */
    public static function query(): Builder
    {
        return Service::query()->with(['client', 'product'])->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereNull('expires_at')->whereHas('plan', fn ($plan) => $plan->where('type', 'recurring'));
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals');
    }
}

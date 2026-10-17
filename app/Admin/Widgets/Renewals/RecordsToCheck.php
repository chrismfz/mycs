<?php

namespace App\Admin\Widgets\Renewals;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Models\Domain;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Domains to look at (owner 2026-10-17, as myip's «Expiry Date Zeros» and «Pending Transfer Domains»): alive without an
 * expiry, a transfer pending for days, or still Active days past its expiry (its sync lags). Each with why. Services
 * without a due date are counted on the services widget's side (ServicesToCheck).
 */
class RecordsToCheck extends TableWidget
{
    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = 'full';

    /** A transfer pending longer than this, or a lapse not synced after this many days, is looked at. */
    public const DAYS = 5;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Domains to check'))
            ->query(self::query())
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('fqdn')->label(__('Domain'))->searchable(),
                TextColumn::make('client.name')->label(__('Client')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('expires_at')->label(__('Expires'))->date('d/m/Y')->placeholder('—'),
                TextColumn::make('why')->label(__('Why'))->state(fn (Domain $record) => self::why($record)),
            ])
            ->recordUrl(fn (Domain $record) => DomainResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('Nothing to check'))
            ->paginated([10, 25]);
    }

    /** @return Builder<Domain> */
    public static function query(): Builder
    {
        $alive = [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value, DomainStatus::Redemption->value];

        return Domain::query()->with('client')->where(fn ($q) => $q
            ->where(fn ($none) => $none->whereIn('status', $alive)->whereNull('expires_at'))
            ->orWhere(fn ($transfer) => $transfer->where('status', DomainStatus::PendingTransfer->value)->where('updated_at', '<', now()->subDays(self::DAYS)))
            ->orWhere(fn ($lag) => $lag->where('status', DomainStatus::Active->value)->whereDate('expires_at', '<', today()->subDays(self::DAYS))));
    }

    private static function why(Domain $domain): string
    {
        return match (true) {
            $domain->expires_at === null => __('No expiry date'),
            $domain->status === DomainStatus::PendingTransfer => __('Transfer pending for more than :days days', ['days' => self::DAYS]),
            default => __('Active but expired more than :days days ago: check its sync', ['days' => self::DAYS]),
        };
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals');
    }
}

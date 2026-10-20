<?php

namespace App\Admin\Widgets\Renewals;

use App\Admin\Resources\DomainResource;
use App\Console\Commands\Domains\SyncDomains;
use App\Enums\DomainStatus;
use App\Models\Domain;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Domains to look at (owner 2026-10-17, as myip's «Expiry Date Zeros» and «Pending Transfer Domains»), each with why:
 * alive without an expiry; a transfer or a registration pending since their order longer than the sync's own warning
 * (SyncDomains::staleTransferDays(), by their start, not their last edit: review R2); still Active days past its expiry,
 * which means the 4-hourly sync or its lifecycle has not run (review R12).
 */
class RecordsToCheck extends TableWidget
{
    protected static ?int $sort = 22;

    // Two side by side, two rows (owner 2026-10-21); a hidden «to check» list leaves no hole.
    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    /** Active this many days past its expiry: the sync has not moved it. */
    public const LAG_DAYS = 2;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Domains to check'))
            ->query(self::query())
            ->defaultSort('id')
            ->columns([
                TextColumn::make('fqdn')->label(__('Domain'))->searchable(),
                TextColumn::make('client.name')->label(__('Client')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('expires_at')->label(__('Expires'))->date('d/m/Y')->placeholder('—'),
                TextColumn::make('why')->label(__('Why'))->state(fn (Domain $record) => self::why($record)),
            ])
            ->recordUrl(fn (Domain $record) => DomainResource::canView($record) ? DomainResource::getUrl('view', ['record' => $record]) : null)
            ->emptyStateHeading(__('Nothing to check'))
            ->paginated([10, 25]);
    }

    /** @return Builder<Domain> */
    public static function query(): Builder
    {
        $alive = [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value, DomainStatus::Redemption->value];
        $pending = [DomainStatus::PendingTransfer->value, DomainStatus::PendingRegister->value];

        return Domain::query()->with(['client.primaryContact', 'client.ownerGrant.user'])->where(fn ($q) => $q
            ->where(fn ($none) => $none->whereIn('status', $alive)->whereNull('expires_at'))
            ->orWhere(fn ($stuck) => $stuck->whereIn('status', $pending)->where('created_at', '<', now()->subDays(SyncDomains::staleTransferDays())))
            ->orWhere(fn ($lag) => $lag->where('status', DomainStatus::Active->value)->whereDate('expires_at', '<', today()->subDays(self::LAG_DAYS))));
    }

    private static function why(Domain $domain): string
    {
        // The stuck order first (review R1: a pending one has no expiry yet).
        return match (true) {
            $domain->status === DomainStatus::PendingTransfer => __('Transfer pending for more than :days days', ['days' => SyncDomains::staleTransferDays()]),
            $domain->status === DomainStatus::PendingRegister => __('Registration pending for more than :days days', ['days' => SyncDomains::staleTransferDays()]),
            $domain->expires_at === null => __('No expiry date'),
            default => __('Active but past its expiry: the domain sync has not run'),
        };
    }

    public static function canView(): bool
    {
        // A list of problems: on the dashboard only while there is one (owner 2026-10-21: empty boxes take the page).
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals') && DomainResource::canViewAny() && self::query()->exists();
    }
}

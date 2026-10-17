<?php

namespace App\Admin\Widgets\Renewals;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Services\Domains\DomainRenewalService;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Domains expiring soon (owner 2026-10-17, as myip's dashboard hack and its .eu hook, for every TLD): who, where, when,
 * whether it renews and how its renewal invoice stands. The next 30 days by default (7, 60, 90).
 */
class DomainRenewals extends TableWidget
{
    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Upcoming domain renewals'))
            ->query(Domain::query()->with(['client', 'registrarConnection'])
                ->whereIn('status', [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value, DomainStatus::Redemption->value])
                ->whereNotNull('expires_at'))
            ->defaultSort('expires_at')
            ->filters([
                SelectFilter::make('within')->label(__('Within'))->default('30')
                    ->options(['7' => __('7 days'), '30' => __('30 days'), '60' => __('60 days'), '90' => __('90 days')])
                    ->query(fn ($query, array $data) => $query->whereDate('expires_at', '<=', today()->addDays((int) ($data['value'] ?: 30)))),
            ])
            ->columns([
                TextColumn::make('fqdn')->label(__('Domain'))->searchable(),
                TextColumn::make('client.name')->label(__('Client')),
                TextColumn::make('registrarConnection.label')->label(__('Registrar'))->placeholder(__('Manual')),
                TextColumn::make('years')->label(__('Period')),
                TextColumn::make('expires_at')->label(__('Expires'))->date('d/m/Y')->sortable()
                    ->color(fn (Domain $record) => $record->expires_at?->isPast() ? 'danger' : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
                IconColumn::make('auto_renew')->label(__('Auto renew'))->boolean(),
                TextColumn::make('renewal')->label(__('Renewal invoice'))->state(fn (Domain $record) => self::renewal($record))->badge()
                    ->color(fn (?string $state) => match ($state) {
                        __('Paid') => 'success',
                        __('Unpaid') => 'warning',
                        __('Not invoiced') => 'gray',
                        default => null,
                    }),
            ])
            ->recordUrl(fn (Domain $record) => DomainResource::getUrl('view', ['record' => $record]))
            ->paginated([10, 25, 50]);
    }

    /** How its next period stands: paid (waiting for its registrar, or done), unpaid, or not invoiced yet. */
    private static function renewal(Domain $domain): string
    {
        $line = $domain->expires_at === null ? null : DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay(), payable: true);

        return match ($line?->invoice?->status) {
            Invoice::STATUS_PAID => __('Paid'),
            Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT => __('Unpaid'),
            default => __('Not invoiced'),
        };
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals');
    }
}

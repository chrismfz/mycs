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
use Illuminate\Support\HtmlString;

/**
 * Domains expiring soon (owner 2026-10-17, as myip's dashboard hack and its .eu hook, for every TLD): who, where, when,
 * whether it renews and how its renewal invoice stands. The next 30 days by default (7, 60, 90), and those expired in
 * the last 90 days (still the customer's to renew), not every old one (review R3).
 */
class DomainRenewals extends TableWidget
{
    protected static ?int $sort = 20;

    // Two side by side, two rows (owner 2026-10-21); a hidden «to check» list leaves no hole.
    protected int|string|array $columnSpan = 1; // the dashboard's grid: one column below lg (full width), two from lg

    /** An expired domain stays on the list this long after its expiry. */
    public const PAST_DAYS = 90;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Upcoming domain renewals'))
            ->query(Domain::query()->with(['client.primaryContact', 'client.ownerGrant.user', 'registrarConnection', 'domainTld.registrarConnection'])
                ->whereIn('status', [DomainStatus::Active->value, DomainStatus::Expired->value, DomainStatus::Grace->value, DomainStatus::Redemption->value])
                ->whereNotNull('expires_at')->whereDate('expires_at', '>=', today()->subDays(self::PAST_DAYS)))
            ->defaultSort('expires_at')
            ->filters([
                SelectFilter::make('within')->label(__('Within'))->default('30')->selectablePlaceholder(false)
                    ->options(['7' => __('7 days'), '30' => __('30 days'), '60' => __('60 days'), '90' => __('90 days')])
                    ->query(fn ($query, array $data) => $query->whereDate('expires_at', '<=', today()->addDays((int) ($data['value'] ?: 30)))),
            ])
            ->columns([
                TextColumn::make('fqdn')->label(__('Domain'))->searchable(),
                TextColumn::make('client.name')->label(__('Client')),
                // Its own registrar, else its TLD's (review R5).
                TextColumn::make('registrar')->label(__('Registrar'))->placeholder('—')
                    ->state(fn (Domain $record) => $record->effectiveRegistrarConnection()?->label),
                TextColumn::make('years')->label(__('Period'))->formatStateUsing(fn ($state) => trans_choice(':count year|:count years', (int) $state, ['count' => (int) $state])),
                TextColumn::make('expires_at')->label(__('Expires'))->date('d/m/Y')->sortable()
                    ->color(fn (Domain $record) => $record->expires_at?->lt(today()) ? 'danger' : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
                IconColumn::make('auto_renew')->label(__('Auto renew'))->boolean(),
                TextColumn::make('renewal')->label(__('Renewal invoice'))->badge()
                    ->state(fn (Domain $record) => self::renewal($record))
                    ->formatStateUsing(fn (string $state) => __(self::STATES[$state][0]))
                    ->color(fn (string $state) => self::STATES[$state][1]),
            ])
            ->recordUrl(fn (Domain $record) => DomainResource::canView($record) ? DomainResource::getUrl('view', ['record' => $record]) : null)
            // Empty, one line, not a big box (owner 2026-10-21); the filter above stays to widen the window.
            ->emptyState(fn () => new HtmlString('<p class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400">' . e(__('No domain renews in this window.')) . '</p>'))
            ->paginated([10, 25, 50]);
    }

    /** The state of its next period's invoice: label and colour (review R11: by state, not by translated label). */
    public const STATES = [
        'paid' => ['Paid', 'success'],
        'unpaid' => ['Unpaid', 'warning'],
        'draft' => ['Draft', 'gray'],
        'none' => ['Not invoiced', 'gray'],
    ];

    private static function renewal(Domain $domain): string
    {
        $line = $domain->expires_at === null ? null : DomainRenewalService::billedLine($domain, $domain->expires_at->copy()->startOfDay(), payable: true);
        $line?->loadMissing('invoice');

        return match ($line?->invoice?->status) {
            Invoice::STATUS_PAID => 'paid',
            Invoice::STATUS_UNPAID => 'unpaid',
            Invoice::STATUS_DRAFT => 'draft',
            default => 'none',
        };
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.widgets.renewals') && DomainResource::canViewAny();
    }
}

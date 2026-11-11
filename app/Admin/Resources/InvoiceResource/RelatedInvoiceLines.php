<?php

namespace App\Admin\Resources\InvoiceResource;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource\OrderSummary;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * «Related invoices» of a service or a domain (owner 2026-11-11: «when was what paid, with which invoice», without
 * opening invoices one by one): one row per invoice line of it, newest first, with the line's own amount and period, its
 * invoice's status, when it was paid and how. Read-only. A line the importer brought without a period shows none: never
 * a guess.
 */
class RelatedInvoiceLines
{
    /** As the invoices list: whoever may not list invoices does not see their lines here either. */
    public static function canView(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    public static function period(InvoiceItem $line): ?string
    {
        if ($line->period_start === null && $line->period_end === null) {
            return null;
        }

        return ($line->period_start?->format('d/m/Y') ?? '…') . ' – ' . ($line->period_end?->format('d/m/Y') ?? '…');
    }

    /** @param Closure(InvoiceItem): ?string $type what the line is (the service itself, an addon, a package change; a domain's registration, renewal, fee) */
    public static function table(Table $table, Closure $type): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.currency', 'invoice.transactions.gateway']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('invoice_items.invoice_id')->orderByDesc('invoice_items.id'))
            ->columns([
                TextColumn::make('invoice_id')->label(__('Invoice'))
                    ->state(fn (InvoiceItem $record) => '#' . $record->invoice_id
                        . (filled($record->invoice->number) && (string) $record->invoice->number !== (string) $record->invoice_id ? ' · ' . $record->invoice->number : ''))
                    ->url(fn (InvoiceItem $record) => InvoiceResource::getUrl('edit', ['record' => $record->invoice])),
                TextColumn::make('invoice.created_at')->label(__('Issued'))->date('d/m/Y'),
                TextColumn::make('type')->label(__('Type'))->placeholder('—')->state($type),
                TextColumn::make('description')->label(__('Description'))->wrap(),
                TextColumn::make('period')->label(__('Period'))->placeholder('—')->state(fn (InvoiceItem $record) => self::period($record)),
                TextColumn::make('formattedTotal')->label(__('Amount (net)')),
                TextColumn::make('invoice.status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => Invoice::statusLabel($state))
                    ->color(fn (string $state): string => Invoice::statusColor($state)),
                TextColumn::make('invoice.paid_at')->label(__('Paid on'))->date('d/m/Y')->placeholder('—'),
                TextColumn::make('paid_with')->label(__('Paid with'))
                    ->state(fn (InvoiceItem $record) => OrderSummary::paymentMethod($record->invoice)),
            ])
            ->paginated([5, 25, 50])
            ->defaultPaginationPageOption(5);
    }
}

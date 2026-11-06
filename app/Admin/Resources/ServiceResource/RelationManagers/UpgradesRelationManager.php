<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The service's changes of package, as WHMCS's upgrades list (owner 2026-11-04): when, from what to what, what it renewed
 * at before and after, the invoice that charged it or the credit a downgrade gave, staff's discount on it, who asked for
 * it. Read only: a change is placed by «Upgrade/Downgrade» or by the customer's upgrade page.
 */
class UpgradesRelationManager extends RelationManager
{
    protected static string $relationship = 'upgrade';

    protected static ?string $title = 'Package changes';

    /** Whoever sees the service sees its changes (they have no policy of their own), once it has one. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Service && $ownerRecord->upgrade()->exists() && Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'plan', 'fromProduct', 'invoice.items', 'invoice.adjustmentNotes', 'invoice.currency', 'staff']))
            ->columns([
                TextColumn::make('created_at')->label('Placed')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('change')->label('Change')
                    ->state(fn (ServiceUpgrade $record) => ($record->fromProduct->name ?? '—') . ' → ' . ($record->product->name ?? '—')),
                TextColumn::make('plan.name')->label('Cycle'),
                TextColumn::make('renews')->label('Recurring (net)')
                    ->state(fn (ServiceUpgrade $record) => $record->to_price === null ? '—'
                        : ($record->from_price ?? '—') . ' → ' . $record->to_price),
                TextColumn::make('invoice_id')->label('Invoice')
                    ->formatStateUsing(fn (ServiceUpgrade $record) => $record->invoice
                        ? "#{$record->invoice->id} {$record->invoice->formattedTotal} (" . Invoice::statusLabel($record->invoice->status) . ')' : '—')
                    ->url(fn (ServiceUpgrade $record) => $record->invoice ? InvoiceResource::getUrl('edit', ['record' => $record->invoice]) : null)
                    ->placeholder('—'),
                TextColumn::make('credit_cents')->label('Credit')
                    ->formatStateUsing(fn ($state) => number_format(((int) $state) / 100, 2, '.', ''))
                    ->placeholder('—'),
                // Staff's discount on the difference (owner 2026-11-06): net, its percentage, and «waived» with no invoice.
                TextColumn::make('discount')->label('Discount (net)')
                    ->formatStateUsing(fn ($state, ServiceUpgrade $record) => number_format((float) $state, 2, '.', '')
                        . ($record->discount_percent !== null ? ' (' . rtrim(rtrim((string) $record->discount_percent, '0'), '.') . '%)' : '')
                        . ($record->invoice_id === null ? ', waived' : ''))
                    ->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        ServiceUpgrade::STATUS_COMPLETED => 'success',
                        ServiceUpgrade::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('source')->label('By')
                    ->state(fn (ServiceUpgrade $record) => match ($record->source) {
                        ServiceUpgrade::SOURCE_STAFF => 'Staff' . ($record->staff ? ": {$record->staff->name}" : ''),
                        ServiceUpgrade::SOURCE_CLIENT => 'Customer',
                        default => '—',
                    }),
                TextColumn::make('applied_at')->label('Applied')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->defaultSort('id', 'desc');
    }
}

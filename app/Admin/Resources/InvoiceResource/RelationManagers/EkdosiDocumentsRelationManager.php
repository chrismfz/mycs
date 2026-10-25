<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Παραστατικά» (docs/ekdosi-bridge.md, E2): the fiscal documents Ekdosi issued from this invoice, as it wrote them
 * back — its code, the MARK at AADE, active or cancelled, its PDF on Ekdosi. Read only: Ekdosi owns them.
 */
class EkdosiDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'ekdosiDocuments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Fiscal documents');
    }

    /** Where the bridge is on, or the invoice has some (the bridge turned off later keeps them visible). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Invoice && parent::canViewForRecord($ownerRecord, $pageClass)
            && ((bool) config('settings.ekdosi_enabled') || $ownerRecord->ekdosiDocuments()->exists());
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mark')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('code')->label(__('Document'))->placeholder('—'),
                TextColumn::make('mark')->label(__('MARK'))->copyable()->fontFamily('mono'),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === EkdosiDocument::CANCELLED ? __('Cancelled') : __('Active'))
                    ->color(fn (string $state): string => $state === EkdosiDocument::CANCELLED ? 'danger' : 'success'),
                TextColumn::make('pdf_url')->label(__('PDF'))
                    ->state(fn (EkdosiDocument $record): ?string => $record->pdf_url ? __('Open') : null)
                    ->url(fn (EkdosiDocument $record): ?string => $record->pdf_url, shouldOpenInNewTab: true)
                    ->placeholder('—'),
                TextColumn::make('created_at')->label(__('Issued'))->dateTime(),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->emptyStateHeading(__('No fiscal document yet'))
            ->emptyStateDescription(__('Ekdosi writes it back here when it issues the document.'));
    }
}

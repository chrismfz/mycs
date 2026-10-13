<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Enums\AdjustmentNoteStatus;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdjustmentNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'adjustmentNotes';

    protected static ?string $title = 'Adjustment Notes';

    protected function canModifyAdjustmentNotes(): bool
    {
        return !config('settings.immutable_invoices_enabled') || $this->getOwnerRecord()->status === Invoice::STATUS_PENDING;
    }

    private function ownerIsPaid(): bool
    {
        $invoice = $this->getOwnerRecord();

        return $invoice instanceof Invoice && $invoice->status === Invoice::STATUS_PAID;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label('Number')
                    ->helperText('The number will be generated automatically')
                    ->disabled(),
                Hidden::make('type')
                    ->default('credit'),
                TextInput::make('amount')
                    ->label('Amount')
                    ->required()
                    ->numeric()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if (is_numeric($state)) {
                            $set('type', $state < 0 ? 'credit' : 'debit');
                        }
                    })
                    ->mask(RawJs::make(
                        <<<'JS'
                            $money($input, '.', '', 2)
                        JS
                    ))
                    ->placeholder('Enter the amount (negative = credit, positive = debit)'),
                Textarea::make('description')
                    ->label('Description')
                    ->required()
                    ->placeholder('Enter a description'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit' => 'danger',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                TextColumn::make('formattedAmount')
                    ->label('Amount')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof AdjustmentNoteStatus ? $state->value : $state) {
                        AdjustmentNoteStatus::Active->value => 'success',
                        AdjustmentNoteStatus::Voided->value => 'danger',
                    })
                    ->formatStateUsing(fn ($state): string => $state instanceof AdjustmentNoteStatus ? $state->value : ucfirst($state))
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                // A paid invoice keeps its total (AdjustmentNoteObserver): set it back to pending first.
                CreateAction::make()
                    ->visible(fn (): bool => $this->canModifyAdjustmentNotes() && !$this->ownerIsPaid()),
            ])
            // Append-only (docs/money-trail.md, MT-1c): never edited or deleted; a wrong note is voided and a new one
            // written.
            ->recordActions([
                VoidAdjustmentNote::make(), // its own rule: VoidAdjustmentNote::allowed
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}

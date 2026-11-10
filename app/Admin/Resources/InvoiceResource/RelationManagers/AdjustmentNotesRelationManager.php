<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Admin\Actions\VoidAdjustmentNote;
use App\Enums\AdjustmentNoteStatus;
use App\Enums\AdjustmentNoteType;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

    private function ownerIsRefunded(): bool
    {
        $invoice = $this->getOwnerRecord();

        return $invoice instanceof Invoice && $invoice->status === Invoice::STATUS_REFUNDED;
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
                // A refunded invoice keeps its total (AdjustmentNoteObserver); a paid one takes notes and says its balance.
                CreateAction::make()
                    ->visible(fn (): bool => !$this->ownerIsRefunded())
                    ->after(fn () => VoidAdjustmentNote::tellBalance($this->getOwnerRecord()->getKey())),
            ])
            // A note staff wrote is voided, edited (description, amount) or deleted (owner 2026-11-10; it was append-only,
            // MT-1c): each under the payment locks and in the note's audit. Not a refund's or a cancellation's.
            ->recordActions([
                VoidAdjustmentNote::make(), // its own rule: VoidAdjustmentNote::allowed
                EditAction::make()
                    ->visible(fn (AdjustmentNote $record): bool => VoidAdjustmentNote::correctable($record, 'update'))
                    ->using(function (AdjustmentNote $record, array $data): AdjustmentNote {
                        VoidAdjustmentNote::correct($record, 'update', fn (AdjustmentNote $note) => $note->update([
                            'amount' => $data['amount'], 'description' => $data['description'],
                            'type' => (float) $data['amount'] < 0 ? AdjustmentNoteType::Credit->value : AdjustmentNoteType::Debit->value,
                        ]));

                        return $record->refresh();
                    }),
                DeleteAction::make()
                    ->visible(fn (AdjustmentNote $record): bool => VoidAdjustmentNote::correctable($record, 'delete'))
                    ->modalDescription(__('The note is removed from the invoice, whose total changes. Its number is not used again.'))
                    ->using(function (AdjustmentNote $record): bool {
                        VoidAdjustmentNote::correct($record, 'delete', fn (AdjustmentNote $note) => $note->delete());

                        return true;
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}

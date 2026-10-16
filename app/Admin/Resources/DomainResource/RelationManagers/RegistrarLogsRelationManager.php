<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Models\DomainRegistrarLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every call to the registrar for this domain (docs/domains.md D-17, D4b): what was asked and answered, by whom, or why
 * it failed. Secrets were masked before they were written; an EPP code is never here. Read-only.
 */
class RegistrarLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrarLogs';

    protected static ?string $title = 'Registrar log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime(),
                TextColumn::make('action')->label(__('Action')),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn (string $state) => match ($state) {
                        DomainRegistrarLog::STATUS_FAILED => 'danger',
                        DomainRegistrarLog::STATUS_OK => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('staff.name')->label(__('By'))->placeholder(__('System')),
                TextColumn::make('error')->label(__('Error'))->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()->schema(fn (Schema $schema) => $schema->components([
                    TextEntry::make('request')->label(__('Request'))
                        ->state(fn (DomainRegistrarLog $record) => json_encode($record->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                        ->fontFamily('mono')->columnSpanFull(),
                    TextEntry::make('response')->label(__('Response'))
                        ->state(fn (DomainRegistrarLog $record) => json_encode($record->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                        ->fontFamily('mono')->columnSpanFull(),
                    TextEntry::make('error')->label(__('Error'))->placeholder('—')->columnSpanFull(),
                ])),
            ]);
    }
}

<?php

namespace App\Admin\Resources\DomainResource\RelationManagers;

use App\Admin\Resources\DomainRegistrarLogResource\RegistrarLogTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every call to the registrar for this domain (docs/domains.md D-17, D4b): what was asked and answered, by whom, or why
 * it failed. Secrets were masked before they were written; an EPP code is never here. Read-only.
 */
class RegistrarLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrarLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Registrar log');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['staff', 'user'])) // who asked, per row (review R13)
            ->columns(RegistrarLogTable::columns(withDomain: false))
            ->recordActions([RegistrarLogTable::viewAction()]);
    }
}

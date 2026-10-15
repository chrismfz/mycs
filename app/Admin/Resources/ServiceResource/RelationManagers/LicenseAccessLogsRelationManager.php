<?php

namespace App\Admin\Resources\ServiceResource\RelationManagers;

use App\Models\Service;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Its license's access log (WHMCS's mod_licensinglog, 90 days): the failed checks as WHMCS logs them, one «License Active»
 * a day per address (beyond WHMCS), and reissues.
 */
class LicenseAccessLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'licenseAccessLogs';

    protected static ?string $title = 'License access log';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Service && $ownerRecord->license !== null && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('message')->wrap(),
                TextColumn::make('ip')->label('IP'),
                TextColumn::make('domain')->toggleable(),
                TextColumn::make('directory')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated([25, 50, 100]);
    }
}

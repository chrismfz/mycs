<?php

namespace App\Admin\RelationManagers;

use App\Admin\Resources\EmailLogResource;
use App\Models\EmailLog;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** «Emails» on an account, an invoice or a service (the mail audit, docs/mail.md): its mails, newest first, and Resend. */
class EmailLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'emailLogs';

    protected static ?string $title = 'Emails';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('viewAny', EmailLog::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns(EmailLogResource::columns())
            ->filters(EmailLogResource::filters())
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('view')->label('View')->icon('ri-eye-line')
                    ->url(fn (EmailLog $record) => EmailLogResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (EmailLog $record) => Gate::allows('view', $record)),
                EmailLogResource::resendAction(),
            ])
            ->paginated([10, 25, 50]);
    }
}

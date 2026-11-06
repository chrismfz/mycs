<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Admin\Resources\EmailLogResource;
use App\Models\EmailLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * The account's «Emails» in its own tab (owner 2026-11-06, as Ekdosi's): everything mailed to it, sent, failed or not sent
 * on purpose (its email preferences), with why, and Resend. The same list as the one on its Profile.
 */
class ShowEmails extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'emailLogs';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-mail-send-line';

    public static function getNavigationLabel(): string
    {
        return 'Emails';
    }

    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', EmailLog::class) && parent::canAccess($parameters);
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
            ->paginated([25, 50, 100]);
    }
}

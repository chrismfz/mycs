<?php

namespace App\Admin\Resources\StaffResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\StaffResource;
use App\Models\Staff;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A lost phone: the member sets 2FA up again on their next login (their profile).
            Action::make('resetTwoFactor')
                ->label(__('Reset 2FA'))
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Staff $record): bool => filled($record->app_authentication_secret))
                ->authorize(fn (Staff $record): bool => (bool) auth()->user()?->can('update', $record))
                ->action(function (Staff $record): void {
                    $record->saveAppAuthenticationSecret(null);
                    $record->saveAppAuthenticationRecoveryCodes(null);
                    // Whoever holds a «remember me» of theirs must not be the one to set the new 2FA up (review of #344).
                    $record->forceFill(['remember_token' => null])->saveQuietly();
                    Notification::make()->success()->title(__('Two-factor authentication was reset.'))->send();
                }),
            DeleteAction::make(),
            AuditAction::make(),
        ];
    }

    /**
     * A new password or a disabled member: «remember me» no longer logs them in. Open sessions end on their next
     * request (AuthenticateSession for the password, canAccessPanel for a disabled member).
     */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged(['password', 'active'])) {
            $this->record->forceFill(['remember_token' => null])->saveQuietly();
        }
    }
}

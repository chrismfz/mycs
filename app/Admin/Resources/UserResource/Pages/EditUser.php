<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\UserResource;
use App\Models\Client;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Events\AuditCustom;

class EditUser extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A login that owns an account is not deleted (UserObserver too): say why, not a 500.
            DeleteAction::make()
                ->before(function (DeleteAction $action, User $record): void {
                    if ($record->grants()->whereNotNull('owner_of_client_id')->exists()) {
                        Notification::make()->danger()->title(__('This user owns a client account: delete the account first.'))->send();
                        $action->cancel();
                    }
                }),
            // «Login as client» (AUTH-19): the client area as this user sees it. The staff member stays the one who
            // acts (audits, money journal) and it is recorded on the user.
            Action::make('impersonate')
                ->label(__('Login as client'))
                ->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->action(function (User $record) {
                    self::impersonate($record);
                    $this->redirect('/dashboard');
                })
                ->hidden(fn () => !Auth::guard('staff')->user()?->hasPermission('admin.users.impersonate')),
            AuditAction::make(),
        ];
    }

    /**
     * Staff see the client area as this user, on the given account (else the user's default one); recorded on the
     * user, with the staff member as causer.
     */
    public static function impersonate(User $user, ?Client $client = null): void
    {
        session()->put('impersonating', $user->id);
        if ($client !== null) {
            session()->put(CurrentClient::IMPERSONATING_KEY, $client->id);
        } else {
            session()->forget(CurrentClient::IMPERSONATING_KEY);
        }
        $user->auditEvent = 'impersonated';
        $user->isCustomEvent = true;
        $user->auditCustomOld = [];
        $user->auditCustomNew = ['staff_id' => Auth::guard('staff')->id()];
        event(new AuditCustom($user));
    }

    /** A new password set by staff ends the customer's sessions everywhere (AUTH-18), as their own reset does. */
    protected function afterSave(): void
    {
        $user = $this->getRecord();
        if ($user instanceof User && $user->wasChanged('password')) {
            $user->sessions()->delete();
        }
    }
}

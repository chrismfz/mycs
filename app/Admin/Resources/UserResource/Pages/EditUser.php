<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Events\AuditCustom;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // A customer with money history is never deleted (docs/money-trail.md §8): say why, not a 500. One with
                // services: UserObserver says so.
                ->before(function (DeleteAction $action, User $record): void {
                    if ($record->hasMoneyHistory()) {
                        Notification::make()->danger()
                            ->title(__('A customer with payments or account credit history cannot be deleted.'))->send();
                        $action->cancel();
                    }
                }),
            // «Login as client» (AUTH-19): the client area as the customer sees it. The staff member stays the one who
            // acts (audits, money journal) and it is recorded on the customer.
            Action::make('impersonate')
                ->label(__('Login as client'))
                ->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->action(function (User $record) {
                    session()->put('impersonating', $record->id);
                    $record->auditEvent = 'impersonated';
                    $record->isCustomEvent = true;
                    $record->auditCustomOld = [];
                    $record->auditCustomNew = ['staff_id' => Auth::guard('staff')->id()];
                    event(new AuditCustom($record));

                    $this->redirect('/dashboard');
                })
                ->hidden(fn () => !Auth::guard('staff')->user()?->hasPermission('admin.users.impersonate')),
            AuditAction::make()->auditChildren([
                'invoices',
                'services',
                'tickets',
                'credits',
                'orders',
                'billingAgreements',
                'properties',
            ]),
        ];
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

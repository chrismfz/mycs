<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\UserResource;
use App\Classes\PermissionGuard;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn ($record) => Auth::user()->id == $record->id)
                // A customer with money history is never deleted (docs/money-trail.md §8): say why, not a 500.
                ->before(function (DeleteAction $action, User $record): void {
                    if ($record->hasMoneyHistory()) {
                        Notification::make()->danger()
                            ->title(__('A customer with payments or account credit history cannot be deleted.'))->send();
                        $action->cancel();
                    }
                }),
            Action::make('impersonate')
                ->label('Impersonate')
                ->action(function ($record) {
                    session()->put('impersonating', $record->id);

                    $this->redirect('/dashboard');
                })
                ->hidden(fn ($record) => !Auth::user()->hasPermission('admin.users.impersonate') || Auth::user()->id == $record->id || !PermissionGuard::outranks(Auth::user(), $record)),
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
}

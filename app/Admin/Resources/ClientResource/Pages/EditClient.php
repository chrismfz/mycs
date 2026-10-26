<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Concerns\RemembersRecent;
use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Models\Client;
use App\Support\StaffLink;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class EditClient extends EditRecord
{
    use NamesTheClient;
    use RemembersRecent;
    use TwoColumnForm;

    protected static string $resource = ClientResource::class;

    /** The «Details» section edits the primary contact: an account without one (legacy rows) gets it first. */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $client = $this->getRecord();
        if ($client instanceof Client) {
            $client->ensurePrimaryContact();
        }
    }

    /** «Login as client» (AUTH-19): this account in the client area, as its owner sees it (WHMCS logs in as the owner). */
    public static function loginAsClientAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('Login as client'))
            ->icon('heroicon-o-arrow-right-end-on-rectangle')
            ->disabled(fn (Client $record) => $record->owner() === null)
            // A link in a new tab (owner 2026-10-26): the admin page stays; its signed route starts it (LoginAsClientController).
            ->url(fn (Client $record) => $record->owner() === null ? null : StaffLink::to('filament.admin.login-as.client', ['client' => $record->id]))
            ->openUrlInNewTab()->extraAttributes(['rel' => 'noopener noreferrer'])
            ->hidden(fn () => !Auth::guard('staff')->user()?->hasPermission('admin.users.impersonate'));
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // An account with money history is never deleted (docs/money-trail.md §8): say why, not a 500. One
                // with services: ClientObserver says so.
                ->before(function (DeleteAction $action, Client $record): void {
                    if ($record->hasMoneyHistory()) {
                        Notification::make()->danger()
                            ->title(__('A customer with payments or account credit history cannot be deleted.'))->send();
                        $action->cancel();
                    }
                }),
            self::loginAsClientAction(),
            AuditAction::make()->auditChildren([
                'invoices',
                'services',
                'domains',
                'tickets',
                'credits',
                'orders',
                'billingAgreements',
                'notes',
                'properties',
            ]),
        ];
    }
}

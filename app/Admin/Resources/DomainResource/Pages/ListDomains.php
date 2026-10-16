<?php

namespace App\Admin\Resources\DomainResource\Pages;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\DomainResource;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainAdopter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A domain already at a registrar, not yet here (docs/domains.md §7): reads the registrar, changes nothing there.
            Action::make('addExisting')->label(__('Add existing domain'))->icon('heroicon-o-plus')
                ->visible(fn () => (bool) Auth::guard('staff')->user()?->hasPermission('admin.domains.create'))
                ->modalDescription(__('Read from the registrar, never changed or charged there: its expiry, status and nameservers come from it.'))
                ->schema([
                    TextInput::make('domain')->label(__('domains.domain'))->required()->maxLength(255),
                    ClientComponent::make('client_id'),
                    Select::make('connection_id')->label(__('Registrar'))->required()
                        ->options(fn () => DomainRegistrarConnection::query()->orderBy('label')->get()
                            ->filter(fn (DomainRegistrarConnection $c) => $c->isUsable())->pluck('label', 'id')),
                    Select::make('currency_code')->label(__('Currency'))->placeholder(__('The account\'s'))
                        ->options(fn () => Currency::query()->orderBy('code')->pluck('code', 'code')),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $domain = app(DomainAdopter::class)->adopt((string) $data['domain'], Client::query()->findOrFail((int) $data['client_id']),
                            DomainRegistrarConnection::query()->findOrFail((int) $data['connection_id']), $data['currency_code'] ?: null);
                    } catch (DisplayException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title(__(':domain added.', ['domain' => $domain->fqdn]))->send();
                    $this->redirect(DomainResource::getUrl('view', ['record' => $domain]));
                }),
        ];
    }
}

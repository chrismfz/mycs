<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Models\Client;
use App\Services\Admin\ClientSummary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * The client's «Summary» (WHMCS's client profile): the first page of a client, its details, invoices, services,
 * credit, last login and mails on one screen, with the usual actions. Read only: each panel links to where it is changed.
 */
class ClientSummaryPage extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    public static function getNavigationLabel(): string
    {
        return __('Summary');
    }

    public function getTitle(): string
    {
        $client = $this->getRecord();

        return $client instanceof Client ? '#' . $client->id . ' ' . $client->name : __('Summary');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('admin.resources.client-resource.pages.client-summary')
                ->viewData(fn () => $this->viewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        /** @var Client $client */
        $client = $this->getRecord();
        $staff = auth()->user();
        $can = fn (string $permission) => (bool) $staff?->hasPermission($permission);

        return [
            'client' => $client,
            'summary' => ClientSummary::for($client),
            'links' => [
                'services' => $can('admin.services.viewAny') ? ClientResource::getUrl('services', ['record' => $client]) : null,
                'service' => $can('admin.services.update') ? fn (int $id) => ServiceResource::getUrl('edit', ['record' => $id]) : null,
                'invoices' => $can('admin.invoices.viewAny') ? ClientResource::getUrl('invoices', ['record' => $client]) : null,
                'credits' => $can('admin.credits.viewAny') ? ClientResource::getUrl('credits', ['record' => $client]) : null,
                'tickets' => $can('admin.tickets.viewAny') ? ClientResource::getUrl('tickets', ['record' => $client]) : null,
                'email' => $can('admin.email_logs.view') ? fn (int $id) => EmailLogResource::getUrl('view', ['record' => $id]) : null,
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        /** @var Client $client */
        $client = $this->getRecord();
        $new = fn (string $name, string $label, string $icon, bool $allowed, string $url) => Action::make($name)->label($label)->icon($icon)
            ->url($url)->visible($allowed);

        return [
            ActionGroup::make([
                $new('newInvoice', __('Create Invoice'), 'heroicon-o-document-plus', InvoiceResource::canCreate(), InvoiceResource::getUrl('create', ['client_id' => $client->id])),
                $new('newOrder', __('Add New Order'), 'heroicon-o-shopping-cart', OrderResource::canCreate(), OrderResource::getUrl('create', ['client_id' => $client->id])),
                $new('newTicket', __('Open New Ticket'), 'heroicon-o-lifebuoy', TicketResource::canCreate(), TicketResource::getUrl('create', ['client_id' => $client->id])),
                $new('credit', __('Manage Credits'), 'heroicon-o-banknotes', (bool) auth()->user()?->hasPermission('admin.credits.viewAny'), ClientResource::getUrl('credits', ['record' => $client])),
            ])->label(__('Actions'))->icon('heroicon-m-chevron-down')->button(),
            Action::make('edit')->label(__('Edit'))->icon('heroicon-o-pencil-square')->color('gray')
                ->url(ClientResource::getUrl('edit', ['record' => $client]))->visible(ClientResource::canEdit($client)),
            EditClient::loginAsClientAction(),
        ];
    }
}

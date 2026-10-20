<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Actions\InvoiceSelectedItemsAction;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\EmailLogResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\ServiceResource;
use App\Admin\Resources\TicketResource;
use App\Models\Client;
use App\Models\Staff;
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

    /** @var array<string, mixed>|null built once per request */
    private ?array $summary = null;

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
                ->viewData(fn () => $this->viewData())
                ->columnSpanFull(),
        ]);
    }

    /**
     * None here: the client's users, contacts, invites and properties are edited on its Edit page, which needs
     * `admin.clients.update`; the Summary needs only `admin.clients.viewAny` (review B1).
     */
    public function getRelationManagers(): array
    {
        return [];
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
            'summary' => $this->summary ??= ClientSummary::for($client, $staff instanceof Staff ? $staff : null),
            // The sticky notes on top (WHMCS's client «Notes»); the page is staff's, as are the notes.
            'stickyNotes' => $client->notes()->where('sticky', true)->with('staff')->latest()->limit(10)->get(),
            'notesUrl' => ClientResource::getUrl('notes', ['record' => $client]),
            'links' => [
                'services' => $can('admin.services.viewAny') ? ClientResource::getUrl('services', ['record' => $client]) : null,
                'service' => $can('admin.services.update') ? fn (int $id) => ServiceResource::getUrl('edit', ['record' => $id]) : null,
                'domains' => $can('admin.domains.viewAny') ? ClientResource::getUrl('domains', ['record' => $client]) : null,
                'domain' => $can('admin.domains.viewAny') ? fn (int $id) => DomainResource::getUrl('view', ['record' => $id]) : null,
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
                InvoiceSelectedItemsAction::forClient(),
                $new('credit', __('Manage Credits'), 'heroicon-o-banknotes', (bool) auth()->user()?->hasPermission('admin.credits.viewAny'), ClientResource::getUrl('credits', ['record' => $client])),
            ])->label(__('Actions'))->icon('heroicon-m-chevron-down')->button(),
            Action::make('edit')->label(__('Edit'))->icon('heroicon-o-pencil-square')->color('gray')
                ->url(ClientResource::getUrl('edit', ['record' => $client]))->visible(ClientResource::canEdit($client)),
            // As before the Summary: only staff who may also edit the client (review F2), besides impersonate.
            EditClient::loginAsClientAction()->visible(fn (Client $record) => ClientResource::canEdit($record)),
        ];
    }
}

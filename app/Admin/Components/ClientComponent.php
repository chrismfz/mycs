<?php

namespace App\Admin\Components;

use App\Admin\Resources\ClientResource;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The customer account of an order, service, invoice or ticket (docs/accounts.md, PR 2a), found by its id or by the
 * name or email of a user who sees it (WHMCS «Client»).
 */
class ClientComponent extends Select
{
    public static function make(?string $name = null): static
    {
        return parent::make($name)
            ->label(__('Client'))
            ->relationship('client', 'id')
            ->searchable()
            ->getOptionLabelFromRecordUsing(fn (Client $record) => self::optionLabel($record))
            ->getSearchResultsUsing(fn (string $search): array => Client::query()
                ->where(fn (Builder $query) => $query->whereKey((int) $search)
                    ->orWhereHas('users', fn (Builder $users) => $users->where('first_name', 'like', "%$search%")
                        ->orWhere('last_name', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")))
                ->with('ownerGrant.user')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Client $client) => [$client->id => self::optionLabel($client)])
                ->toArray())
            ->hint(fn ($get) => $get($name ?? 'client_id') ? new HtmlString('<a href="' . ClientResource::getUrl('edit', ['record' => $get($name ?? 'client_id')]) . '" target="_blank">' . e(__('Go to Client')) . '</a>') : null)
            ->live()
            ->required();
    }

    public static function optionLabel(Client $client): string
    {
        return '#' . $client->id . ' ' . $client->name . ($client->email ? ' (' . $client->email . ')' : '');
    }
}

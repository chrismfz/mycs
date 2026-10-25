<?php

namespace App\Admin\Resources\TicketResource;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\TicketResource;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Models\Staff;
use App\Models\Ticket;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The search panel above staff's ticket list, after WHMCS's (owner 2026-10-25, docs/tickets.md §6μ). Each narrows what
 * the open tab shows: the tab and the filters both hold (the «Closed» tab with status «Open» shows nothing).
 */
final class TicketFilters
{
    /** @return list<BaseFilter> */
    public static function make(): array
    {
        return [
            // Searched, never every account loaded up front (one query per account before).
            SelectFilter::make('client')
                ->label(__('Client'))
                ->attribute('client_id')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => ClientComponent::searchResults($search))
                ->getOptionLabelUsing(fn ($value) => ClientComponent::labelOf($value))
                ->modifyFormFieldUsing(fn ($field) => $field->inlineLabel()->placeholder(__('Type to search clients…'))),
            Filter::make('text')
                ->schema([TextInput::make('text')->label(__('Subject / message'))->inlineLabel()->placeholder(__('Words in the subject or any message'))])
                ->query(fn (Builder $query, array $data) => $query->when(filled($data['text'] ?? null), function (Builder $query) use ($data) {
                    $text = (string) $data['text'];
                    // Internal notes too: staff search their own notes.
                    $query->where(fn (Builder $query) => TicketResource::whereContains($query, 'subject', $text)
                        ->orWhereHas('messages', fn (Builder $messages) => TicketResource::whereContains($messages, 'message', $text)));
                }))
                ->indicateUsing(fn (array $data) => filled($data['text'] ?? null) ? __('Text') . ': ' . $data['text'] : null),
            SelectFilter::make('department_id')
                ->label(__('Department'))
                ->multiple()
                ->relationship('department', 'name')
                ->modifyFormFieldUsing(fn ($field) => $field->inlineLabel()),
            Filter::make('email')
                ->schema([TextInput::make('email')->label(__('Email address'))->inlineLabel()->placeholder(__('Requestor or any sender'))])
                ->query(fn (Builder $query, array $data) => $query->when(filled($data['email'] ?? null), function (Builder $query) use ($data) {
                    $email = (string) $data['email'];
                    // The guest's, a login of the account, or whoever wrote in it by mail (a CC's reply).
                    $query->where(fn (Builder $query) => TicketResource::whereContains($query, 'guest_email', $email)
                        ->orWhereHas('client.users', fn (Builder $users) => TicketResource::whereContains($users, 'email', $email))
                        ->orWhereHas('messages', fn (Builder $messages) => TicketResource::whereContains($messages, 'sender_email', $email)));
                }))
                ->indicateUsing(fn (array $data) => filled($data['email'] ?? null) ? __('Email') . ': ' . $data['email'] : null),
            SelectFilter::make('status')
                ->label(__('Status'))
                ->multiple()
                ->options(Ticket::statusOptions())
                ->modifyFormFieldUsing(fn ($field) => $field->inlineLabel()),
            Filter::make('number')
                ->schema([TextInput::make('number')->label(__('Ticket # / ID'))->inlineLabel()->placeholder('ABC-123')])
                ->query(fn (Builder $query, array $data) => $query->when(filled($data['number'] ?? null), function (Builder $query) use ($data) {
                    $number = ltrim(trim((string) $data['number']), '#');
                    $query->where(fn (Builder $query) => TicketResource::whereContains($query, 'reference', $number)
                        ->when(ctype_digit($number), fn (Builder $query) => $query->orWhere('id', (int) $number)));
                }))
                ->indicateUsing(fn (array $data) => filled($data['number'] ?? null) ? '#' . ltrim(trim((string) $data['number']), '#') : null),
            SelectFilter::make('priority')
                ->label(__('Priority'))
                ->multiple()
                ->options(TicketResource::priorityOptions())
                ->modifyFormFieldUsing(fn ($field) => $field->inlineLabel()),
            // WHMCS's «Flagged» is «Me» here. Staff, not customers (AUTH-14).
            SelectFilter::make('assigned')
                ->label(__('Assigned to'))
                ->options(fn () => ['me' => __('Me'), 'none' => __('Unassigned')]
                    + Staff::query()->where('active', true)->get()->mapWithKeys(fn (Staff $staff) => [(string) $staff->id => $staff->name])->all())
                ->query(fn (Builder $query, array $data) => match ($value = $data['value'] ?? null) {
                    null, '' => $query,
                    'me' => $query->where('assigned_to', Auth::guard('staff')->id() ?? 0),
                    'none' => $query->whereNull('assigned_to'),
                    default => $query->where('assigned_to', (int) $value),
                })
                ->modifyFormFieldUsing(fn ($field) => $field->inlineLabel()),
            // A merged ticket is closed and lives on in its survivor (§6κ): hidden unless asked for, or looked for by its
            // number (in the number filter or the search box): else it would look as if it did not exist (review).
            Filter::make('show')
                ->schema([
                    Checkbox::make('pinned')->label(__('Pinned only')),
                    Checkbox::make('guests')->label(__('Guests only')),
                    Checkbox::make('merged')->label(__('Merged too')),
                ])
                ->columns(3)
                ->query(fn (Builder $query, array $data, HasTable $livewire) => $query
                    ->when($data['pinned'] ?? false, fn (Builder $query) => $query->whereNotNull('pinned_at'))
                    ->when($data['guests'] ?? false, fn (Builder $query) => $query->whereNull('client_id'))
                    ->when(!($data['merged'] ?? false) && !self::looksForANumber($livewire), fn (Builder $query) => $query->whereNull('merged_into_id')))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    ($data['pinned'] ?? false) ? __('Pinned only') : null,
                    ($data['guests'] ?? false) ? __('Guests only') : null,
                    ($data['merged'] ?? false) ? __('Merged too') : null,
                ]))),
        ];
    }

    /** A number in the number filter, or a whole ticket number (or id) in the search box. */
    private static function looksForANumber(HasTable $livewire): bool
    {
        if (!$livewire instanceof ListTickets) {
            return false;
        }
        $number = trim((string) ($livewire->getTableFilterState('number')['number'] ?? ''));
        $search = ltrim(trim((string) $livewire->getTableSearch()), '#');

        return $number !== '' || ($search !== '' && Ticket::query()->where(fn (Builder $query) => $query->where('reference', $search)
            ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('id', (int) $search)))->exists());
    }
}

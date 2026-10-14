<?php

namespace App\Admin\Resources\ClientResource\RelationManagers;

use App\Admin\Schemas\ContactForm;
use App\Exceptions\DisplayException;
use App\Models\Contact;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The account's contacts (docs/accounts.md, PR 3a; WHMCS «Contacts»): the primary one is the account's details; the
 * others receive mails, are invoice recipients (Ekdosi) and, with the domains, registrant/admin/tech/billing contacts.
 * A contact is archived, not deleted.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contacts';

    public function isReadOnly(): bool
    {
        return false;
    }

    private static function canManage(): bool
    {
        return (bool) Auth::guard('staff')->user()?->hasPermission('admin.clients.update');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ContactForm::fields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('primary_of_client_id')->orderBy('id'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->state(fn (Contact $record) => $record->displayName()),
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('tax_id')->label(__('Tax ID'))->placeholder('—'),
                TextColumn::make('country')->label(__('Country'))->placeholder('—'),
                IconColumn::make('primary')->label(__('Primary'))->boolean()->state(fn (Contact $record) => $record->isPrimary()),
                TextColumn::make('document_type')->label(__('Document'))->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? __('account.document_type.' . $state) : null),
                TextColumn::make('archived_at')->label(__('Archived'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('archived')->label(__('Archived'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query->whereNull('archived_at'),
                    )->default(false),
            ])
            ->headerActions([
                CreateAction::make()->visible(fn () => self::canManage()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn () => self::canManage()),
                Action::make('primary')->label(__('Make primary'))->icon('heroicon-o-star')->requiresConfirmation()
                    ->modalDescription(__('The account\'s details become this contact\'s; the current primary stays as a contact.'))
                    ->visible(fn (Contact $record) => self::canManage() && !$record->isPrimary())
                    ->action(fn (Contact $record) => $record->makePrimary()),
                Action::make('archive')->label(__('Archive'))->icon('heroicon-o-archive-box')->color('danger')->requiresConfirmation()
                    ->visible(fn (Contact $record) => self::canManage() && !$record->isPrimary() && $record->archived_at === null)
                    ->action(function (Contact $record) {
                        try {
                            $record->archive();
                        } catch (DisplayException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('restore')->label(__('Restore'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Contact $record) => self::canManage() && $record->archived_at !== null)
                    ->action(fn (Contact $record) => $record->forceFill(['archived_at' => null])->save()),
            ]);
    }
}

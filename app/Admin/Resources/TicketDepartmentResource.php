<?php

namespace App\Admin\Resources;

use App\Admin\Resources\TicketDepartmentResource\Pages\CreateTicketDepartment;
use App\Admin\Resources\TicketDepartmentResource\Pages\EditTicketDepartment;
use App\Admin\Resources\TicketDepartmentResource\Pages\ListTicketDepartments;
use App\Models\TicketDepartment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Ticket departments (docs/tickets.md §5): WHMCS «Support», «Sales»…, each with its own address; its mailbox comes in
 * PR A2. A department with tickets is hidden, not deleted.
 */
class TicketDepartmentResource extends Resource
{
    protected static ?string $model = TicketDepartment::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-customer-service-2-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-customer-service-2-fill';

    protected static ?string $navigationLabel = 'Ticket Departments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Name')->required()->maxLength(255),
                TextInput::make('email')->label('Email')->email()->maxLength(255)
                    ->helperText('The department\'s address (e.g. support@…): its mails come from it, and its mailbox from PR A2.'),
                TextInput::make('sort')->label('Order')->integer()->minValue(0)->maxValue(65535)->default(0),
                Toggle::make('hidden')->label('Hidden')->helperText('Not offered to customers; staff still use it.'),
                Toggle::make('clients_only')->label('Clients only')
                    ->helperText('No tickets from guests (the contact form, mail from unknown senders).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')->searchable(),
                TextColumn::make('email')->label('Email')->placeholder('—'),
                TextColumn::make('sort')->label('Order')->sortable(),
                IconColumn::make('hidden')->label('Hidden')->boolean(),
                IconColumn::make('clients_only')->label('Clients only')->boolean(),
                TextColumn::make('tickets_count')->label('Tickets')->counts('tickets'),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketDepartments::route('/'),
            'create' => CreateTicketDepartment::route('/create'),
            'edit' => EditTicketDepartment::route('/{record}/edit'),
        ];
    }
}

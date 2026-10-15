<?php

namespace App\Admin\Schemas;

use App\Models\Contact;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/**
 * A contact's fields (docs/accounts.md, PR 3a), the same for the account's details (its primary contact) and its other
 * contacts. Nothing is required here beyond a name: what a role needs (invoice, domain, email) is checked where the
 * contact is used (Contact::missingFor).
 */
class ContactForm
{
    /** @return array<int, mixed> */
    public static function fields(): array
    {
        return [
            Select::make('type')->label(__('Type'))->options([
                'individual' => __('account.contact_type.individual'),
                'business' => __('account.contact_type.business'),
            ])->default('individual')->required(),
            TextInput::make('first_name')->label(__('First name'))->maxLength(255),
            TextInput::make('last_name')->label(__('Last name'))->maxLength(255),
            TextInput::make('company_name')->label(__('Company name'))->maxLength(255),
            TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(50),
            Fieldset::make(__('Tax details'))->schema([
                TextInput::make('tax_id')->label(__('Tax ID'))->maxLength(50),
                TextInput::make('vat_number')->label(__('EU VAT number'))->maxLength(50),
                TextInput::make('tax_office')->label(__('Tax office'))->maxLength(255),
                TextInput::make('activity')->label(__('Activity'))->maxLength(255),
                Select::make('document_type')->label(__('Document'))->options([
                    'invoice' => __('account.document_type.invoice'),
                    'receipt' => __('account.document_type.receipt'),
                    'none' => __('account.document_type.none'),
                ]),
            ])->columns(2),
            Fieldset::make(__('Address'))->schema([
                TextInput::make('address1')->label(__('Address'))->maxLength(255),
                TextInput::make('address2')->label(__('Address 2'))->maxLength(255),
                TextInput::make('city')->label(__('City'))->maxLength(255),
                TextInput::make('state')->label(__('State'))->maxLength(255),
                TextInput::make('postcode')->label(__('Postcode'))->maxLength(20),
                Select::make('country')->label(__('Country'))->searchable()
                    ->options(array_slice((array) config('app.countries'), 1, null, true)),
            ])->columns(2),
            CheckboxList::make('email_preferences')->label(__('Receives emails'))->columns(3)->columnSpanFull()
                ->options(collect(Contact::EMAIL_PREFERENCES)->mapWithKeys(fn ($kind) => [$kind => __('account.email_preference.' . $kind)])->all()),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ];
    }
}

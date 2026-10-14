<?php

namespace App\Admin\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The staff member's own profile (docs/accounts.md, PR 1): first and last name instead of Filament's single `name`,
 * email, password; the 2FA section comes from the panel's multi-factor authentication.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')->label(__('First name'))->required()->maxLength(255),
                TextInput::make('last_name')->label(__('Last name'))->required()->maxLength(255),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }
}

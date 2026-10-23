<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ClientResource;
use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use App\Support\Locales;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** A new account with its owner, as WHMCS «Add New Client» (docs/accounts.md, PR 2a). */
class CreateClient extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $owner = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $staff = Auth::guard('staff')->user();
            $client = Client::openFor($owner, grantedBy: $staff instanceof Staff ? $staff : null);
            $client->forceFill(['credit_auto_apply' => (bool) ($data['credit_auto_apply'] ?? true), 'tax_exempt' => (bool) ($data['tax_exempt'] ?? false),
                'separate_invoices' => (bool) ($data['separate_invoices'] ?? false), 'auto_charge' => (bool) ($data['auto_charge'] ?? true),
                'language' => Locales::allowed($data['language'] ?? null)])->save();
            // The account's details (PR 3a): what staff typed over what the owner gave.
            $contact = array_filter((array) ($data['contact'] ?? []), fn ($value) => $value !== null && $value !== '' && $value !== []);
            $client->primaryContact?->update($contact);

            return $client;
        });
    }
}

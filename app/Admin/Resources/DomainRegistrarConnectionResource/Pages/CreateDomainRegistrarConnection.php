<?php

namespace App\Admin\Resources\DomainRegistrarConnectionResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Models\DomainRegistrarConnection;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** The connection and its credentials together: the secret ones encrypted (D-21). */
class CreateDomainRegistrarConnection extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = DomainRegistrarConnectionResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $credentials = (array) ($data['credentials'] ?? []);
        unset($data['credentials']);

        return DB::transaction(function () use ($data, $credentials) {
            $connection = DomainRegistrarConnection::query()->create($data);
            $connection->storeCredentials(...EditDomainRegistrarConnection::split($connection->registrar, $credentials));

            return $connection;
        });
    }
}

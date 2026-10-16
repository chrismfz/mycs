<?php

namespace App\Admin\Resources\DomainRegistrarConnectionResource\Pages;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Models\DomainRegistrarConnection;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Edit a connection; its credentials: a non-secret one is shown and saved, a secret one never shown and kept when left
 * empty (D-21). The registrar is fixed once created.
 */
class EditDomainRegistrarConnection extends EditRecord
{
    protected static string $resource = DomainRegistrarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [DomainRegistrarConnectionResource::testAction(), DomainRegistrarConnectionResource::deleteAction()];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var DomainRegistrarConnection $connection */
        $connection = $this->getRecord();
        $stored = $connection->credentials();
        foreach (DomainRegistrarConnectionResource::credentialFields($connection->registrar) as $key => $field) {
            $data['credentials'][$key] = ($field['secret'] ?? false) ? null : ($stored[$key] ?? null);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $credentials = (array) ($data['credentials'] ?? []);
        unset($data['credentials'], $data['registrar']);

        return DB::transaction(function () use ($record, $data, $credentials) {
            /** @var DomainRegistrarConnection $record */
            $record->update($data);
            $record->storeCredentials(...self::split($record->registrar, $credentials));

            return $record;
        });
    }

    /**
     * Only the registrar's own fields, and which of them are secret.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function split(string $registrar, array $values): array
    {
        $fields = DomainRegistrarConnectionResource::credentialFields($registrar);

        return [
            array_intersect_key($values, $fields),
            array_keys(array_filter($fields, fn (array $field) => (bool) ($field['secret'] ?? false))),
        ];
    }
}

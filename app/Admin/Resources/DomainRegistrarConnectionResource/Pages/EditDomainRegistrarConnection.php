<?php

namespace App\Admin\Resources\DomainRegistrarConnectionResource\Pages;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use App\Models\DomainRegistrarConnection;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        self::secretsAgainWhenMoved($record, $data, $credentials);

        return DB::transaction(function () use ($record, $data, $credentials) {
            /** @var DomainRegistrarConnection $record */
            $record->update($data);
            $record->storeCredentials(...self::split($record->registrar, $credentials));

            return $record;
        });
    }

    /**
     * The connection moved (its mode, or an address among its credentials): its stored secrets are typed again, never
     * sent on to the other system by themselves (the R6 rule; security audit SA-6: a live password to the test system).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $credentials
     */
    private static function secretsAgainWhenMoved(Model $record, array $data, array $credentials): void
    {
        /** @var DomainRegistrarConnection $record */
        $fields = DomainRegistrarConnectionResource::credentialFields($record->registrar);
        $stored = $record->credentials();
        $moved = (isset($data['mode']) && $data['mode'] !== $record->mode)
            || collect($fields)->keys()->contains(fn ($key) => !($fields[$key]['secret'] ?? false)
                && preg_match('/host|url|endpoint|server|port/i', (string) $key) === 1
                && (string) ($stored[$key] ?? '') !== (string) ($credentials[$key] ?? ''));
        if (!$moved) {
            return;
        }
        $missing = [];
        foreach ($fields as $key => $field) {
            if (($field['secret'] ?? false) && (string) ($credentials[$key] ?? '') === '' && (string) ($stored[$key] ?? '') !== '') {
                $missing['data.credentials.' . $key] = __('The address changed: enter this secret again.');
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /** A secret just typed does not stay in the page (review F7). */
    protected function afterSave(): void
    {
        /** @var DomainRegistrarConnection $connection */
        $connection = $this->getRecord();
        foreach (DomainRegistrarConnectionResource::credentialFields($connection->registrar) as $key => $field) {
            if ($field['secret'] ?? false) {
                $this->data['credentials'][$key] = null;
            }
        }
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

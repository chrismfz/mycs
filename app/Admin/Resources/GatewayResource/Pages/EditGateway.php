<?php

namespace App\Admin\Resources\GatewayResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\GatewayResource;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Domain;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Service;
use App\Services\Money\GatewayBalances;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditGateway extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = GatewayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Its items become the account's (ADV-541-5): a card on the account then charges what was paid by this way,
            // e.g. an imported bank transfer one never set up (ADV-679-2). Staff are told before, as WHMCS asks where to move them.
            DeleteAction::make()->before(fn ($record) => ExtensionHelper::call($record, 'disabled', [$record], mayFail: true))
                ->modalDescription(function (Gateway $record): string {
                    $items = Service::query()->where('payment_gateway_id', $record->id)->whereIn('status', Service::PAYING_STATUSES)->count()
                        + Domain::query()->where('payment_gateway_id', $record->id)->whereNotIn('status', DomainStatus::terminalValues())->count();

                    return $items === 0 ? 'No live service or domain pays by it.'
                        : "{$items} live service(s) and domain(s) pay by it. Deleted, they become their accounts' again: a saved method on the account charges their renewals. To keep them uncharged, set them to another way, or to «Do not charge automatically», first.";
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Its stored secrets stay out of the page (sweep §6 #4): left empty, they are kept on save.
        $record = $this->getRecord();
        $data['settings'] = $record instanceof Extension ? ExtensionHelper::settingsForForm($record) : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $wasEnabled = (bool) $record->getAttribute('enabled');
        $record->update(Arr::except($data, ['settings']));
        // Turned off, the extension is told as on delete; turned on, after its settings below, as on create.
        $toggled = array_key_exists('enabled', $data) && (bool) $data['enabled'] !== $wasEnabled;
        if ($toggled && !$data['enabled']) {
            ExtensionHelper::call($record, 'disabled', [$record], mayFail: true);
        }

        if (isset($data['settings'])) {
            ExtensionHelper::saveSettings($record, $record instanceof Extension ? ExtensionHelper::withStoredSecrets($record, $data['settings']) : $data['settings']);

            ExtensionHelper::call($record, 'updated', [$record], mayFail: true);
        }
        if ($toggled && $data['enabled']) {
            ExtensionHelper::call($record, 'enabled', [$record], mayFail: true);
        }
        if ($record instanceof Gateway) {
            GatewayBalances::readSoon($record); // Bank totals: new keys, or turned on or off, seen now
        }

        // Maybe the extension changed the record, so we need to refresh it
        return $record->refresh();
    }

    /** A secret typed in leaves the page once saved (review R10), as the Settings page does (AUTH-12). */
    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof Extension) {
            foreach (ExtensionHelper::storedSecretKeys($record) as $key) {
                $this->data['settings'][$key] = null;
            }
        }
    }
}

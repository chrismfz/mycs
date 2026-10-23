<?php

namespace App\Admin\Resources\RoleResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditRole extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = RoleResource::class;

    /** Permissions the role holds that the form has no box for (`*`, a disabled extension's): kept as they are. */
    #[Locked]
    public array $unlistedPermissions = [];

    /** Permissions of what is gone from MyCS: dropped from a role when it is saved (review of #358). */
    private const REMOVED = ['admin.oauth_clients.'];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $held = array_values(array_filter((array) ($data['permissions'] ?? []), 'is_string'));
        $listed = array_keys(RoleResource::permissionOptions());
        // A value with no box would fail the form's own check, so the role could never be saved again.
        $this->unlistedPermissions = array_values(array_filter($held, fn (string $permission) => !in_array($permission, $listed, true)
            && !array_filter(self::REMOVED, fn (string $prefix) => str_starts_with($permission, $prefix))));
        $data['permissions'] = array_values(array_intersect($held, $listed));

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['permissions'] = array_values(array_unique([...(array) ($data['permissions'] ?? []), ...$this->unlistedPermissions]));

        return $data;
    }
}

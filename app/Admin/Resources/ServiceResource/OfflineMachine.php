<?php

namespace App\Admin\Resources\ServiceResource;

use App\Classes\Extension\OfflineServer;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The «Machine» of a service on a module with no API (OfflineServer): its fields come from the module, their values are
 * the service's properties (as every module's), edited by staff who may change services.
 */
final class OfflineMachine
{
    /** One MAC address (colons or dashes). */
    public const MAC = '/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/';

    /** @return list<array{name: string, label: string, type: string, help?: string}> */
    public static function fields(?Service $service): array
    {
        $server = $service?->provisioningServer();
        if ($server === null || blank($server->extension)) {
            return [];
        }
        try {
            $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        } catch (Throwable) {
            return [];
        }

        return $extension instanceof OfflineServer ? $extension->serviceFields() : [];
    }

    /** Its section's title: the module's noun («Machine», «Certificate»). */
    public static function title(?Service $service): string
    {
        $server = $service?->provisioningServer();
        try {
            $extension = $server !== null && filled($server->extension) ? ExtensionHelper::getExtension('server', $server->extension, $server->settings) : null;
        } catch (Throwable) {
            $extension = null;
        }

        return ucfirst($extension instanceof OfflineServer ? $extension->noun() : 'machine');
    }

    public static function value(Service $service, string $key): ?string
    {
        $value = $service->properties->firstWhere('key', $key)?->value;

        return filled($value) ? (string) $value : null;
    }

    public static function editAction(): Action
    {
        return Action::make('editMachine')->label(fn (Service $record) => 'Edit ' . strtolower(self::title($record)) . ' details')->icon('heroicon-o-pencil-square')->color('gray')
            ->authorize(fn (Service $record) => (bool) auth()->user()?->can('update', $record))
            ->modalWidth('3xl')
            ->fillForm(fn (Service $record) => collect(self::fields($record))->mapWithKeys(fn (array $field) => [$field['name'] => self::value($record, $field['name'])])->all())
            ->schema(fn (Service $record) => collect(self::fields($record))->map(fn (array $field) => self::input($field))->all())
            ->action(function (Service $record, array $data): void {
                foreach (self::fields($record) as $field) {
                    $value = self::normalise($field['type'], $data[$field['name']] ?? null);
                    $existing = $record->properties()->where('key', $field['name'])->first();
                    if ($value === null) {
                        $existing?->delete();
                    } elseif ($existing === null) {
                        $record->properties()->create(['key' => $field['name'], 'name' => $field['label'], 'value' => $value]);
                    } elseif ($existing->value !== $value) {
                        $existing->update(['value' => $value]);
                    }
                }
                $record->unsetRelation('properties');
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    /** @param array{name: string, label: string, type: string, help?: string} $field */
    private static function input(array $field): TextInput|Textarea
    {
        $input = match ($field['type']) {
            'textarea' => Textarea::make($field['name'])->rows(2),
            'mac' => Textarea::make($field['name'])->rows(2)->placeholder('AA:BB:CC:DD:EE:FF')
                ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    foreach (preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mac) {
                        if (preg_match(self::MAC, $mac) !== 1) {
                            $fail(__('Not a MAC address: :mac', ['mac' => $mac]));
                        }
                    }
                }]),
            'ip' => TextInput::make($field['name'])->ip()->maxLength(45),
            'number' => TextInput::make($field['name'])->numeric()->minValue(0)->maxValue(100),
            default => TextInput::make($field['name'])->maxLength(255),
        };

        return $input->label($field['label'])->helperText($field['help'] ?? null)->nullable();
    }

    /** As stored: MACs upper case with colons, one per line; blanks gone; empty as none. */
    private static function normalise(string $type, mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if ($type === 'mac') {
            return collect(preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                ->map(fn (string $mac) => strtoupper(str_replace('-', ':', $mac)))->unique()->implode("\n");
        }

        return $value;
    }
}

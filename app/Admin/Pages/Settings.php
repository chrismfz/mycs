<?php

namespace App\Admin\Pages;

use App\Classes\FilamentInput;
use App\Classes\Settings as ClassesSettings;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Settings';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-settings-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-settings-3-fill';

    protected string $view = 'admin.pages.settings';

    public ?array $data = [];

    /**
     * The values the form was filled with. Save writes only what the staff changed since: the page may stay open
     * while the app moves a setting on (the invoice and credit note counters on every invoice), and writing the
     * stale value back would rewind it (duplicate numbers, a payment rolled back after the gateway charged).
     */
    #[Locked]
    public array $loaded = [];

    /** Counters the app increments: staff may move them forward, never below the last number used. */
    private const COUNTERS = ['invoice_number', 'credit_note_number'];

    public function mount(): void
    {
        $setting_values = [];
        foreach (ClassesSettings::settings() as $group => $settings) {
            foreach ($settings as $setting) {
                $setting_values[$setting['name']] = config("settings.{$setting['name']}", $setting['default'] ?? null);
            }
        }

        $this->loaded = $setting_values;
        $this->form->fill($setting_values);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach (ClassesSettings::settings() as $key => $categories) {
            $tab = Tab::make($key)
                ->label(ucwords(str_replace('-', ' ', $key)))
                ->schema(function () use ($categories) {
                    $inputs = [];
                    foreach ($categories as $setting) {
                        $inputs[] = FilamentInput::convert($setting);
                    }

                    return $inputs;
                });

            $tabs[] = $tab;
        }

        return $schema
            ->components([
                Form::make([
                    Tabs::make('Tabs')
                        ->tabs($tabs)
                        ->persistTabInQueryString(),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        Gate::authorize('has-permission', 'admin.settings.update');

        $data = $this->form->getState();
        $data = array_filter($data, fn ($value, $key) => !array_key_exists($key, $this->loaded) || !self::same($value, $this->loaded[$key]), ARRAY_FILTER_USE_BOTH);

        DB::transaction(fn () => $this->write($data));
        $this->loaded = array_merge($this->loaded, $data); // a second save from the same page compares with this one

        Notification::make()
            ->title('Saved successfully!')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data  the settings the staff changed
     */
    private function write(array $data): void
    {
        $settings = Setting::where('settingable_type', null)
            ->whereIn('key', array_keys($data))
            ->lockForUpdate()
            ->get()
            ->keyBy('key');

        foreach (array_intersect(self::COUNTERS, array_keys($data)) as $key) {
            $last = (int) ($settings[$key]->value ?? 0);
            if ((int) $data[$key] < $last) {
                throw ValidationException::withMessages([
                    'data.' . $key => 'The last number used is ' . $last . ': a lower value would reuse numbers.',
                ]);
            }
        }

        foreach ($data as $key => $value) {
            // Get only the settings that have changed
            $avSetting = (object) collect(ClassesSettings::settings())->flatten(1)->firstWhere('name', $key);
            $avSetting->value = $settings[$key]->value ?? $avSetting->default ?? null;

            if ($value !== $avSetting->value || (($avSetting->database_type ?? 'string') === 'boolean' && (bool) $value !== (bool) $avSetting->value)) {
                if ($setting = $settings[$key] ?? null) {
                    $setting->update([
                        'value' => $value,
                        'type' => $avSetting->database_type ?? 'string',
                        'encrypted' => $avSetting->encrypted ?? false,
                    ]);
                } else {
                    Setting::create([
                        'key' => $key,
                        'value' => $value,
                        'settingable_type' => null,
                        'type' => $avSetting->database_type ?? 'string',
                        'encrypted' => $avSetting->encrypted ?? false,
                    ]);
                }
            }
        }
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        return (string) $a === (string) $b;
    }

    public static function canAccess(): bool
    {
        /** @var User */
        $user = auth()->user();

        return $user && $user->hasPermission('admin.settings.view');
    }
}

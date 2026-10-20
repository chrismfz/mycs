<?php

namespace App\Admin\Pages;

use App\Classes\FilamentInput;
use App\Classes\Settings as ClassesSettings;
use App\Listeners\InvoiceNumberListener;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
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
    private const COUNTERS = Setting::COUNTERS;

    private const COUNTER_MAX = 999_999_999_999;

    /** Tabs named otherwise than their group key (the query string keeps the key). */
    private const TAB_LABELS = ['cronjob' => 'Automation', 'theme' => 'Appearance'];

    public function mount(): void
    {
        $setting_values = [];
        foreach (ClassesSettings::settings() as $group => $settings) {
            foreach ($settings as $setting) {
                // A secret never goes to the browser (audit sweep AUTH-12): staff with settings.view could read it in the
                // page state. The field stays empty; left empty on save, the stored value is kept.
                $setting_values[$setting['name']] = self::isEncrypted($setting['name'])
                    ? null
                    : config("settings.{$setting['name']}", $setting['default'] ?? null);
            }
        }

        // The counters as stored now: the settings cache is not flushed when an invoice moves them (EVT-13).
        $counters = Setting::query()->whereNull('settingable_type')->whereIn('key', self::COUNTERS)->pluck('value', 'key');
        foreach ($counters as $key => $value) {
            $setting_values[$key] = $value;
        }

        $this->loaded = $setting_values;
        $this->form->fill($setting_values);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach (ClassesSettings::settings() as $key => $categories) {
            $tab = Tab::make($key)
                ->label(self::TAB_LABELS[$key] ?? ucwords(str_replace('-', ' ', $key)))
                ->key("{$key}::data::tab") // Filament keys a tab by its label: keep the group key (links, bookmarks)
                ->schema(function () use ($categories) {
                    // Settings with a 'section' are grouped under its heading (the Automation tab, as WHMCS).
                    $inputs = [];
                    $sections = [];
                    foreach ($categories as $setting) {
                        $input = FilamentInput::convert($setting);
                        if (!self::mayChange($setting)) {
                            $input->disabled(); // shown, not sent back (and refused in save())
                        }
                        if (self::isEncrypted($setting['name']) && self::hasStoredValue($setting['name'])) {
                            // Empty means "keep the stored one", so it is not required again.
                            $input->required(false)->placeholder(__('Stored: leave empty to keep it'));
                        }
                        if (isset($setting['section'])) {
                            if (!isset($sections[$setting['section']])) {
                                $sections[$setting['section']] = [];
                                $inputs[] = $setting['section']; // its place, filled in below
                            }
                            $sections[$setting['section']][] = $input;
                        } else {
                            $inputs[] = $input;
                        }
                    }

                    return array_map(fn ($input) => is_string($input) ? Section::make($input)->schema($sections[$input]) : $input, $inputs);
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
        foreach (array_keys($data) as $key) {
            $setting = collect(ClassesSettings::settings())->flatten(1)->firstWhere('name', $key);
            if ($setting !== null && !self::mayChange($setting)) {
                abort(403, "Changing {$key} needs the {$setting['permission']} permission.");
            }
        }

        DB::transaction(function () use (&$data) {
            $this->write($data); // normalises the counters in $data
        });
        $this->loaded = array_merge($this->loaded, $data); // a second save from the same page compares with this one
        // A secret typed in leaves the page state once saved (AUTH-12): empty again, the stored one is kept.
        foreach (array_keys($data) as $key) {
            if (self::isEncrypted($key)) {
                $this->loaded[$key] = null;
                $this->data[$key] = null;
            }
        }
        $moved = $this->refreshMoved(array_keys($data));

        Notification::make()
            ->title('Saved successfully!')
            ->body($moved ? 'Changed by someone else while this page was open, kept as they set it: ' . implode(', ', $moved) . '.' : null)
            ->success()
            ->send();
    }

    /**
     * Fields the staff left as loaded that changed in the database meanwhile (another admin, the CLI, the app's
     * counters): the page shows the stored value again, so what is on screen is what is stored. Returns the ones
     * worth telling (not the counters, which the app moves on every invoice).
     *
     * @param  array<int, string>  $saved
     * @return array<int, string>
     */
    private function refreshMoved(array $saved): array
    {
        $stored = Setting::query()->whereNull('settingable_type')->whereIn('key', array_keys($this->loaded))->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value]); // models: decrypted and cast
        $moved = [];
        foreach ($stored as $key => $value) {
            // Never a secret back into the page (AUTH-12): its field stays empty whatever is stored.
            if (self::isEncrypted($key) || in_array($key, $saved, true) || self::same($value, $this->loaded[$key])) {
                continue;
            }
            $this->loaded[$key] = $value;
            $this->data[$key] = $value;
            if (!in_array($key, self::COUNTERS, true)) {
                $moved[] = $key;
            }
        }

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $data  the settings the staff changed
     */
    private function write(array &$data): void
    {
        $settings = Setting::where('settingable_type', null)
            ->whereIn('key', array_keys($data))
            ->lockForUpdate()
            ->get()
            ->keyBy('key');

        // «Number = id» only when no number given so far could be an invoice's id later: a number that is a whole number
        // and not its own invoice's id (a «{number}» counter, an import) would be taken (adversarial ADV-5 of #238).
        if (array_key_exists('invoice_number_is_id', $data) && filter_var($data['invoice_number_is_id'], FILTER_VALIDATE_BOOL)
            && !filter_var($settings['invoice_number_is_id']->value ?? false, FILTER_VALIDATE_BOOL)
            && ($clash = Invoice::query()->whereNotNull('number')->select(['id', 'number'])->lazyById()
                ->first(fn (Invoice $invoice) => ctype_digit((string) $invoice->number) && $invoice->number !== (string) $invoice->id)) !== null) {
            throw ValidationException::withMessages(['data.invoice_number_is_id' => "Invoice number {$clash->number} could be another invoice's id: numbering by id would give it twice."]);
        }

        foreach (array_intersect(self::COUNTERS, array_keys($data)) as $key) {
            // A whole number in range: numeric() gives floats, and (int) of a float past PHP_INT_MAX wraps around.
            $value = filter_var($data[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::COUNTER_MAX]]);
            if ($value === false) {
                throw ValidationException::withMessages(['data.' . $key => 'A whole number from 0 to ' . self::COUNTER_MAX . '.']);
            }
            $data[$key] = $value;

            // Lower than the last number used only when the next number is free: a new {year} restarts the count,
            // going back within the same numbers would reuse them.
            $last = (int) ($settings[$key]->value ?? 0);
            $prefix = substr($key, 0, -strlen('_number'));
            if ($value < $last && $this->numberTaken($prefix, InvoiceNumberListener::formatInvoiceNumber($value + 1, $prefix))) {
                throw ValidationException::withMessages([
                    'data.' . $key => 'The next number (' . InvoiceNumberListener::formatInvoiceNumber($value + 1, $prefix) . ') is already used; the last number used is ' . $last . '.',
                ]);
            }
        }

        foreach ($data as $key => $value) {
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

    private function numberTaken(string $prefix, string $number): bool
    {
        return $prefix === 'invoice'
            ? Invoice::query()->where('number', $number)->exists()
            : AdjustmentNote::query()->where('number', $number)->exists();
    }

    /**
     * Settings with their own permission on top of settings.update (the Appearance tab's custom code and files: they
     * run in the client area, same origin as the admin).
     *
     * @param  array<string, mixed>  $setting
     */
    private static function mayChange(array $setting): bool
    {
        return !isset($setting['permission']) || Gate::allows('has-permission', $setting['permission']);
    }

    /** Settings stored encrypted: never sent to the browser. */
    private static function isEncrypted(string $key): bool
    {
        static $encrypted = null;
        $encrypted ??= collect(ClassesSettings::settings())->flatten(1)->filter(fn ($setting) => $setting['encrypted'] ?? false)
            ->pluck('name')->all();

        return in_array($key, $encrypted, true);
    }

    private static function hasStoredValue(string $key): bool
    {
        return filled(config("settings.$key"));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        // Blank is blank: a tags field with no row loads null and saves [].
        if (in_array($a, [null, '', []], true) && in_array($b, [null, '', []], true)) {
            return true;
        }
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
        /** @var Staff|null */
        $user = auth()->user();

        return $user && $user->hasPermission('admin.settings.view');
    }
}

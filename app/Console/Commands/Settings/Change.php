<?php

namespace App\Console\Commands\Settings;

use App\Classes\Settings;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

use function Laravel\Prompts\form;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Change extends Command
{
    protected $signature = 'app:settings:change {key?} {value?}';

    protected $description = 'Change a setting';

    public function handle()
    {
        Config::set('audit.console', true);

        $key = $this->argument('key');
        $value = $this->argument('value');
        $form = form();

        if (!$key) {
            // Settings::settings is a array with first level keys as categories and second level keys as settings, so we need to flatten it
            $settings = collect(Settings::settings())->flatten(1)->map(function ($item) {
                return $item['name'];
            })->toArray();

            $form->suggest('Which setting would you like to change?', $settings, name: 'key');
        }

        if (!$value) {
            $form->add(function ($responses) use ($key) {
                $key = $responses['key'] ?? $key;
                $setting = Settings::getSetting($key);
                if (!isset($setting->type)) {
                    return text('What value should the setting have?', default: '', hint: 'Could not find setting but you can still change it');
                }
                // What type is the setting?
                if ($setting->type === 'select') {
                    // Options read when shown are a Closure in Settings::settings() (code review of #689).
                    $options = collect(Settings::settings())->flatten(1)->firstWhere('name', $key)['options'] ?? $setting->options;

                    return select('What value should the setting have?', $options instanceof \Closure ? $options() : (array) $options);
                } else {
                    return text('What value should the setting have?', default: $setting->value ?? '');
                }
            });
        }
        $form = $form->submit();

        if (isset($form['key'])) {
            $key = $form['key'];
        }
        if (isset($form[0]) || isset($form[1])) {
            $value = $form[0] ?? $form[1];
        }

        // Type and encryption from the setting's definition, as the admin page does: a row created here (a setting
        // with no default, or one a later release added) must not become a 'string' that keeps 'false' as true, nor
        // a secret stored, cached and audited in clear (sweep SETTINGS-OPS-1). Only the global row: an extension or
        // product setting with the same key is not this one.
        $definition = collect(Settings::settings())->flatten(1)->firstWhere('name', $key);
        $attributes = ['value' => $value];
        if (is_array($definition)) {
            $attributes['type'] = $definition['database_type'] ?? 'string';
            $attributes['encrypted'] = (bool) ($definition['encrypted'] ?? false);
        }

        Setting::updateOrCreate(['key' => $key, 'settingable_type' => null], $attributes);
    }
}

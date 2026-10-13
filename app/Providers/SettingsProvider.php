<?php

namespace App\Providers;

use App\Classes\Settings;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Qirolab\Theme\Theme;

class SettingsProvider extends ServiceProvider
{
    private const CACHE_KEY = 'settings.rows';

    /**
     * Register services.
     */
    public function register(): void
    {
        // Settings::tax() per country: reset for every request and every queued job (scoped), so a rate changed
        // meanwhile is seen.
        $this->app->scoped('settings.tax-rates', fn () => new \ArrayObject);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->getSettings();
    }

    public static function getSettings($force = false): void
    {
        if (config('settings') && !empty(config('settings')) && !$force) {
            return;
        }
        try {
            // The cache keeps the rows as stored (secrets still encrypted, it lands in storage/ and its backups);
            // decrypted only into this process's config.
            $rows = Cache::get(self::CACHE_KEY);
            if (!is_array($rows) || $rows === []) { // empty: the table may have been empty at the first boot
                Cache::forget('settings'); // the old cache held decrypted values
                $rows = Setting::query()->whereNull('settingable_type')->toBase()->get(['key', 'value', 'type', 'encrypted'])
                    ->map(fn ($row) => (array) $row)->all();
                Cache::put(self::CACHE_KEY, $rows);
            }
            $settings = collect($rows)->mapWithKeys(fn (array $row) => [
                $row['key'] => Setting::decodeValue($row['value'], $row['type'], (bool) $row['encrypted']),
            ]);
            // Is the current command a config:cache command?
            if (isset($_SERVER['argv']) && (in_array('config:cache', $_SERVER['argv']) || in_array('optimize', $_SERVER['argv']) || in_array('app:optimize', $_SERVER['argv']))) {
                return;
            }
            config(['settings' => $settings]);
            foreach (Settings::settings() as $settings) {
                foreach ($settings as $setting) {
                    if (isset($setting['override']) && config("settings.$setting[name]") !== null) {
                        config([$setting['override'] => config("settings.$setting[name]")]);
                    }
                }
            }

            include_once app_path('Classes/helpers.php');

            date_default_timezone_set(config('settings.timezone', 'UTC'));

            $themeName = config('settings.theme', 'default');
            Theme::set($themeName, 'default');

            $themeLangPath = base_path("themes/{$themeName}/lang");
            if (is_dir($themeLangPath)) {
                Lang::addNamespace($themeName, $themeLangPath);
            }

            if (Str::startsWith(config('app.url') ?? '', 'https://')) {
                URL::forceScheme('https');
            }
            URL::forceRootUrl(config('app.url'));

            Config::set('filesystems.disks.public.url', config('app.url') . '/storage');
        } catch (Exception $e) {
            // Do nothing
        }
    }

    public static function flushCache()
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('settings');
        // Restart queue worker
        Artisan::call('queue:restart');
        self::getSettings(true);
    }
}

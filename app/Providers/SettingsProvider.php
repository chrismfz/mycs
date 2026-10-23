<?php

namespace App\Providers;

use App\Classes\Settings;
use App\Models\Setting;
use App\Support\BootFailure;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SettingsProvider extends ServiceProvider
{
    private const CACHE_KEY = 'settings.rows';

    /** A setting was saved since the settings were last read from the database: flush when the app terminates. */
    private const FLUSH_PENDING = 'settings.flush-pending';

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

        // Once per app, flushing only if still pending: a flush or reload meanwhile already read what was saved (the
        // seeder flushes at its end; a test reloads in setUp() and its config() stays past its first request).
        $this->app->terminating(function () {
            if (self::flushPending()) {
                self::flushCache();
            }
        });
    }

    /** A setting was saved: flush the settings when the app terminates, unless they are read again before. */
    public static function flushLater(): void
    {
        app()->instance(self::FLUSH_PENDING, true);
    }

    public static function flushPending(): bool
    {
        return app()->bound(self::FLUSH_PENDING);
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

            if (Str::startsWith(config('app.url') ?? '', 'https://')) {
                URL::forceScheme('https');
            }
            URL::forceRootUrl(config('app.url'));

            Config::set('filesystems.disks.public.url', config('app.url') . '/storage');
        } catch (Exception $e) {
            BootFailure::handle($e); // reported; fatal for web requests and workers (no running on defaults)
        }
    }

    /** The settings again from the database, past the cached rows (tests: the database is switched after the boot). */
    public static function reload(): void
    {
        app()->forgetInstance(self::FLUSH_PENDING);
        Cache::forget(self::CACHE_KEY);
        self::getSettings(true);
    }

    public static function flushCache()
    {
        app()->forgetInstance(self::FLUSH_PENDING);
        Cache::forget(self::CACHE_KEY);
        Cache::forget('settings');
        // Restart queue worker
        Artisan::call('queue:restart');
        self::getSettings(true);
    }
}

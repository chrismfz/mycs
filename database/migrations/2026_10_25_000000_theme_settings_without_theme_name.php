<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The theme system went (one set of client views in resources/views): the active theme's settings, stored as
 * theme_<theme>_<name>, become theme_<name>, and the theme choice itself goes. Other themes' settings are left as
 * they are (nothing reads them).
 */
return new class extends Migration
{
    public function up(): void
    {
        $active = DB::table('settings')->whereNull('settingable_type')->where('key', 'theme')->value('value') ?: 'default';
        $this->rename("theme_{$active}_", 'theme_');
        DB::table('settings')->whereNull('settingable_type')->where('key', 'theme')->delete();
        Cache::forget('settings.rows'); // SettingsProvider::CACHE_KEY
        Cache::forget('settings');
    }

    public function down(): void
    {
        DB::table('settings')->insert(['key' => 'theme', 'value' => 'default', 'type' => 'string', 'created_at' => now(), 'updated_at' => now()]);
        $this->rename('theme_', 'theme_default_');
        Cache::forget('settings.rows'); // SettingsProvider::CACHE_KEY
        Cache::forget('settings');
    }

    private function rename(string $from, string $to): void
    {
        $taken = DB::table('settings')->whereNull('settingable_type')->pluck('key')->flip();
        foreach (DB::table('settings')->whereNull('settingable_type')->get(['id', 'key']) as $setting) {
            if (!str_starts_with($setting->key, $from)) {
                continue;
            }
            $key = $to . substr($setting->key, strlen($from));
            // Down: only the old theme settings (not theme_default_x again), and never over a key that exists.
            if (isset($taken[$key]) || ($to === 'theme_default_' && str_starts_with($setting->key, 'theme_default_'))) {
                continue;
            }
            DB::table('settings')->where('id', $setting->id)->update(['key' => $key]);
        }
    }
};

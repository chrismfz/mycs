<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The theme system went (one set of client views in resources/views): the active theme's settings, stored as
 * theme_<theme>_<name>, become theme_<name>, and the theme choice itself goes. Other themes' settings are left as
 * they are (nothing reads them).
 */
return new class extends Migration
{
    /** The default theme's settings (themes/default/theme.php): the ones down() gives their old names back. */
    private const NAMES = [
        'direct_checkout', 'small_images', 'show_category_description', 'logo_display', 'home_page_text',
        'primary', 'secondary', 'neutral', 'base', 'muted', 'inverted', 'background', 'background-secondary',
        'dark-primary', 'dark-secondary', 'dark-neutral', 'dark-base', 'dark-muted', 'dark-inverted', 'dark-background',
        'dark-background-secondary',
    ];

    public function up(): void
    {
        $active = DB::table('settings')->whereNull('settingable_type')->where('key', 'theme')->value('value') ?: 'default';
        $prefix = "theme_{$active}_";
        foreach ($this->global()->get(['id', 'key']) as $setting) {
            if (str_starts_with($setting->key, $prefix)) {
                $this->move($setting->id, 'theme_' . substr($setting->key, strlen($prefix)));
            }
        }
        $this->global()->where('key', 'theme')->delete();
        $this->forgetCache();
    }

    public function down(): void
    {
        if (!$this->global()->where('key', 'theme')->exists()) {
            DB::table('settings')->insert(['key' => 'theme', 'value' => 'default', 'type' => 'string', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (self::NAMES as $name) {
            $id = $this->global()->where('key', "theme_{$name}")->value('id');
            if ($id !== null) {
                $this->move($id, "theme_default_{$name}");
            }
        }
        $this->forgetCache();
    }

    /**
     * The row takes the new key. One already there (seeded with the new code before this ran) is a default: the
     * staff's value wins and the default row goes.
     */
    private function move(int $id, string $key): void
    {
        $this->global()->where('key', $key)->delete();
        DB::table('settings')->where('id', $id)->update(['key' => $key]);
    }

    private function global(): Builder
    {
        return DB::table('settings')->whereNull('settingable_type');
    }

    private function forgetCache(): void
    {
        Cache::forget('settings.rows'); // SettingsProvider::CACHE_KEY
        Cache::forget('settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The languages no customer of either company ever used, half translated from the upstream, are gone (owner
 * 2026-10-24): kept are el, en, da, de, es, fr, nl, pt, sv. The settings that named a dropped one fall back to English,
 * and logins and accounts that chose one go back to the instance's language.
 */
return new class extends Migration
{
    private const DROPPED = ['ar', 'bn', 'fi', 'he', 'hi', 'hu', 'id', 'it', 'ko', 'lv', 'no', 'pl', 'sr', 'tr', 'uk', 'zh'];

    public function up(): void
    {
        $setting = fn (string $key) => DB::table('settings')->where('key', $key)->whereNull('settingable_type');

        $allowed = $setting('allowed_languages')->value('value');
        $list = is_string($allowed) ? json_decode($allowed, true) : null;
        if (is_array($list)) {
            $kept = array_values(array_diff($list, self::DROPPED));
            $setting('allowed_languages')->update(['value' => json_encode($kept === [] ? ['en'] : $kept)]);
        }
        foreach (['app_language', 'admin_language'] as $key) {
            if (in_array($setting($key)->value('value'), self::DROPPED, true)) {
                $setting($key)->update(['value' => 'en']);
            }
        }
        foreach (['users', 'clients'] as $table) {
            DB::table($table)->whereIn('language', self::DROPPED)->update(['language' => null]);
        }
    }

    public function down(): void
    {
        // The files are gone with this change: nothing to bring back.
    }
};

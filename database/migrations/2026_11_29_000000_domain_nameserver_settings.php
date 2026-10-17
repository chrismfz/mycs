<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Domains settings tab (owner 2026-10-17, as WHMCS): the default nameservers one per field (NS1 to NS5) instead of
 * one textarea (D8b-2). What was set there moves to the fields, in its order.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('settings')->whereNull('settingable_type')->where('key', 'domain_default_nameservers')->first();
        if ($old !== null) {
            $hosts = array_values(array_filter(preg_split('/[\s,]+/', trim((string) $old->value)) ?: []));
            foreach (array_slice($hosts, 0, 5) as $i => $host) {
                $key = 'domain_nameserver_' . ($i + 1);
                $row = DB::table('settings')->whereNull('settingable_type')->where('key', $key)->first();
                if ($row === null) {
                    DB::table('settings')->insert(['key' => $key, 'value' => mb_strtolower($host), 'type' => 'string', 'encrypted' => false,
                        'created_at' => now(), 'updated_at' => now()]);
                } elseif (trim((string) $row->value) === '') {
                    DB::table('settings')->where('id', $row->id)->update(['value' => mb_strtolower($host), 'updated_at' => now()]);
                }
            }
            DB::table('settings')->where('id', $old->id)->delete();
        }
        // The settings as cached (SettingsProvider): read again from the table.
        Cache::forget('settings.rows');
        Cache::forget('settings');
    }

    public function down(): void
    {
        $hosts = DB::table('settings')->whereNull('settingable_type')->where('key', 'like', 'domain_nameserver_%')->orderBy('key')->pluck('value')->filter()->all();
        if ($hosts !== []) {
            DB::table('settings')->insert(['key' => 'domain_default_nameservers', 'value' => implode("\n", $hosts), 'type' => 'string',
                'encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('settings')->whereNull('settingable_type')->where('key', 'like', 'domain_nameserver_%')->delete();
        // The settings as cached (SettingsProvider): read again from the table.
        Cache::forget('settings.rows');
        Cache::forget('settings');
    }
};

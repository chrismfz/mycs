<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * The settings cache used to hold decrypted secrets under 'settings' (audit sweep KR-7). SettingsProvider removes it
 * when it first builds 'settings.rows', but old code still serving during a deploy can write it back: removed once
 * more here, after the switch. Storage backups taken before still hold those secrets in clear: rotate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Cache::forget('settings');
    }

    public function down(): void {}
};

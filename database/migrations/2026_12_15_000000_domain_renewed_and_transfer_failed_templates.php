<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The domain mails WHMCS sends and we did not (mail check of 2026-10-21, ROADMAP 6γ): a renewal done
 * («domain_renewed»), a transfer that did not go through («domain_transfer_failed»), en and el. Added where missing.
 */
return new class extends Migration
{
    private const KEYS = ['domain_renewed', 'domain_transfer_failed'];

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            if (!DB::table('notification_templates')->where('key', $key)->exists()) {
                DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[$key], [
                    'key' => $key,
                    'enabled' => true,
                    'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('key', self::KEYS)->delete();
    }
};

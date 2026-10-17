<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain expiry notices (D7, docs/domains.md §2b): the templates DomainReminders sends (en and el), added where missing.
 */
return new class extends Migration
{
    private const KEYS = ['domain_renewal_notice', 'domain_free_renewal_notice', 'domain_expired_notice'];

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

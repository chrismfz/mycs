<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domains ordered (D8b-2, docs/domains.md §4): the mails DomainOrderFulfilment sends once a domain is registered or its
 * transfer started (en and el), added where missing.
 */
return new class extends Migration
{
    private const KEYS = ['domain_registered', 'domain_transfer_started'];

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

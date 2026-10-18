<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A transfer done at once (the .gr registry approves it on the spot, G5b-3 of grepp): «domain_transferred» (en and el),
 * not «started, 5 to 7 days». Added where missing.
 */
return new class extends Migration
{
    private const KEY = 'domain_transferred';

    public function up(): void
    {
        if (!DB::table('notification_templates')->where('key', self::KEY)->exists()) {
            DB::table('notification_templates')->insert(array_merge(EmailTemplateSeeder::mapping[self::KEY], [
                'key' => self::KEY,
                'enabled' => true,
                'translations' => json_encode(EmailTemplateSeeder::translations[self::KEY], JSON_UNESCAPED_UNICODE),
            ]));
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', self::KEY)->delete();
    }
};

<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Service cancelled» (sweep modules §21): the customer who asked for the end of the period is told it ended as asked,
 * not that it was «terminated».
 */
return new class extends Migration
{
    public function up(): void
    {
        $key = 'service_cancelled';
        DB::table('notification_templates')->insertOrIgnore(array_merge(EmailTemplateSeeder::mapping[$key], [
            'key' => $key,
            'enabled' => true,
            'translations' => json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE),
        ]));
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'service_cancelled')->delete();
    }
};

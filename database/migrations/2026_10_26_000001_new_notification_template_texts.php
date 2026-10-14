<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The templates' new texts, in English and Greek (audit sweep Σ4, from the WHMCS templates in use): a template staff
 * never edited (updated_at empty: seeded) takes them; one staff edited keeps its text and is logged, its Greek text to
 * be written by hand. New templates (server_unsuspended) are added.
 */
return new class extends Migration
{
    private const FIELDS = ['subject', 'body', 'in_app_title', 'in_app_body', 'in_app_url', 'mail_enabled', 'in_app_enabled', 'edit_preference_message'];

    public function up(): void
    {
        foreach (EmailTemplateSeeder::mapping as $key => $data) {
            $translations = isset(EmailTemplateSeeder::translations[$key]) ? json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE) : null;
            $row = DB::table('notification_templates')->where('key', $key)->first();
            if ($row === null) {
                DB::table('notification_templates')->insert(array_merge($data, ['key' => $key, 'enabled' => true, 'translations' => $translations]));

                continue;
            }
            if ($row->updated_at !== null) {
                Log::notice("Notification template '{$key}' was edited by staff: kept as it is, without the new Greek text.");

                continue;
            }
            DB::table('notification_templates')->where('id', $row->id)->update(array_merge(
                array_intersect_key($data, array_flip(self::FIELDS)),
                ['translations' => $translations],
            ));
        }
    }

    public function down(): void
    {
        // The old texts are not kept: the translations column goes with its own migration.
    }
};

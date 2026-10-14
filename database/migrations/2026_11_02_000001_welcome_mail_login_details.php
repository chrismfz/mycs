<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The welcome mail (new_server_created) gives the login, as WHMCS's «Hosting Account Welcome Email»: username and, in
 * this mail only, {{ service_password }}. Its English and Greek bodies take the new text where they are still the
 * previous default (by their hash); a text staff rewrote is kept.
 */
return new class extends Migration
{
    private const PREVIOUS_BODY = '767c7800420ec61622ab3c9060736568';

    private const PREVIOUS_GREEK_BODY = 'a465b8f7cdf88d2747353f8f4a935b3f';

    public function up(): void
    {
        $row = DB::table('notification_templates')->where('key', 'new_server_created')->first();
        if ($row === null) {
            return;
        }
        $changes = [];
        if (md5((string) $row->body) === self::PREVIOUS_BODY) {
            $changes['body'] = EmailTemplateSeeder::mapping['new_server_created']['body'];
        }
        $translations = json_decode((string) $row->translations, true) ?: [];
        if (md5((string) ($translations['el']['body'] ?? '')) === self::PREVIOUS_GREEK_BODY) {
            $translations['el']['body'] = EmailTemplateSeeder::translations['new_server_created']['el']['body'];
            $changes['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        }
        if ($changes !== []) {
            DB::table('notification_templates')->where('id', $row->id)->update($changes);
        }
    }

    public function down(): void
    {
        // The previous text is not kept.
    }
};

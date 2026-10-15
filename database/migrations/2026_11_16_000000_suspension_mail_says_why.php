<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Service suspended» says «because its renewal is overdue» only when the cron suspended it for that
 * (`dunning_suspended_at`); staff's suspend says «was suspended», with the reason staff wrote. Each language takes the
 * new text only while it is still the previous default; one staff rewrote is kept and logged.
 */
return new class extends Migration
{
    private const KEY = 'server_suspended';

    /** md5 of subject, body, in_app_title, in_app_body (NUL-joined) of the previous defaults (a1aaf27). */
    private const PREVIOUS_EN = '1444545cc9ff0ca24da979eb339fe532';

    private const PREVIOUS_EL = '8baf79cd97cd910d55d6d642d199335b';

    public function up(): void
    {
        $row = DB::table('notification_templates')->where('key', self::KEY)->first();
        if ($row === null) {
            return;
        }
        $update = [];
        $new = EmailTemplateSeeder::mapping[self::KEY];
        $hash = self::hash((array) $row);
        if ($hash === self::PREVIOUS_EN) {
            $update += array_intersect_key($new, array_flip(['subject', 'body', 'in_app_title', 'in_app_body']));
        } elseif ($hash !== self::hash($new)) { // a fresh install already has the new text
            Log::notice("Notification template '" . self::KEY . "' was rewritten by staff: its English text kept.");
        }
        $translations = json_decode((string) $row->translations, true);
        if (is_array($translations['el'] ?? null)) {
            $hash = self::hash($translations['el']);
            if ($hash === self::PREVIOUS_EL) {
                $translations['el'] = array_merge($translations['el'], EmailTemplateSeeder::translations[self::KEY]['el']);
                $update['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
            } elseif ($hash !== self::hash(EmailTemplateSeeder::translations[self::KEY]['el'])) {
                Log::notice("Notification template '" . self::KEY . "' was rewritten by staff: its Greek text kept.");
            }
        }
        if ($update !== []) {
            DB::table('notification_templates')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // The previous texts are not kept.
    }

    /** @param  array<string, mixed>  $texts */
    private static function hash(array $texts): string
    {
        return md5(implode("\0", [$texts['subject'] ?? '', $texts['body'] ?? '', $texts['in_app_title'] ?? '', $texts['in_app_body'] ?? '']));
    }
};

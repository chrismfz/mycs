<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bank transfer (owner 2026-10-21): the new invoice mail lists the bank accounts and the reference to write, for an unpaid
 * invoice when the method is set up ({{ bank_transfer }}). A text still the default takes it, English and Greek each on
 * its own; one staff rewrote is kept and logged (they add the block by hand if they want it).
 */
return new class extends Migration
{
    private const KEY = 'new_invoice_created';

    /**
     * md5 of subject, body, in_app_title, in_app_body (NUL-joined) of every default these texts have had (the seeder's
     * history: 3d920e8d, 6e5ba773, 57651ec7). Some changed without a migration, so a database may still hold an older one
     * (the live one did, adversarial BT-ADV-5): each takes the new text whole.
     */
    private const PREVIOUS = [
        'en' => ['03ec555b1e9c148bd8eff3328c8de462', '68d38afcdead57c6c7c4b205d9979fd7', '65ffa8770419ff8cc0b5bdbcce8a07b2'],
        'el' => ['438b9a380c3f9c55280e4b9f90efbf89', '1daedc928920c7333b15b46d1153daf8'],
    ];

    private const FIELDS = ['subject', 'body', 'in_app_title', 'in_app_body'];

    public function up(): void
    {
        $row = DB::table('notification_templates')->where('key', self::KEY)->first();
        if ($row === null) {
            return; // the seeder writes the new texts
        }
        $update = [];
        $default = EmailTemplateSeeder::mapping[self::KEY];
        $hash = self::hash((array) $row);
        if (in_array($hash, self::PREVIOUS['en'], true)) {
            $update = array_intersect_key($default, array_flip(self::FIELDS));
        } elseif ($hash !== self::hash($default)) {
            Log::notice("Notification template '" . self::KEY . "' was rewritten by staff: kept, without the bank transfer block.");
        }
        $translations = json_decode((string) $row->translations, true);
        $el = is_array($translations) ? ($translations['el'] ?? null) : null;
        if (is_array($el) && in_array(self::hash($el), self::PREVIOUS['el'], true)) {
            $translations['el'] = array_intersect_key(EmailTemplateSeeder::translations[self::KEY]['el'], array_flip(self::FIELDS)) + $el;
            $update['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        } elseif (is_array($el) && self::hash($el) !== self::hash(EmailTemplateSeeder::translations[self::KEY]['el'])) {
            Log::notice("Notification template '" . self::KEY . "' (el) was rewritten by staff: kept, without the bank transfer block.");
        }
        if ($update !== []) {
            DB::table('notification_templates')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // The block reads nothing when the method is not set up: left in place.
    }

    /** @param  array<string, mixed>  $texts */
    private static function hash(array $texts): string
    {
        return md5(implode("\0", [$texts['subject'] ?? '', $texts['body'] ?? '', $texts['in_app_title'] ?? '', $texts['in_app_body'] ?? '']));
    }
};

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

    /** md5 of subject, body, in_app_title, in_app_body (NUL-joined) of the defaults before this migration. */
    private const PREVIOUS = ['en' => '65ffa8770419ff8cc0b5bdbcce8a07b2', 'el' => '1daedc928920c7333b15b46d1153daf8'];

    public function up(): void
    {
        $row = DB::table('notification_templates')->where('key', self::KEY)->first();
        if ($row === null) {
            return; // the seeder writes the new texts
        }
        $update = [];
        $default = EmailTemplateSeeder::mapping[self::KEY];
        if (self::hash((array) $row) === self::PREVIOUS['en']) {
            $update['body'] = $default['body'];
        } elseif (self::hash((array) $row) !== self::hash($default)) {
            Log::notice("Notification template '" . self::KEY . "' was rewritten by staff: kept, without the bank transfer block.");
        }
        $translations = json_decode((string) $row->translations, true);
        $el = is_array($translations) ? ($translations['el'] ?? null) : null;
        if (is_array($el) && self::hash($el) === self::PREVIOUS['el']) {
            $translations['el']['body'] = EmailTemplateSeeder::translations[self::KEY]['el']['body'];
            $update['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
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

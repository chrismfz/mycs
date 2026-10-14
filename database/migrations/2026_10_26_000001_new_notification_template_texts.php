<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The templates' new texts, in English and Greek (audit sweep Σ4, from the WHMCS templates in use). A template whose
 * texts are still a default (the previous ones, by their hash, or already the new ones) takes them; one staff rewrote
 * keeps its text and is logged, its Greek text to be written by hand. Not updated_at: saving the form unchanged stamps
 * it. New templates (server_unsuspended) are added; the default mail footer gets its Greek line.
 */
return new class extends Migration
{
    private const FIELDS = ['subject', 'body', 'in_app_title', 'in_app_body', 'in_app_url', 'mail_enabled', 'in_app_enabled', 'edit_preference_message'];

    /** md5 of subject, body, in_app_title, in_app_body (NUL-joined) of the defaults before this migration (0be6618). */
    private const PREVIOUS_DEFAULTS = [
        'new_login_detected' => 'ac04c5badfcae541a6ea1710e07093eb',
        'new_invoice_created' => '491ed286c448279483f9bdab0cca395d',
        'invoice_paid' => '803c71442b738a2f63f3a6812afcda8e',
        'credit_applied' => '9547e95181dca21bdbe5b9e85c37805e',
        'invoice_refunded' => '83fb412974e1bb492eca4587a101f29e',
        'invoice_payment_failed' => '5617717f530a13b08a40b10067f13adc',
        'new_order_created' => '258a30a74dd672c5d2b59c03011e445a',
        'new_server_created' => '9d2ae94af1a4b8db92712a2f2747b542',
        'server_suspended' => 'f8049408ad6380e0f107e8f4c7d6446a',
        'server_terminated' => '8836752bf4b2c400e33de82aff88ffb5',
        'new_ticket_message' => '45beda162d50f1ac3c523ebcabdb0251',
        'email_verification' => 'ae3732874f70f224491270ea87c9d998',
        'password_reset' => '3560ad9906ef4f97eaf3aca5dd94305e',
        'service_cancellation_received' => '731e8cbf8766e8ba238fb999cce95cea',
    ];

    private const OLD_FOOTER_LINE = '© {{ app.year }} {{ app.name }}. All rights reserved.';

    private const NEW_FOOTER_LINE = '© {{ app.year }} {{ app.name }}. {% if locale == "el" %}Με επιφύλαξη παντός δικαιώματος.{% else %}All rights reserved.{% endif %}';

    public function up(): void
    {
        foreach (EmailTemplateSeeder::mapping as $key => $data) {
            $translations = isset(EmailTemplateSeeder::translations[$key]) ? json_encode(EmailTemplateSeeder::translations[$key], JSON_UNESCAPED_UNICODE) : null;
            $row = DB::table('notification_templates')->where('key', $key)->first();
            if ($row === null) {
                DB::table('notification_templates')->insert(array_merge($data, ['key' => $key, 'enabled' => true, 'translations' => $translations]));

                continue;
            }
            $hash = self::hash((array) $row);
            if ($hash !== (self::PREVIOUS_DEFAULTS[$key] ?? null) && $hash !== self::hash($data)) {
                Log::notice("Notification template '{$key}' was rewritten by staff: kept as it is, without the new Greek text.");

                continue;
            }
            DB::table('notification_templates')->where('id', $row->id)->update(array_merge(
                array_intersect_key($data, array_flip(self::FIELDS)),
                ['translations' => $translations],
            ));
        }

        foreach (DB::table('settings')->whereNull('settingable_type')->where('key', 'mail_footer')->get(['id', 'value']) as $footer) {
            if (str_contains((string) $footer->value, self::OLD_FOOTER_LINE)) {
                DB::table('settings')->where('id', $footer->id)->update(['value' => str_replace(self::OLD_FOOTER_LINE, self::NEW_FOOTER_LINE, $footer->value)]);
                Cache::forget('settings.rows'); // SettingsProvider::CACHE_KEY
                Cache::forget('settings');
            }
        }
    }

    public function down(): void
    {
        // The old texts are not kept: the translations column goes with its own migration.
    }

    /** @param  array<string, mixed>  $texts */
    private static function hash(array $texts): string
    {
        return md5(implode("\0", [$texts['subject'] ?? '', $texts['body'] ?? '', $texts['in_app_title'] ?? '', $texts['in_app_body'] ?? '']));
    }
};

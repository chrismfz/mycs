<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Templates are no longer compiled as Blade (docs/history/audit-security.md H1). Fields still in
 * Blade syntax are replaced by the new default of the same field; anything else is kept.
 * A customised Blade template cannot be converted automatically: it is logged so it can
 * be rewritten in the new syntax.
 */
return new class extends Migration
{
    private const FIELDS = ['subject', 'body', 'in_app_title', 'in_app_body', 'in_app_url'];

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get() as $row) {
            $defaults = EmailTemplateSeeder::mapping[$row->key] ?? [];
            $changes = [];
            foreach (self::FIELDS as $field) {
                if (!$this->isBlade($row->{$field} ?? null)) {
                    continue;
                }
                if (array_key_exists($field, $defaults)) {
                    $changes[$field] = $defaults[$field];
                    Log::notice("Notification template '{$row->key}'.{$field} was Blade: replaced with the new default.");
                } else {
                    Log::warning("Notification template '{$row->key}'.{$field} is Blade and has no default: rewrite it in the new template syntax.");
                }
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $row->id)->update($changes);
            }
        }

        // Product snippets have no default to fall back to: flag them for a rewrite.
        foreach (DB::table('products')->whereNotNull('email_template')->get(['id', 'email_template']) as $product) {
            if ($this->isBlade($product->email_template)) {
                Log::warning("Product #{$product->id} email_template is Blade: rewrite it in the new template syntax.");
            }
        }

        foreach (['mail_header', 'mail_footer'] as $key) {
            $value = DB::table('settings')->where('key', $key)->whereNull('settingable_type')->value('value');
            if ($this->isBlade($value)) {
                DB::table('settings')->where('key', $key)->whereNull('settingable_type')
                    ->update(['value' => file_get_contents(database_path('seeders/data/' . $key))]);
            }
        }

        Cache::forget('settings');
        Cache::forget('settings.rows'); // SettingsProvider's cache since KR-7
    }

    private function isBlade(?string $value): bool
    {
        return $value !== null && preg_match('/\{\{\s*\$|\{\{\s*[A-Za-z_\\\\]+\(|\{!!|@(if|isset|foreach|endif|else|php)\b/', $value) === 1;
    }
};

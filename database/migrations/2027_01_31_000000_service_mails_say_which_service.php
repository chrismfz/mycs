<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The service mails say which service (owner 2026-11-03: «Service suspended: cPanel test 10» could be any of them): its
 * product and its domain, or its own label, or its license (Service::mailName). Only text still as seeded changes: a
 * template staff wrote otherwise keeps its own.
 */
return new class extends Migration
{
    private const SEEDED = '{{ service.product.name }}';

    private const NAMED = '{{ service.mailName }}';

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get(['id', 'subject', 'body', 'translations']) as $template) {
            $translations = $template->translations;
            $decoded = is_string($translations) ? json_decode($translations, true) : null;
            if (is_array($decoded)) {
                array_walk_recursive($decoded, function (&$value) {
                    $value = is_string($value) ? str_replace(self::SEEDED, self::NAMED, $value) : $value;
                });
                $translations = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            $subject = str_replace(self::SEEDED, self::NAMED, (string) $template->subject);
            $body = str_replace(self::SEEDED, self::NAMED, (string) $template->body);
            if ($subject !== (string) $template->subject || $body !== (string) $template->body || $translations !== $template->translations) {
                DB::table('notification_templates')->where('id', $template->id)->update(['subject' => $subject, 'body' => $body, 'translations' => $translations]);
            }
        }
    }
};

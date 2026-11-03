<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The service mails say which service (owner 2026-11-03: «Service suspended: cPanel test 10» could be any of them): its
 * product and its domain, or its own label, or its license (Service::mailName). In every text field of a template
 * (subject, body, in-app title and body, and their translations) the product's name becomes the service's; never in one
 * that already names the service some other way (its domain, label or license: code review of #592, F2), which staff
 * wrote so and keep.
 */
return new class extends Migration
{
    private const SEEDED = '{{ service.product.name }}';

    private const NAMED = '{{ service.mailName }}';

    private const FIELDS = ['subject', 'body', 'in_app_title', 'in_app_body'];

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get(['id', 'translations', ...self::FIELDS]) as $template) {
            $update = [];
            foreach (self::FIELDS as $field) {
                $named = $this->named($template->{$field});
                if ($named !== $template->{$field}) {
                    $update[$field] = $named;
                }
            }
            $decoded = is_string($template->translations) ? json_decode($template->translations, true) : null;
            if (is_array($decoded)) {
                $changed = false;
                array_walk_recursive($decoded, function (&$value) use (&$changed) {
                    $named = $this->named($value);
                    $changed = $changed || $named !== $value;
                    $value = $named;
                });
                if ($changed) {
                    $update['translations'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
            if ($update !== []) {
                DB::table('notification_templates')->where('id', $template->id)->update($update);
            }
        }
    }

    private function named(mixed $text): mixed
    {
        if (!is_string($text) || !str_contains($text, self::SEEDED) || preg_match('/service\.(domain|label|license|mailName)\b/', $text) === 1) {
            return $text;
        }

        return str_replace(self::SEEDED, self::NAMED, $text);
    }
};

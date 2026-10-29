<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice emails' button names the invoice's preferred payment method («Pay with PayPal») and opens its payment
 * window (urls.pay). Only a template whose button is still the default one changes: one staff edited stays as it is.
 */
return new class extends Migration
{
    /** @var array<string, string> the button's text => its new form (English, Greek) */
    private const BUTTONS = [
        'View & pay invoice' => '{% if preferred_method %}Pay with {{ preferred_method }}{% else %}View & pay invoice{% endif %}',
        'Προβολή & πληρωμή' => '{% if preferred_method %}Πληρωμή με {{ preferred_method }}{% else %}Προβολή & πληρωμή{% endif %}',
        // The reminders' and overdue notices' (reviews of #539: the main chasing email kept the old one).
        'Pay invoice' => '{% if preferred_method %}Pay with {{ preferred_method }}{% else %}Pay invoice{% endif %}',
        'Εξόφληση προτιμολογίου' => '{% if preferred_method %}Πληρωμή με {{ preferred_method }}{% else %}Εξόφληση προτιμολογίου{% endif %}',
    ];

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get(['id', 'body', 'translations']) as $template) {
            $body = $this->button((string) $template->body);
            $translations = $template->translations;
            $decoded = is_string($translations) ? json_decode($translations, true) : null;
            if (is_array($decoded)) {
                array_walk_recursive($decoded, function (&$value) {
                    if (is_string($value)) {
                        $value = $this->button($value);
                    }
                });
                $translations = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            if ($body !== (string) $template->body || $translations !== $template->translations) {
                DB::table('notification_templates')->where('id', $template->id)->update(['body' => $body, 'translations' => $translations]);
            }
        }
    }

    private function button(string $html): string
    {
        foreach (self::BUTTONS as $text => $new) {
            $changed = preg_replace('#href="\{\{ urls\.invoice \}\}">(\s*)' . preg_quote($text, '#') . '(\s*)</a>#u',
                'href="{{ urls.pay }}">$1' . str_replace('$', '\$', $new) . '$2</a>', $html);
            $html = $changed ?? $html; // not valid UTF-8: kept as it is, never emptied (reviews of #539)
        }

        return $html;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice mails' «Total» is the total after the account credit used on the invoice, as the PDF and the portal show
 * it (ROADMAP «Total»): the line «Credit applied» (el «Πίστωση») before it, when there is credit. Only where the default
 * line is still there as seeded; a template staff rewrote keeps its text.
 */
return new class extends Migration
{
    private const LINES = ['Total' => 'Credit applied', 'Σύνολο' => 'Πίστωση'];

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get(['id', 'body', 'translations']) as $row) {
            $body = self::withCredit((string) $row->body);
            $translations = json_decode((string) $row->translations, true);
            if (is_array($translations)) {
                foreach ($translations as $locale => $texts) {
                    if (is_array($texts) && isset($texts['body']) && is_string($texts['body'])) {
                        $translations[$locale]['body'] = self::withCredit($texts['body']);
                    }
                }
            }
            DB::table('notification_templates')->where('id', $row->id)->update([
                'body' => $body,
                'translations' => is_array($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : $row->translations,
            ]);
        }
    }

    public function down(): void
    {
        // The line stays: it is shown only when credit was used.
    }

    private static function withCredit(string $body): string
    {
        if (str_contains($body, '{{ credit }}')) {
            return $body;
        }
        foreach (self::LINES as $total => $credit) {
            $body = (string) preg_replace('/^([ \t]*)- ' . $total . ': \*\*\{\{ total \}\}\*\*$/mu',
                "\$1{% if credit %}\n\$1- {$credit}: **{{ credit }}**\n\$1{% endif %}\n\$1- {$total}: **{{ total }}**", $body);
        }

        return $body;
    }
};

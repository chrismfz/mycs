<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «New invoice» and «Payment received» name the invoice's own amount in their subject (owner 2026-11-13): `total` is
 * what is left after the account credit, so an invoice credit paid said «€0,00» there (nixpal, 2026-6892). Only a
 * subject still as seeded changes: one staff edited keeps its own.
 */
return new class extends Migration
{
    /** @var array<string, string> seeded subject => its new form */
    private const SUBJECTS = [
        'New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ total }}' => 'New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ amount }}',
        'Payment received: invoice #{{ invoice_number }} | {{ total }}' => 'Payment received: invoice #{{ invoice_number }} | {{ amount }}',
        'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ total }}' => 'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ amount }}',
        'Επιβεβαίωση πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ total }}' => 'Επιβεβαίωση πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ amount }}',
    ];

    public function up(): void
    {
        $this->each(self::SUBJECTS);
    }

    public function down(): void
    {
        $this->each(array_flip(self::SUBJECTS));
    }

    /** @param  array<string, string>  $map  a subject exactly as it was => its new form */
    private function each(array $map): void
    {
        foreach (DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'invoice_paid'])->get(['id', 'subject', 'translations']) as $row) {
            $changes = [];
            if (isset($map[(string) $row->subject])) {
                $changes['subject'] = $map[(string) $row->subject];
            }
            $translations = is_string($row->translations) ? json_decode($row->translations, true) : null;
            $translated = false;
            foreach (is_array($translations) ? $translations : [] as $locale => $fields) {
                if (is_array($fields) && is_string($fields['subject'] ?? null) && isset($map[$fields['subject']])) {
                    $translations[$locale]['subject'] = $map[$fields['subject']];
                    $translated = true;
                }
            }
            if ($translated) { // re-encoded only when a subject in it changed: the rest stays byte for byte
                $changes['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $row->id)->update($changes);
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice mails answer «what is it, what if I do not pay, is it renewed?» (nixpal 2026-11-17): each line links to
 * its service or domain, a new invoice and its reminder say when its services are suspended unpaid, a new invoice
 * charged on its own says when and with what, and «payment received» lists what it paid until when. Only text still
 * as seeded changes (each sentence on its own): a template staff rewrote keeps theirs.
 */
return new class extends Migration
{
    /** @var array<string, string> a seeded text => its new form */
    private const TEXTS = [
        '| {{ item.description }} |' => '| {% if item.serviceUrl %}[{{ item.description }}]({{ item.serviceUrl }}){% else %}{{ item.description }}{% endif %} |',
        'A new invoice was issued for your account.' => 'A new invoice was issued for your account.{% if suspend_on %} Please pay it by **{{ invoice.due_at|date("d/m/Y") }}**: unpaid, its services are suspended on **{{ suspend_on }}**.{% endif %}',
        'Εκδόθηκε νέο προτιμολόγιο για τον λογαριασμό σας.' => 'Εκδόθηκε νέο προτιμολόγιο για τον λογαριασμό σας.{% if suspend_on %} Παρακαλούμε εξοφλήστε το έως **{{ invoice.due_at|date("d/m/Y") }}**: αν μείνει απλήρωτο, οι υπηρεσίες του αναστέλλονται στις **{{ suspend_on }}**.{% endif %}',
        'is due on **{{ invoice.due_at|date("d/m/Y") }}**.' => 'is due on **{{ invoice.due_at|date("d/m/Y") }}**.{% if suspend_on %} Unpaid, its services are suspended on **{{ suspend_on }}**.{% endif %}',
        'λήγει στις **{{ invoice.due_at|date("d/m/Y") }}**.' => 'λήγει στις **{{ invoice.due_at|date("d/m/Y") }}**.{% if suspend_on %} Αν μείνει απλήρωτο, οι υπηρεσίες του αναστέλλονται στις **{{ suspend_on }}**.{% endif %}',
        'It will be paid automatically with {% if auto_charge_method %}{{ auto_charge_method }}{% else %}your saved payment method{% endif %}: there is nothing you need to do.' => '{% if auto_charge_method %}It will be charged automatically on **{{ invoice.due_at|date("d/m/Y") }}** with **{{ auto_charge_method }}**{% else %}It will be paid automatically with your saved payment method{% endif %}: there is nothing you need to do.',
        'Θα εξοφληθεί αυτόματα με {% if auto_charge_method %}{{ auto_charge_method }}{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}: δεν χρειάζεται να κάνετε κάτι.' => '{% if auto_charge_method %}Θα χρεωθεί αυτόματα στις **{{ invoice.due_at|date("d/m/Y") }}** με **{{ auto_charge_method }}**{% else %}Θα εξοφληθεί αυτόματα με τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}: δεν χρειάζεται να κάνετε κάτι.',
        'This email confirms your payment.' => 'This email confirms your payment.' . "\n" . '' . "\n" . '{% if paid_until %}' . "\n" . '**Paid until:**' . "\n" . '' . "\n" . '{% for line in paid_until %}' . "\n" . '- {{ line.name }}: **{{ line.until }}**' . "\n" . '{% endfor %}' . "\n" . '{% endif %}',
        'Αυτό το email επιβεβαιώνει την πληρωμή σας.' => 'Αυτό το email επιβεβαιώνει την πληρωμή σας.' . "\n" . '' . "\n" . '{% if paid_until %}' . "\n" . '**Πληρωμένες έως:**' . "\n" . '' . "\n" . '{% for line in paid_until %}' . "\n" . '- {{ line.name }}: **{{ line.until }}**' . "\n" . '{% endfor %}' . "\n" . '{% endif %}',
    ];

    private const KEYS = ['new_invoice_created', 'invoice_paid', 'invoice_payment_reminder', 'card_payment_due', 'invoice_overdue', 'invoice_modified'];

    public function up(): void
    {
        $this->each(fn (string $body) => self::swap($body, self::TEXTS));
    }

    public function down(): void
    {
        // Back: each new text as it was (the new holds the old, so no «once» here).
        $this->each(fn (string $body) => strtr($body, array_flip(self::TEXTS)));
    }

    /** @param  array<string, string>  $map */
    private static function swap(string $body, array $map): string
    {
        foreach ($map as $from => $to) {
            // Once: a body that has the new text already (a second run) is left alone.
            if (!str_contains($body, $to)) {
                $body = str_replace($from, $to, $body);
            }
        }

        return $body;
    }

    /** @param  callable(string): string  $change */
    private function each(callable $change): void
    {
        foreach (DB::table('notification_templates')->whereIn('key', self::KEYS)->get(['id', 'body', 'translations']) as $row) {
            $changes = [];
            $body = $change((string) $row->body);
            if ($body !== (string) $row->body) {
                $changes['body'] = $body;
            }
            $translations = is_string($row->translations) ? json_decode($row->translations, true) : null;
            $translated = false;
            foreach (is_array($translations) ? $translations : [] as $locale => $fields) {
                if (is_array($fields) && is_string($fields['body'] ?? null) && ($new = $change($fields['body'])) !== $fields['body']) {
                    $translations[$locale]['body'] = $new;
                    $translated = true;
                }
            }
            if ($translated) { // re-encoded only when a body in it changed
                $changes['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
            }
            if ($changes !== []) {
                DB::table('notification_templates')->where('id', $row->id)->update($changes);
            }
        }
    }
};

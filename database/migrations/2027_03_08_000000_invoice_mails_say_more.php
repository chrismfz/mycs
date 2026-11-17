<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice mails answer «what is it, what if I do not pay, is it renewed?» (nixpal 2026-11-17): each line links to
 * its service or domain, a new invoice and its reminder say when its services are suspended unpaid, a new invoice
 * charged on its own says when and with what, and «payment received» lists what it paid until when. Only a line still
 * whole as seeded changes (review of #897, ADV-897-6): a line staff rewrote, even around a seeded sentence, keeps theirs.
 */
return new class extends Migration
{
    /** @var array<string, string> a seeded line, whole => its new form */
    private const LINES = [
        'A new invoice was issued for your account.' => 'A new invoice was issued for your account.{% if suspend_on %} Please pay it by **{{ invoice.due_at|date("d/m/Y") }}**: unpaid, its services are suspended on **{{ suspend_on }}**.{% endif %}',
        '| {{ item.description }} | {{ item.quantity }} | {{ item.formattedPrice }} |' => '| {% if item.serviceUrl %}[{{ item.description }}]({{ item.serviceUrl }}){% else %}{{ item.description }}{% endif %} | {{ item.quantity }} | {{ item.formattedPrice }} |',
        'It will be paid automatically with {% if auto_charge_method %}{{ auto_charge_method }}{% else %}your saved payment method{% endif %}: there is nothing you need to do.' => '{% if auto_charge_method %}It will be charged automatically{% if charge_on %} on **{{ charge_on }}**{% endif %} with **{{ auto_charge_method }}**{% else %}It will be paid automatically with your saved payment method{% endif %}: there is nothing you need to do.',
        'We received your payment for invoice **#{{ invoice_number }}**. Thank you! This email confirms your payment.' => 'We received your payment for invoice **#{{ invoice_number }}**. Thank you! This email confirms your payment.' . "\n" . '' . "\n" . '{% if paid_until %}' . "\n" . '**Paid until:**' . "\n" . '' . "\n" . '{% for line in paid_until %}' . "\n" . '- {{ line.name }}: **{{ line.until }}**' . "\n" . '{% endfor %}' . "\n" . '{% endif %}',
        'This is a reminder that invoice **#{{ invoice_number }}** is due on **{{ invoice.due_at|date("d/m/Y") }}**. If you have already paid it, please ignore this message.' => 'This is a reminder that invoice **#{{ invoice_number }}** is due on **{{ invoice.due_at|date("d/m/Y") }}**.{% if suspend_on %} Unpaid, its services are suspended on **{{ suspend_on }}**.{% endif %} If you have already paid it, please ignore this message.',
        'Εκδόθηκε νέο προτιμολόγιο για τον λογαριασμό σας.' => 'Εκδόθηκε νέο προτιμολόγιο για τον λογαριασμό σας.{% if suspend_on %} Παρακαλούμε εξοφλήστε το έως **{{ invoice.due_at|date("d/m/Y") }}**: αν μείνει απλήρωτο, οι υπηρεσίες του αναστέλλονται στις **{{ suspend_on }}**.{% endif %}',
        'Θα εξοφληθεί αυτόματα με {% if auto_charge_method %}{{ auto_charge_method }}{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}: δεν χρειάζεται να κάνετε κάτι.' => '{% if auto_charge_method %}Θα χρεωθεί αυτόματα{% if charge_on %} στις **{{ charge_on }}**{% endif %} με **{{ auto_charge_method }}**{% else %}Θα εξοφληθεί αυτόματα με τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}: δεν χρειάζεται να κάνετε κάτι.',
        'Η πληρωμή σας για το προτιμολόγιο **#{{ invoice_number }}** ελήφθη με επιτυχία. Σας ευχαριστούμε! Αυτό το email επιβεβαιώνει την πληρωμή σας.' => 'Η πληρωμή σας για το προτιμολόγιο **#{{ invoice_number }}** ελήφθη με επιτυχία. Σας ευχαριστούμε! Αυτό το email επιβεβαιώνει την πληρωμή σας.' . "\n" . '' . "\n" . '{% if paid_until %}' . "\n" . '**Πληρωμένες έως:**' . "\n" . '' . "\n" . '{% for line in paid_until %}' . "\n" . '- {{ line.name }}: **{{ line.until }}**' . "\n" . '{% endfor %}' . "\n" . '{% endif %}',
        'Σας υπενθυμίζουμε ότι το προτιμολόγιο **#{{ invoice_number }}** λήγει στις **{{ invoice.due_at|date("d/m/Y") }}**. Αν το έχετε ήδη εξοφλήσει, παραβλέψτε αυτό το μήνυμα.' => 'Σας υπενθυμίζουμε ότι το προτιμολόγιο **#{{ invoice_number }}** λήγει στις **{{ invoice.due_at|date("d/m/Y") }}**.{% if suspend_on %} Αν μείνει απλήρωτο, οι υπηρεσίες του αναστέλλονται στις **{{ suspend_on }}**.{% endif %} Αν το έχετε ήδη εξοφλήσει, παραβλέψτε αυτό το μήνυμα.',
        // card_payment_due: the day the cron charges, as the new invoice says (REV2-897-2).
        'Invoice **#{{ invoice_number }}** of **{{ total }}** will be charged automatically on **{{ invoice.due_at|date("d/m/Y") }}** with {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}your saved payment method{% endif %}. There is nothing you need to do.' => 'Invoice **#{{ invoice_number }}** of **{{ total }}** will be charged automatically on **{% if charge_on %}{{ charge_on }}{% else %}{{ invoice.due_at|date("d/m/Y") }}{% endif %}** with {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}your saved payment method{% endif %}. There is nothing you need to do.',
        'Το προτιμολόγιο **#{{ invoice_number }}** ποσού **{{ total }}** θα χρεωθεί αυτόματα στις **{{ invoice.due_at|date("d/m/Y") }}** με {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}. Δεν χρειάζεται να κάνετε κάτι.' => 'Το προτιμολόγιο **#{{ invoice_number }}** ποσού **{{ total }}** θα χρεωθεί αυτόματα στις **{% if charge_on %}{{ charge_on }}{% else %}{{ invoice.due_at|date("d/m/Y") }}{% endif %}** με {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}. Δεν χρειάζεται να κάνετε κάτι.',
        'Invoice #{{ invoice_number }} ({{ total }}) will be charged automatically on {{ invoice.due_at|date("d/m/Y") }}.' => 'Invoice #{{ invoice_number }} ({{ total }}) will be charged automatically on {% if charge_on %}{{ charge_on }}{% else %}{{ invoice.due_at|date("d/m/Y") }}{% endif %}.',
        'Το προτιμολόγιο #{{ invoice_number }} ({{ total }}) θα χρεωθεί αυτόματα στις {{ invoice.due_at|date("d/m/Y") }}.' => 'Το προτιμολόγιο #{{ invoice_number }} ({{ total }}) θα χρεωθεί αυτόματα στις {% if charge_on %}{{ charge_on }}{% else %}{{ invoice.due_at|date("d/m/Y") }}{% endif %}.',
    ];

    private const FIELDS = ['body', 'in_app_body'];

    private const KEYS = ['new_invoice_created', 'invoice_paid', 'invoice_payment_reminder', 'card_payment_due', 'invoice_overdue', 'invoice_modified'];

    public function up(): void
    {
        $this->each(function (string $body): string {
            foreach (self::LINES as $from => $to) {
                if (str_contains($body, $to)) {
                    continue; // a second run: as it is
                }
                $body = implode("\n", array_map(fn (string $line) => trim($line) === $from ? str_replace($from, $to, $line) : $line, explode("\n", $body)));
            }

            return $body;
        });
    }

    public function down(): void
    {
        $this->each(fn (string $body) => strtr($body, array_flip(self::LINES)));
    }

    /** @param  callable(string): string  $change */
    private function each(callable $change): void
    {
        foreach (DB::table('notification_templates')->whereIn('key', self::KEYS)->get(['id', 'body', 'in_app_body', 'translations']) as $row) {
            $changes = [];
            foreach (self::FIELDS as $field) { // the mail's body, and the bell's and the push's text
                $text = $change((string) $row->{$field});
                if ($text !== (string) $row->{$field}) {
                    $changes[$field] = $text;
                }
            }
            $translations = is_string($row->translations) ? json_decode($row->translations, true) : null;
            $translated = false;
            foreach (is_array($translations) ? $translations : [] as $locale => $fields) {
                foreach (self::FIELDS as $field) {
                    if (is_array($fields) && is_string($fields[$field] ?? null) && ($new = $change($fields[$field])) !== $fields[$field]) {
                        $translations[$locale][$field] = $new;
                        $translated = true;
                    }
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

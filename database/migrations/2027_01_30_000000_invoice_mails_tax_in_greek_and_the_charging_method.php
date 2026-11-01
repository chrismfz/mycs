<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice emails (owner 2026-11-01): the Greek ones show the subtotal and VAT as the English ones do (WHMCS showed
 * them), and «paid automatically» names the saved method that pays it («Visa 4242», a PayPal account; #568). Only text
 * still as seeded changes: a template staff edited keeps its own.
 */
return new class extends Migration
{
    /** @var array<string, string> seeded sentence => its new form */
    private const SENTENCES = [
        'It will be paid automatically with your saved payment method: there is nothing you need to do.' => 'It will be paid automatically with {% if auto_charge_method %}{{ auto_charge_method }}{% else %}your saved payment method{% endif %}: there is nothing you need to do.',
        'will be charged automatically on **{{ invoice.due_at|date("d/m/Y") }}** with your saved payment method.' => 'will be charged automatically on **{{ invoice.due_at|date("d/m/Y") }}** with {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}your saved payment method{% endif %}.',
        'Θα εξοφληθεί αυτόματα με τον αποθηκευμένο τρόπο πληρωμής σας: δεν χρειάζεται να κάνετε κάτι.' => 'Θα εξοφληθεί αυτόματα με {% if auto_charge_method %}{{ auto_charge_method }}{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}: δεν χρειάζεται να κάνετε κάτι.',
        'θα χρεωθεί αυτόματα στις **{{ invoice.due_at|date("d/m/Y") }}** με τον αποθηκευμένο τρόπο πληρωμής σας.' => 'θα χρεωθεί αυτόματα στις **{{ invoice.due_at|date("d/m/Y") }}** με {% if auto_charge_method %}**{{ auto_charge_method }}**{% else %}τον αποθηκευμένο τρόπο πληρωμής σας{% endif %}.',
    ];

    /** The invoice templates whose English shows the subtotal and tax. */
    private const WITH_TAX = ['new_invoice_created', 'invoice_paid', 'invoice_payment_failed', 'invoice_payment_reminder', 'invoice_overdue', 'invoice_modified'];

    public function up(): void
    {
        foreach (DB::table('notification_templates')->get(['id', 'key', 'body', 'translations']) as $template) {
            $body = $this->sentences((string) $template->body);
            $translations = $template->translations;
            $decoded = is_string($translations) ? json_decode($translations, true) : null;
            if (is_array($decoded)) {
                $tax = in_array($template->key, self::WITH_TAX, true);
                array_walk_recursive($decoded, function (&$value, $field) use ($tax) {
                    if (is_string($value)) {
                        $value = $this->sentences($value);
                        $value = $tax && $field === 'body' ? $this->greekTax($value) : $value;
                    }
                });
                $translations = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
            if ($body !== (string) $template->body || $translations !== $template->translations) {
                DB::table('notification_templates')->where('id', $template->id)->update(['body' => $body, 'translations' => $translations]);
            }
        }
    }

    private function sentences(string $text): string
    {
        return strtr($text, self::SENTENCES);
    }

    /** The subtotal and VAT under the invoice's line, where the Greek body still has none. */
    private function greekTax(string $body): string
    {
        $changed = preg_replace('#^([ \t]*)- Προτιμολόγιο: \*\*\#\{\{ invoice_number \}\}\*\*\n(?![ \t]*\{% if tax %\})#mu',
            "\$0\$1{% if tax %}\n\$1- Μερικό σύνολο: **{{ subtotal }}**\n\$1- ΦΠΑ: **{{ tax }}**\n\$1{% endif %}\n", $body);

        return $changed ?? $body; // not valid UTF-8: kept as it is, never emptied (as the migration of #539)
    }
};

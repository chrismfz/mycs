<?php

namespace App\Services\Invoice;

use App\Models\Audit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceUpgrade;
use App\Services\Money\PaymentsLog;
use Closure;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * A change to a line of an open invoice made by the app (a new recurring amount, an upgrade's price), under the
 * payment locks (docs/money-trail.md §2: service, if the caller holds it, then customer, invoice, lines). An invoice
 * paid, cancelled, or with a payment on it (processing too) keeps its line: a lower price would leave a payment above
 * what is owed, a higher one would reopen what was paid (audit sweep R9 of #68). A caller that must tell staff of a line
 * kept that way passes $kept: told here, under the same locks, so no other place guesses the decision (review C1 of #695).
 * Every kept line is also in its invoice's activity (event «line_kept_at_price»: the line as billed, and what the change
 * would have made of it), whoever the caller: staff see it in the admin, not only in payments.log (owner 2026-11-07).
 * Once per line and outcome: the cron that tries the same change every day does not add a row a day.
 */
class ChangeOpenLine
{
    /**
     * $change fills the locked line (its return is ignored); saved if anything changed. $kept is told of an open invoice's
     * line kept at its price (a payment or an Ekdosi document on it) that the change would have changed: the line as
     * billed (locked), as the change would have made it (never saved), and its invoice.
     *
     * @param  Closure(InvoiceItem): mixed  $change
     * @param  (Closure(InvoiceItem, InvoiceItem, Invoice): mixed)|null  $kept
     */
    public static function handle(InvoiceItem $line, Closure $change, ?Closure $kept = null): bool
    {
        return DB::transaction(function () use ($line, $change, $kept) {
            $invoice = (new RecordPaymentService)->lockInvoice($line->invoice_id);
            if (!in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
                return false;
            }
            // Ekdosi issued its fiscal document: the line stays as issued (R2-2 of #459).
            if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
                PaymentsLog::warning('A line of an invoice with a fiscal document from Ekdosi keeps its price: the app would have changed it.', [
                    'invoice_id' => $invoice->id, 'invoice_item_id' => $line->getKey(),
                ]);
                self::tellKept($line, $change, $invoice, $kept, self::KEPT_EKDOSI);

                return false;
            }
            if ($invoice->hasPayments(lock: true)) {
                // Kept at its price: staff decide (a note, or a refund of the part over).
                PaymentsLog::warning('A line of an invoice with a payment on it keeps its price: the app would have changed it.', [
                    'invoice_id' => $invoice->id, 'invoice_item_id' => $line->getKey(),
                ]);
                self::tellKept($line, $change, $invoice, $kept, self::KEPT_PAYMENT);

                return false;
            }
            $locked = InvoiceItem::query()->whereKey($line->getKey())->lockForUpdate()->first();
            if ($locked === null || (int) $locked->invoice_id !== (int) $invoice->id) {
                return false;
            }
            $change($locked);
            if (!$locked->isDirty()) {
                return false;
            }
            $locked->save();
            $line->setRawAttributes($locked->getAttributes(), true);
            // Down to nothing to pay (a price now free): closed as paid, as a zero invoice is (2b). Its payments are
            // none (above), so nothing becomes credit. Not an upgrade's: one repriced to nothing (a cheaper target)
            // would be applied without its downgrade credit and without anyone deciding it; staff or the customer do.
            if (!InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', ServiceUpgrade::class)->exists()) {
                (new RecordPaymentService)->settle($invoice->id);
            }

            return true;
        });
    }

    public const AUDIT_EVENT = 'line_kept_at_price';

    public const KEPT_PAYMENT = 'payment';

    public const KEPT_EKDOSI = 'ekdosi_document';

    /** The kept line, locked after its invoice (docs/money-trail.md §2), and what the change would have made of it. */
    private static function tellKept(InvoiceItem $line, Closure $change, Invoice $invoice, ?Closure $kept, string $because): void
    {
        $billed = InvoiceItem::query()->whereKey($line->getKey())->lockForUpdate()->first();
        if ($billed === null || (int) $billed->invoice_id !== (int) $invoice->id) {
            return;
        }
        $wouldBe = clone $billed;
        $change($wouldBe);
        if (!$wouldBe->isDirty()) {
            return;
        }
        self::audit($billed, $wouldBe, $invoice, $because);
        if ($kept !== null) {
            $kept($billed, $wouldBe, $invoice);
        }
    }

    /** In the invoice's activity: old = the line as billed (what stays), new = what the change would have made of it. */
    private static function audit(InvoiceItem $billed, InvoiceItem $wouldBe, Invoice $invoice, string $because): void
    {
        $fields = array_keys($wouldBe->getDirty());
        $old = ['invoice_item_id' => (int) $billed->getKey()];
        $new = ['invoice_item_id' => (int) $billed->getKey(), 'kept_because' => $because];
        foreach ($fields as $field) {
            $old[$field] = self::plain($billed->getAttributes()[$field] ?? null);
            $new[$field] = self::plain($wouldBe->getAttributes()[$field] ?? null);
        }
        // Already told of this very outcome for this line: the cron's next day changes nothing.
        $last = Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->getKey())
            ->where('event', self::AUDIT_EVENT)->orderByDesc('id')->get()
            ->first(fn (Audit $audit) => (int) (((array) $audit->new_values)['invoice_item_id'] ?? 0) === (int) $billed->getKey());
        if ($last !== null && (array) $last->old_values == $old && (array) $last->new_values == $new) {
            return;
        }
        $invoice->auditEvent = self::AUDIT_EVENT;
        $invoice->isCustomEvent = true;
        $invoice->auditCustomOld = $old;
        $invoice->auditCustomNew = $new;
        event(new AuditCustom($invoice));
        $invoice->isCustomEvent = false;
        $invoice->auditCustomOld = $invoice->auditCustomNew = null;
    }

    /** A value as JSON keeps it, so the same outcome compares equal to its earlier row (a date as its string, numbers as text). */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_float($value), is_int($value) => (string) $value,
            default => $value,
        };
    }
}

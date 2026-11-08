<?php

namespace App\Services\Invoice;

use App\Models\Audit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceUpgrade;
use App\Services\Money\PaymentsLog;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Arr;
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
 * Once per line, reason and fields until the line changes (audit()): the cron's daily retry adds no row a day.
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

    /** The order's own pro rata line, kept at the old plan's price on a plan change by staff (REV3-L-ROUNDING-TAX-2). */
    public const KEPT_ORDER_LINE = 'order_line';

    /**
     * A line kept as it is by a decision of the caller's own, not of these locks (the order's line on a plan change: it
     * keeps its stub), in its invoice's activity as any kept line: $wouldBe is what the change would have made of it.
     * Each call is a row: its caller tells once per change itself (a plan change: the observer, on plan_id changed), so a
     * change back and forth again is two rows, not one taken for the other (review REV-PPN-3).
     */
    public static function recordKept(InvoiceItem $billed, InvoiceItem $wouldBe, Invoice $invoice, string $because): void
    {
        if ($wouldBe->isDirty()) {
            self::audit($billed, $wouldBe, $invoice, $because, once: false);
        }
    }

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

    /**
     * In the invoice's activity: old = the line as billed (what stays), new = what the change would have made of it, the
     * line and why it was kept. Once per line and outcome until the line itself changes; an upgrade's line once per reason,
     * fields and direction, since its price follows the days left and the cron would add a row a day (ADV-KL-1); a line edited since its
     * last row is told again (ADV-KL-2). Only where the audit is on (the cron, jobs, web; not tinker or the importer,
     * config/audit.php): a custom event skips that check of its own (ADV-KL-3). Not $once: a row each call (recordKept).
     */
    private static function audit(InvoiceItem $billed, InvoiceItem $wouldBe, Invoice $invoice, string $because, bool $once = true): void
    {
        if (!Invoice::isAuditingEnabled()) {
            return;
        }
        $fields = array_keys($wouldBe->getDirty());
        sort($fields);
        $old = [];
        $new = ['invoice_item_id' => (int) $billed->getKey(), 'line' => (string) $billed->description, 'kept_because' => $because];
        foreach ($fields as $field) {
            $old[$field] = self::plain($billed->getAttributeValue($field));
            $new[$field] = self::plain($wouldBe->getAttributeValue($field));
        }
        $last = !$once ? null : Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->getKey())
            ->where('event', self::AUDIT_EVENT)->where('new_values->invoice_item_id', (int) $billed->getKey())->orderByDesc('id')->first();
        if ($last !== null) {
            $was = (array) $last->new_values;
            $wasFields = array_values(array_diff(array_keys($was), ['invoice_item_id', 'line', 'kept_because']));
            sort($wasFields);
            $edited = $billed->updated_at !== null && $last->created_at !== null && $billed->updated_at->greaterThan($last->created_at);
            $same = ($was['kept_because'] ?? null) === $because && $wasFields === $fields && (array) $last->old_values == $old
                && self::direction((array) $last->old_values, $was) === self::direction($old, $new)
                // Only an upgrade's line drifts by itself (its price follows the days left); any other line's new figures
                // (a domain asked for 3 years after 2) are another outcome (final review).
                && ($billed->reference_type === ServiceUpgrade::class || Arr::only($was, $fields) == Arr::only($new, $fields));
            if (!$edited && $same) {
                return;
            }
        }
        $invoice->auditEvent = self::AUDIT_EVENT;
        $invoice->isCustomEvent = true;
        $invoice->auditCustomOld = $old;
        $invoice->auditCustomNew = $new;
        event(new AuditCustom($invoice));
        $invoice->isCustomEvent = false;
        $invoice->auditCustomOld = $invoice->auditCustomNew = null;
    }

    /**
     * Up or down, per amount: a change that would now lower what was raised (overpaid instead of owing more) is another
     * outcome for staff, though its fields are the same (review of the ADV-KL fix).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, int>
     */
    private static function direction(array $old, array $new): array
    {
        $signs = [];
        foreach ($old as $field => $value) {
            if (is_numeric($value) && is_numeric($new[$field] ?? null)) {
                $signs[$field] = BigDecimal::of((string) $new[$field])->compareTo(BigDecimal::of((string) $value));
            }
        }

        return $signs;
    }

    /** A value as staff read it and as JSON keeps it: a date as its day (or moment), an amount as its cast gives it. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s'),
            is_float($value), is_int($value) => (string) $value,
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}

<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff taking a recorded refund back (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-17, docs/staff-freedom.md): one
 * refund of a payment, by its journal event, under the payments' locks. The exact reverse of recording it: the payment
 * holds the money again (refunded_amount, or credited_amount for a refund to credit), the event gets its reversal, the
 * refund's note is voided, and a refunded invoice is paid again.
 *
 * - «By hand» (no money moved by MyCS): nothing else.
 * - To account credit: the credit is taken back; already spent, staff confirm that it goes negative, as for a deletion.
 * - One the gateway made (or reported, matched to a by-hand one): only with staff's word that this corrects MyCS's books
 *   and not the gateway's. The gateway is never called. Its record becomes «failed», so its late event records nothing
 *   and the payment may be deleted or edited again.
 *
 * Not undone: a chargeback (its own «won» path), a refund on an invoice with a fiscal document from Ekdosi or paid (or
 * refunded) before the cut-over, on a cancelled invoice, on a credit purchase, one that took account credit, and one whose
 * undo would pay again an invoice paid in WHMCS (its lines, never applied here, would renew their services again).
 */
class UndoRefund
{
    public const BY_HAND = 'hand';

    public const BY_GATEWAY = 'gateway';

    public const TO_CREDIT = 'credit';

    /** Ends the description of a note voided with its refund: it says why, and such a note is never made active again. */
    public const NOTE_MARK = ' [refund undone]';

    /** Whether the note was voided because its refund was undone. */
    public static function undone(AdjustmentNote $note): bool
    {
        return $note->is_refund && str_ends_with((string) $note->description, self::NOTE_MARK);
    }

    /** Set while a refund is undone: the only time a refund's note is voided and a refunded invoice is paid again. */
    private static bool $undoing = false;

    public static function undoing(): bool
    {
        return self::$undoing;
    }

    /**
     * The refunds standing on a payment, newest first: its refund events and refunds to credit not reversed. Never a
     * chargeback's.
     *
     * @return Collection<int, MoneyEvent>
     */
    public static function refunds(InvoiceTransaction $payment, bool $lock = false): Collection
    {
        return MoneyEvent::query()->where('invoice_transaction_id', $payment->getKey())->whereDoesntHave('reversal')
            ->where(fn ($query) => $query
                ->where(fn ($refund) => $refund->where('kind', MoneyEventKind::Refund->value)
                    ->where(fn ($cause) => $cause->whereNull('cause')->orWhere('cause', '!=', MoneyTrail::CAUSE_CHARGEBACK)))
                ->orWhere(fn ($credit) => $credit->where('kind', MoneyEventKind::CreditIn->value)->where('cause', MoneyTrail::CAUSE_REFUND_TO_CREDIT)))
            ->when($lock, fn ($query) => $query->lockForUpdate())->orderByDesc('id')->get();
    }

    /** How a refund went back: by hand, by the gateway, or to account credit. */
    public static function kind(MoneyEvent $refund): string
    {
        if ($refund->kind === MoneyEventKind::CreditIn) {
            return self::TO_CREDIT;
        }

        return $refund->source === MoneyTrail::SOURCE_LOCAL && self::reported($refund) === null ? self::BY_HAND : self::BY_GATEWAY;
    }

    /**
     * @throws DisplayException nothing changed
     */
    public function handle(InvoiceTransaction $payment, int $refundId, bool $allowNegativeCredit = false, bool $moneyLeftAtGateway = false): void
    {
        // Read before the transaction: lockInvoice() takes the owner from the model, so nothing is read before the locks.
        $on = Invoice::query()->findOrFail($payment->invoice_id);

        DB::transaction(function () use ($payment, $on, $refundId, $allowNegativeCredit, $moneyLeftAtGateway) {
            $invoice = (new RecordPaymentService)->lockInvoice($on);
            $invoice->transactions()->lockForUpdate()->get(['id']);
            $payment = InvoiceTransaction::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            $refund = $payment === null ? null : self::refunds($payment, lock: true)->firstWhere('id', $refundId);
            if ($payment === null || $refund === null || $payment->status !== InvoiceTransactionStatus::Succeeded) {
                throw new DisplayException(__('invoices.refund_undo.gone'));
            }
            if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
                throw new DisplayException(__('invoices.refund_undo.ekdosi'));
            }
            if ($refund->source === MoneyTrail::SOURCE_IMPORT || EkdosiDocument::issuedBeforeCutOver($invoice->id)) {
                throw new DisplayException(__('invoices.refund_undo.before_cut_over'));
            }
            if ($invoice->status === Invoice::STATUS_CANCELLED) {
                throw new DisplayException(__('invoices.refund_undo.cancelled'));
            }
            // Its refund took back the credit it bought (purchase_refunded): what it holds is counted from those events.
            if ($invoice->items()->where('reference_type', Credit::class)->exists()) {
                throw new DisplayException(__('invoices.refund_undo.credit_purchase'));
            }
            // The part of a gateway refund taken from the credit the payment left (ExtensionHelper::refundOverpaidCredit).
            if ($refund->kind === MoneyEventKind::Refund && $refund->cause !== null) {
                throw new DisplayException(__('invoices.refund_undo.took_credit'));
            }

            $cents = RecordPaymentService::toCents($refund->amount);
            $trail = new MoneyTrail;
            if ($refund->kind === MoneyEventKind::CreditIn) {
                $this->takeCreditBack($invoice, $payment, $refund, $cents, $allowNegativeCredit);
            } else {
                $reported = self::reported($refund, lock: true);
                if (($refund->source !== MoneyTrail::SOURCE_LOCAL || $reported !== null) && !$moneyLeftAtGateway) {
                    throw new DisplayException(__('invoices.refund_undo.gateway_unconfirmed'));
                }
                // Which of the gateway's records is this refund's is not known (another amount: part of it took account
                // credit, or it was matched to a by-hand refund of another amount).
                if (($reported === null && $refund->source !== MoneyTrail::SOURCE_LOCAL && self::gatewayRecords($payment)->isNotEmpty())
                    || ($reported !== null && RecordPaymentService::toCents($reported->origin === GatewayRefund::ORIGIN_HAND ? $reported->amount : $reported->recorded_amount) !== $cents)) {
                    throw new DisplayException(__('invoices.refund_undo.unmatched'));
                }
                if (RecordPaymentService::toCents($payment->refunded_amount) < $cents) {
                    throw new DisplayException(__('invoices.refund_undo.gone'));
                }
                $payment->update(['refunded_amount' => (RecordPaymentService::toCents($payment->refunded_amount) - $cents) / 100]);
                $trail->reverse($refund, MoneyTrail::CAUSE_REFUND_UNDONE);
                // Its record at the gateway: «failed» is one that moved no money, known by its id (a late event of it
                // records nothing; the payment's deletion takes it along).
                $reported?->update(['origin' => GatewayRefund::ORIGIN_FAILED, 'recorded_amount' => 0]);
            }

            self::$undoing = true;
            try {
                $note = self::noteOf($invoice, $payment, $refund);
                $note?->update(['status' => AdjustmentNoteStatus::Voided, 'description' => $note->description . self::NOTE_MARK]);
                $this->statusFollows($invoice->id);
            } finally {
                self::$undoing = false;
            }
        });
    }

    /** A refund to credit: the credit it gave is taken back, as a deletion takes back what a payment left as credit. */
    private function takeCreditBack(Invoice $invoice, InvoiceTransaction $payment, MoneyEvent $refund, int $cents, bool $allowNegativeCredit): void
    {
        $credited = RecordPaymentService::toCents($payment->credited_amount);
        $allocated = (int) InvoiceTransaction::query()->where('source_transaction_id', $payment->id)->lockForUpdate()->get()
            ->sum(fn (InvoiceTransaction $paid) => RecordPaymentService::toCents($paid->amount));
        // No longer the payment's credited part: a gateway refund took it since, or it paid other invoices as this payment's.
        if ($credited - $allocated < $cents || $invoice->client_id === null) {
            throw new DisplayException(__('invoices.refund_undo.credit_moved'));
        }
        $credit = Credit::query()->where('client_id', $invoice->client_id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first()
            ?? $invoice->client->credits()->create(['currency_code' => $invoice->currency_code, 'amount' => 0]);
        $balance = CreditLedger::cents($credit) - $cents;
        if ($balance < 0 && !$allowNegativeCredit) {
            throw new DisplayException(__('invoices.refund_undo.credit_spent'));
        }
        $payment->update(['credited_amount' => ($credited - $cents) / 100]);
        $credit->amount = $balance / 100;
        (new MoneyTrail)->reverse($refund, MoneyTrail::CAUSE_REFUND_UNDONE, $credit);
        $credit->save();
    }

    /**
     * The invoice after the undo, read under the locks: refunded, it holds money again and is paid (or owes, when its
     * total changed since); open and covered again, paid. A paid one stays paid and shows its balance.
     */
    private function statusFollows(int $invoiceId): void
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail()->withLockedBalance();
        $remaining = RecordPaymentService::toCents($invoice->remaining);
        if ($invoice->status === Invoice::STATUS_REFUNDED && $remaining < 0) {
            throw new DisplayException(__('invoices.refund_undo.over'));
        }
        $paidAgain = $remaining === 0 && ($invoice->status === Invoice::STATUS_REFUNDED
            || ($invoice->status === Invoice::STATUS_UNPAID && $invoice->items->isNotEmpty()));
        // Paid in WHMCS, its lines never applied here: paid again they would renew their services a second time.
        if ($paidAgain && EditPayment::paidAgainWouldRenew($invoice)) {
            throw new DisplayException(__('invoices.refund_undo.would_renew'));
        }
        if ($paidAgain || $invoice->status === Invoice::STATUS_REFUNDED) {
            $invoice->status = $paidAgain ? Invoice::STATUS_PAID : Invoice::STATUS_UNPAID;
            $invoice->save();
        }
    }

    /**
     * The note a refund wrote on its (then paid) invoice: a refund's note of its payment, for its amount, written with
     * it. None when the invoice was open at the time (its balance grew back instead).
     */
    private static function noteOf(Invoice $invoice, InvoiceTransaction $payment, MoneyEvent $refund): ?AdjustmentNote
    {
        $text = ($refund->kind === MoneyEventKind::CreditIn ? 'Refund to account credit for transaction #' : 'Refund for transaction #') . $payment->id;

        return $invoice->adjustmentNotes()->where('is_refund', true)->where('status', AdjustmentNoteStatus::Active->value)->lockForUpdate()->get()
            ->filter(fn (AdjustmentNote $note) => RecordPaymentService::toCents($note->amount) === -RecordPaymentService::toCents($refund->amount)
                && preg_match('/^' . preg_quote($text, '/') . '(\D|$)/', (string) $note->description) === 1
                && $note->created_at !== null && abs($note->created_at->diffInSeconds($refund->occurred_at)) <= 60)
            ->sortBy(fn (AdjustmentNote $note) => abs($note->created_at->diffInSeconds($refund->occurred_at)))->first();
    }

    /**
     * The gateway's own record of this refund (gateway_refunds), when it has one: MyCS's or the gateway's for a refund
     * through it; for one recorded by hand, the gateway refund that was matched to it (ExtensionHelper), when the by-hand
     * refunds left would no longer cover what was matched.
     */
    private static function reported(MoneyEvent $refund, bool $lock = false): ?GatewayRefund
    {
        $cents = RecordPaymentService::toCents($refund->amount);
        $byHand = $refund->source === MoneyTrail::SOURCE_LOCAL;
        $rows = GatewayRefund::query()->where('invoice_transaction_id', $refund->invoice_transaction_id)
            ->whereIn('origin', $byHand ? [GatewayRefund::ORIGIN_HAND] : [GatewayRefund::ORIGIN_MYCS, GatewayRefund::ORIGIN_GATEWAY])
            ->when($lock, fn ($query) => $query->lockForUpdate())->orderByDesc('id')->get();
        if ($byHand) {
            $standing = (int) MoneyEvent::query()->where('invoice_transaction_id', $refund->invoice_transaction_id)->where('kind', MoneyEventKind::Refund->value)
                ->where('source', MoneyTrail::SOURCE_LOCAL)->whereNull('cause')->whereDoesntHave('reversal')->get()
                ->sum(fn (MoneyEvent $event) => RecordPaymentService::toCents($event->amount));
            $matched = (int) $rows->sum(fn (GatewayRefund $row) => RecordPaymentService::toCents($row->amount));
            if ($standing - $cents >= $matched) {
                return null; // the other by-hand refunds still cover what the gateway's were matched to
            }

            return $rows->first(fn (GatewayRefund $row) => RecordPaymentService::toCents($row->amount) === $cents) ?? $rows->first();
        }

        return $rows->sortBy(fn (GatewayRefund $row) => $row->created_at === null ? PHP_INT_MAX : abs($row->created_at->diffInSeconds($refund->occurred_at)))
            ->first(fn (GatewayRefund $row) => RecordPaymentService::toCents($row->recorded_amount) === $cents);
    }

    /** @return Collection<int, GatewayRefund> the refunds its gateway reported on the payment that recorded money */
    private static function gatewayRecords(InvoiceTransaction $payment): Collection
    {
        return GatewayRefund::query()->where('invoice_transaction_id', $payment->id)
            ->whereIn('origin', [GatewayRefund::ORIGIN_MYCS, GatewayRefund::ORIGIN_GATEWAY])->where('recorded_amount', '>', 0)->get();
    }
}

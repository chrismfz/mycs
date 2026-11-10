<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Staff correcting a recorded payment, as in WHMCS (owner 2026-11-10, sweep 7 TIGHT-INVOICES-PAYMENTS-15,
 * docs/staff-freedom.md), under the payments' locks (customer, invoice, payment).
 *
 * - What moves no money (its date, gateway/method, transaction id, fee) is changed on the row, in its audit; the
 *   invoice's paid date follows the payment's when it came from it; a transaction id stays one per gateway account (the
 *   webhook's replay guard).
 * - Its amount, or the invoice it is on (another of the same customer and currency): the payment is deleted and
 *   recorded again, in one transaction, by RecordPaymentService::deleteTransaction and ::recordByStaff. No money rule is
 *   written here: whatever a deletion or «Add payment» refuses, asks or does, this does.
 *
 * Refused here: a payment that did not arrive, one made with account credit (delete it: the credit goes back), the
 * amount, id and gateway of a payment its gateway recorded (it is what the gateway took), and the amount, invoice and
 * date of a payment on an invoice paid before the cut-over (EkdosiDocument::issuedBeforeCutOver).
 */
class EditPayment
{
    /**
     * @param  array{amount?: mixed, invoice_id?: mixed, gateway_id?: mixed, transaction_id?: mixed, fee?: mixed, paid_at?: ?\DateTimeInterface}  $changes  only the keys given change
     *
     * @throws DisplayException nothing changed
     */
    public function handle(InvoiceTransaction $payment, array $changes, bool $allowNegativeCredit = false): InvoiceTransaction
    {
        // Read before the transaction: lockInvoice() takes the owner from the model, so nothing is read before the locks.
        $on = Invoice::query()->findOrFail($payment->invoice_id);

        return DB::transaction(function () use ($payment, $on, $changes, $allowNegativeCredit) {
            $payments = new RecordPaymentService;
            $invoice = $payments->lockInvoice($on);
            $payment = InvoiceTransaction::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if ($payment === null || (int) $payment->invoice_id !== (int) $invoice->id) {
                throw new DisplayException(__('invoices.payment_edit.gone'));
            }
            if ($payment->status !== InvoiceTransactionStatus::Succeeded) {
                throw new DisplayException(__('invoices.payment_edit.not_succeeded'));
            }
            if ($payment->is_credit_transaction || $payment->source_transaction_id !== null) {
                throw new DisplayException(__('invoices.payment_edit.credit_payment'));
            }

            $was = RecordPaymentService::toCents($payment->amount);
            $cents = array_key_exists('amount', $changes) ? RecordPaymentService::toCents($changes['amount']) : $was;
            $targetId = filled($changes['invoice_id'] ?? null) ? (int) $changes['invoice_id'] : (int) $invoice->id;
            $gatewayId = array_key_exists('gateway_id', $changes) ? (filled($changes['gateway_id']) ? (int) $changes['gateway_id'] : null) : $payment->gateway_id;
            $reference = array_key_exists('transaction_id', $changes) ? (trim((string) $changes['transaction_id']) ?: null) : $payment->transaction_id;
            $fee = array_key_exists('fee', $changes) ? (filled($changes['fee']) ? $changes['fee'] : null) : $payment->fee;
            $paidAt = isset($changes['paid_at']) ? Carbon::instance($changes['paid_at']) : null;
            $dated = $paidAt !== null && ($payment->created_at === null || !$paidAt->equalTo($payment->created_at));
            $renamed = $reference !== $payment->transaction_id || $gatewayId !== ($payment->gateway_id === null ? null : (int) $payment->gateway_id);
            $moves = $cents !== $was || $targetId !== (int) $invoice->id;

            if ($cents <= 0) {
                throw new DisplayException(__('The amount must be positive.'));
            }
            if ($fee !== null && (!is_numeric($fee) || (float) $fee < 0)) {
                throw new DisplayException(__('invoices.payment_edit.fee'));
            }
            if ($dated && $paidAt->isFuture()) {
                throw new DisplayException(__('invoices.payment_edit.future'));
            }
            $byGateway = self::recordedByGateway($payment);
            if ($byGateway && $cents !== $was) {
                throw new DisplayException(__('invoices.payment_edit.gateway_amount'));
            }
            if ($byGateway && $renamed) {
                throw new DisplayException(__('invoices.payment_edit.gateway_identity'));
            }
            // Paid in WHMCS, its document issued there. A new amount or invoice would write MyCS money events on it (and
            // open it again); a new date would move its paid date, by which Ekdosi's feed takes invoices (`since`).
            if (($moves || $dated) && EkdosiDocument::issuedBeforeCutOver($invoice->id)) {
                throw new DisplayException(__('invoices.payment_edit.before_cut_over'));
            }
            // Paid in WHMCS and touched here since (no longer «all the importer's»): open and paid again, its lines,
            // never applied here, would renew their services a second time.
            if ($moves && self::paidAgainWouldRenew($invoice)) {
                throw new DisplayException(__('invoices.payment_edit.before_cut_over'));
            }

            if ($moves) {
                $target = $invoice;
                if ($targetId !== (int) $invoice->id) {
                    $target = Invoice::query()->whereKey($targetId)->lockForUpdate()->first();
                    if ($target === null || $invoice->client_id === null || (int) $target->client_id !== (int) $invoice->client_id
                        || strtoupper((string) $target->currency_code) !== strtoupper((string) $invoice->currency_code)) {
                        throw new DisplayException(__('invoices.payment_edit.other_customer'));
                    }
                    if (EkdosiDocument::issuedBeforeCutOver($target->id)) {
                        throw new DisplayException(__('invoices.payment_edit.target_before_cut_over'));
                    }
                }
                $gateway = $gatewayId === null ? null : Gateway::query()->find($gatewayId);
                $at = $paidAt ?? $payment->created_at;
                // Delete + record again: every rule of the two (credit taken back or refused, the tick, a real refund on
                // it, the invoice open again, the target paid, an id already recorded) is theirs.
                $payments->deleteTransaction($payment, $allowNegativeCredit);

                return $payments->recordByStaff($target, $gateway, number_format($cents / 100, 2, '.', ''), $reference, $fee, $at, recordedByGateway: $byGateway);
            }

            if ($renamed && $reference !== null && ($twin = self::twin($payment, $invoice, $reference, $gatewayId)) !== null) {
                throw new DisplayException(__('invoices.payment_edit.duplicate_id', ['invoice' => $twin->invoice_id]));
            }
            // The invoice's paid date is its last payment's (InvoiceObserver): it follows when it still is.
            $last = fn () => $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded->value)->max('created_at');
            $follows = $dated && $invoice->status === Invoice::STATUS_PAID && $invoice->paid_at !== null
                && ($before = $last()) !== null && $invoice->paid_at->equalTo(Carbon::parse($before));

            $payment->fill(['gateway_id' => $gatewayId, 'transaction_id' => $reference, 'fee' => $fee]);
            if ($dated) {
                $from = $payment->created_at;
                $payment->created_at = $paidAt;
            }
            $payment->save(); // audited: who, before → after
            if ($dated) {
                // Timestamps are not in the model's audit (config/audit.php): the date's change is written to it by name.
                $payment->auditEvent = 'payment_date_changed';
                $payment->isCustomEvent = true;
                $payment->auditCustomOld = ['paid_at' => $from?->toDateTimeString()];
                $payment->auditCustomNew = ['paid_at' => $paidAt->toDateTimeString()];
                event(new AuditCustom($payment));
            }
            if ($follows) {
                $invoice->paid_at = Carbon::parse($last())->toImmutable();
                $invoice->save();
            }

            return $payment;
        });
    }

    /**
     * Whether its gateway recorded it (a webhook, a charge): its journal entry holds the provider's id, which an entry
     * staff typed never does (MoneyTrail). Its amount, id and gateway are what the gateway says.
     */
    public static function recordedByGateway(InvoiceTransaction $payment): bool
    {
        return MoneyEvent::query()->where('invoice_transaction_id', $payment->id)->where('kind', MoneyEventKind::Payment->value)
            ->whereNotNull('external_reference')->whereDoesntHave('reversal')->exists();
    }

    /**
     * An invoice paid in WHMCS comes with lines that point at their services and were never applied here (the period was
     * given there). Paid AGAIN here (after a deletion, an undone refund), those lines would be applied: a second period
     * for the same money. Whether it is one: it was paid once (paid_at is never cleared), with money of the importer's on
     * it, and has a line of something not applied. One imported unpaid and first paid here is not: its payment applies it.
     */
    public static function paidAgainWouldRenew(Invoice $invoice): bool
    {
        return $invoice->paid_at !== null && MoneyEvent::query()->where('invoice_id', $invoice->id)->where('kind', MoneyEventKind::Payment->value)
            ->where('source', MoneyTrail::SOURCE_IMPORT)->exists()
            && $invoice->items()->whereNotNull('reference_type')->whereNull('applied_at')->exists();
    }

    /**
     * Another payment the new id would be taken for: as RecordPaymentService matches a replay (this customer's, on the
     * same gateway account or with no gateway on either side), and on any customer the same account's id or one a
     * gateway recorded.
     */
    private static function twin(InvoiceTransaction $payment, Invoice $invoice, string $reference, ?int $gatewayId): ?InvoiceTransaction
    {
        $extension = $gatewayId === null ? null : Gateway::query()->whereKey($gatewayId)->value('extension');
        $sameAccount = $gatewayId === null ? [] : Gateway::query()
            ->whereIn('extension', ExtensionHelper::accountExtensions((string) $extension))->pluck('id')->push($gatewayId)->unique()->all();

        return InvoiceTransaction::query()->whereKeyNot($payment->getKey())->where('transaction_id', $reference)
            ->where(fn ($match) => $match
                ->where(fn ($own) => $own->whereHas('invoice', fn ($query) => $query->where('client_id', $invoice->client_id))
                    ->when($gatewayId !== null, fn ($query) => $query->where(
                        fn ($query) => $query->whereIn('gateway_id', $sameAccount)->orWhereNull('gateway_id'))))
                ->when($gatewayId !== null, fn ($query) => $query->orWhereIn('gateway_id', $sameAccount))
                // An id a gateway recorded is the provider's, whoever types it again (MoneyCheck: duplicate_gateway_id).
                ->orWhereExists(fn ($journal) => $journal->from('money_events')->whereColumn('money_events.invoice_transaction_id', 'invoice_transactions.id')
                    ->where('money_events.kind', MoneyEventKind::Payment->value)->where('money_events.external_reference', $reference)))
            ->lockForUpdate()->first();
    }
}

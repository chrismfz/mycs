<?php

namespace App\Services\Money;

use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Writes the money journal (docs/money-trail.md §1, MT-1a). Called only by the code that moves the money, inside
 * its database transaction and under its locks: if the movement rolls back, so does its event.
 *
 * Meaning of the kinds, so the journal adds up:
 * - payment: money onto an invoice from a source (a gateway key, `manual` for staff/API, `credit`);
 * - credit_in / credit_out: the customer's account credit up / down (cause says why);
 * - refund: money back to the customer from a payment;
 * - reversal: undoes one event (a deleted transaction).
 * So an invoice holds Σ payment − Σ credit_in (overpayment) − Σ refund of its transactions, and the credit balance
 * is Σ credit_in − Σ credit_out (reversals counted the other way).
 *
 * No personal data is written: ids, amounts, the provider's ids, and who acted (by id) only (§9). The external
 * reference is kept only when a gateway recorded the payment: an id staff type by hand can hold a payer's name.
 */
class MoneyTrail
{
    public const SOURCE_CREDIT = 'credit';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_SYSTEM = 'system';

    public const CAUSE_OVERPAYMENT = 'overpayment';

    public const CAUSE_CREDIT_PAYMENT = 'credit_payment';

    public const CAUSE_PURCHASE = 'purchase';

    public const CAUSE_PURCHASE_REFUNDED = 'purchase_refunded';

    public const CAUSE_TRANSACTION_DELETED = 'transaction_deleted';

    public const CAUSE_OPENING_BALANCE = 'opening_balance';

    /** The source of a payment: the gateway's key, else credit or a manual entry. */
    public static function sourceFor(?Gateway $gateway, bool $fromCredit = false): string
    {
        if ($fromCredit) {
            return self::SOURCE_CREDIT;
        }

        return $gateway?->extension ? strtolower($gateway->extension) : self::SOURCE_MANUAL;
    }

    /**
     * Money onto the invoice: the whole transaction amount (what it could not take is a separate credit_in).
     * $reference: the provider's id, only when the gateway itself recorded the payment (§9).
     */
    public function payment(Invoice $invoice, InvoiceTransaction $transaction, string $source, ?string $group = null, ?string $reference = null): MoneyEvent
    {
        return $this->write([
            'kind' => MoneyEventKind::Payment,
            'amount' => $transaction->amount,
            'currency_code' => $invoice->currency_code,
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'invoice_transaction_id' => $transaction->id,
            'source' => $source,
            'external_reference' => $reference,
            'group_reference' => $group,
        ]);
    }

    /** Credit went up by $cents; $credit is the saved row (its balance is the balance after). */
    public function creditIn(Credit $credit, int $cents, string $cause, string $source, ?Invoice $invoice = null, ?InvoiceTransaction $transaction = null): MoneyEvent
    {
        return $this->creditMove(MoneyEventKind::CreditIn, $credit, $cents, $cause, $source, $invoice, $transaction);
    }

    /** Credit went down by $cents; $credit is the saved row (its balance is the balance after). */
    public function creditOut(Credit $credit, int $cents, string $cause, string $source, ?Invoice $invoice = null, ?InvoiceTransaction $transaction = null, ?string $group = null): MoneyEvent
    {
        return $this->creditMove(MoneyEventKind::CreditOut, $credit, $cents, $cause, $source, $invoice, $transaction, $group);
    }

    /** Money back to the customer from $transaction (through its gateway, or recorded locally). */
    public function refund(InvoiceTransaction $transaction, Invoice $invoice, int $cents, string $source): MoneyEvent
    {
        return $this->write([
            'kind' => MoneyEventKind::Refund,
            'amount' => $cents / 100,
            'currency_code' => $invoice->currency_code,
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'invoice_transaction_id' => $transaction->id,
            'source' => $source,
            'external_reference' => self::externalReference($transaction),
        ]);
    }

    /**
     * Undo $event: the same amount the other way. $credit is the saved credit row when the event moved credit.
     * Each event is reversed at most once (a unique key enforces it).
     */
    public function reverse(MoneyEvent $event, ?string $cause = null, ?Credit $credit = null): MoneyEvent
    {
        return $this->write([
            'kind' => MoneyEventKind::Reversal,
            'amount' => $event->amount,
            'currency_code' => $event->currency_code,
            'user_id' => $event->user_id,
            'invoice_id' => $event->invoice_id,
            'invoice_transaction_id' => $event->invoice_transaction_id,
            'credit_balance_after' => $credit?->amount,
            'source' => $event->source,
            'cause' => $cause,
            'external_reference' => $event->external_reference,
            'group_reference' => $event->group_reference,
            'reverses_id' => $event->id,
        ]);
    }

    private function creditMove(MoneyEventKind $kind, Credit $credit, int $cents, string $cause, string $source, ?Invoice $invoice, ?InvoiceTransaction $transaction, ?string $group = null): MoneyEvent
    {
        return $this->write([
            'kind' => $kind,
            'amount' => $cents / 100,
            'currency_code' => $credit->currency_code,
            'user_id' => $credit->user_id,
            'invoice_id' => $invoice?->id,
            'invoice_transaction_id' => $transaction?->id,
            'credit_balance_after' => $credit->amount,
            'source' => $source,
            'cause' => $cause,
            'external_reference' => $transaction === null ? null : self::externalReference($transaction),
            'group_reference' => $group,
        ]);
    }

    /**
     * The provider's id, as the transaction's payment event holds it: set only when a gateway recorded the payment,
     * never what staff typed in the admin form (it can name the payer).
     */
    private static function externalReference(InvoiceTransaction $transaction): ?string
    {
        return MoneyEvent::query()->where('invoice_transaction_id', $transaction->id)
            ->where('kind', MoneyEventKind::Payment->value)->whereNotNull('external_reference')
            ->value('external_reference');
    }

    /** @param  array<string, mixed>  $values */
    private function write(array $values): MoneyEvent
    {
        if (RecordPaymentService::toCents($values['amount']) <= 0) {
            throw new LogicException('A money event has a positive amount.');
        }
        $actor = Auth::user();

        return MoneyEvent::query()->create($values + [
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'occurred_at' => now(),
        ]);
    }
}

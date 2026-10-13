<?php

namespace App\Services\Money;

use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Account credit goes up or down only here (docs/money-trail.md §2, MT-1b): the row is locked after its customer
 * (the payments' lock order: customer → invoice → transaction → lines → credit), changed, and the move journaled in
 * the same transaction. The credits table is the cache of Σ credit_in − Σ credit_out (mycs:credits-check).
 *
 * The one exception is RecordPaymentService::deleteTransaction: it undoes a payment's own events step by step
 * (reversals) under the same locks.
 */
class CreditLedger
{
    /** The largest balance (either way) in cents: 9,999,999,999.99, the API's max too. */
    public const MAX_CENTS = 999_999_999_999;

    /** The customer's credit row in this currency, locked after the customer; null when there is none. */
    public function lock(int $userId, string $currency): ?Credit
    {
        User::query()->whereKey($userId)->lockForUpdate()->first();

        return Credit::query()->where('user_id', $userId)->where('currency_code', $currency)->lockForUpdate()->first();
    }

    /**
     * Credit up by $cents (> 0). Returns the saved row.
     */
    public function add(int $userId, string $currency, int $cents, string $cause, string $source, ?Invoice $invoice = null, ?InvoiceTransaction $transaction = null, ?string $note = null, ?string $group = null): Credit
    {
        return $this->move(1, $userId, $currency, $cents, $cause, $source, $invoice, $transaction, $note, $group, false);
    }

    /**
     * Credit down by $cents (> 0). Refused below zero unless $mayGoNegative (the customer then owes it, shown in the
     * admin and in the daily check). Returns the saved row.
     *
     * @throws DisplayException
     */
    public function remove(int $userId, string $currency, int $cents, string $cause, string $source, ?Invoice $invoice = null, ?InvoiceTransaction $transaction = null, ?string $note = null, ?string $group = null, bool $mayGoNegative = false): Credit
    {
        return $this->move(-1, $userId, $currency, $cents, $cause, $source, $invoice, $transaction, $note, $group, $mayGoNegative);
    }

    /**
     * Set the balance to $targetCents (≥ 0): the difference, computed under the lock, is journaled as one move.
     * Returns the saved row (null when there was none and the target is zero).
     */
    public function setBalance(int $userId, string $currency, int $targetCents, string $cause, string $source, ?string $note = null): ?Credit
    {
        if ($targetCents < 0) {
            throw new LogicException('A balance is set to zero or more.');
        }
        self::requireReason($cause, $note);

        return DB::transaction(function () use ($userId, $currency, $targetCents, $cause, $source, $note) {
            $credit = $this->lock($userId, $currency);
            $difference = $targetCents - ($credit === null ? 0 : self::cents($credit));
            if ($difference === 0) {
                return $credit;
            }

            return $difference > 0
                ? $this->add($userId, $currency, $difference, $cause, $source, note: $note)
                : $this->remove($userId, $currency, -$difference, $cause, $source, note: $note);
        });
    }

    /** Staff adjust credit only with a reason (decision 2): the form and the API ask for it, the ledger makes sure. */
    private static function requireReason(string $cause, ?string $note): void
    {
        if ($cause === MoneyTrail::CAUSE_STAFF_ADJUSTMENT && trim((string) $note) === '') {
            throw new LogicException('A staff credit adjustment needs a reason.');
        }
    }

    /** The balance in cents (no lock: for display and checks). */
    public function balanceCents(int $userId, string $currency): int
    {
        $credit = Credit::query()->where('user_id', $userId)->where('currency_code', $currency)->first();

        return $credit === null ? 0 : self::cents($credit);
    }

    /**
     * A stored balance in cents. Unlike RecordPaymentService::toCents, never 0 for an amount it cannot represent:
     * a balance past MAX_CENTS (only from outside the ledger) stops the caller instead of being read as zero.
     */
    public static function cents(Credit $credit): int
    {
        $cents = (float) $credit->amount * 100;
        if (abs($cents) > self::MAX_CENTS + 0.5) {
            throw new LogicException("The credit balance of row {$credit->getKey()} is out of range.");
        }

        return (int) round($cents);
    }

    /** Whether a stored balance is exactly zero (any size: for checks that must not read a huge balance as zero). */
    public static function isZero(Credit $credit): bool
    {
        return (float) $credit->amount === 0.0;
    }

    private function move(int $sign, int $userId, string $currency, int $cents, string $cause, string $source, ?Invoice $invoice, ?InvoiceTransaction $transaction, ?string $note, ?string $group, bool $mayGoNegative): Credit
    {
        if ($cents <= 0) {
            throw new LogicException('A credit move has a positive amount in cents.');
        }
        self::requireReason($cause, $note);

        return DB::transaction(function () use ($sign, $userId, $currency, $cents, $cause, $source, $invoice, $transaction, $note, $group, $mayGoNegative) {
            $credit = $this->lock($userId, $currency)
                ?? Credit::query()->create(['user_id' => $userId, 'currency_code' => $currency, 'amount' => 0]);
            $before = self::cents($credit);
            $balance = $before + $sign * $cents;
            // Adding is always allowed: on a negative balance (decision 1) it repays part of what the customer owes.
            if ($sign < 0 && $balance < 0 && !$mayGoNegative) {
                throw new DisplayException(__('Not enough account credit: the balance is :balance.', [
                    'balance' => number_format($before / 100, 2, '.', '') . ' ' . $currency,
                ]));
            }
            if (abs($balance) > self::MAX_CENTS) {
                throw new DisplayException(__('Account credit cannot go past :max.', [
                    'max' => number_format(self::MAX_CENTS / 100, 2, '.', '') . ' ' . $currency,
                ]));
            }
            $credit->amount = $balance / 100;
            $credit->save();

            $trail = new MoneyTrail;
            $sign > 0
                ? $trail->creditIn($credit, $cents, $cause, $source, $invoice, $transaction, $group, $note)
                : $trail->creditOut($credit, $cents, $cause, $source, $invoice, $transaction, $group, $note);

            return $credit;
        });
    }
}

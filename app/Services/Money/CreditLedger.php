<?php

namespace App\Services\Money;

use App\Exceptions\DisplayException;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
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

        return DB::transaction(function () use ($userId, $currency, $targetCents, $cause, $source, $note) {
            $credit = $this->lock($userId, $currency);
            $difference = $targetCents - RecordPaymentService::toCents($credit?->amount ?? 0);
            if ($difference === 0) {
                return $credit;
            }

            return $difference > 0
                ? $this->add($userId, $currency, $difference, $cause, $source, note: $note)
                : $this->remove($userId, $currency, -$difference, $cause, $source, note: $note);
        });
    }

    /** The balance in cents (no lock: for display and checks). */
    public function balanceCents(int $userId, string $currency): int
    {
        return RecordPaymentService::toCents(Credit::query()->where('user_id', $userId)->where('currency_code', $currency)->value('amount') ?? 0);
    }

    private function move(int $sign, int $userId, string $currency, int $cents, string $cause, string $source, ?Invoice $invoice, ?InvoiceTransaction $transaction, ?string $note, ?string $group, bool $mayGoNegative): Credit
    {
        if ($cents <= 0) {
            throw new LogicException('A credit move has a positive amount in cents.');
        }

        return DB::transaction(function () use ($sign, $userId, $currency, $cents, $cause, $source, $invoice, $transaction, $note, $group, $mayGoNegative) {
            $credit = $this->lock($userId, $currency)
                ?? Credit::query()->create(['user_id' => $userId, 'currency_code' => $currency, 'amount' => 0]);
            $balance = RecordPaymentService::toCents($credit->amount) + $sign * $cents;
            if ($balance < 0 && !$mayGoNegative) {
                throw new DisplayException(__('Not enough account credit: the balance is :balance.', [
                    'balance' => number_format(RecordPaymentService::toCents($credit->amount) / 100, 2, '.', '') . ' ' . $currency,
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

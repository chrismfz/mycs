<?php

namespace App\Services\Money;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Account credit pays open invoices on its own, also in part (docs/money-trail.md, decision 5, MT-1e): when an invoice
 * is made (renewals), when credit comes in (an overpayment) and once a day before suspensions, so a customer with
 * credit is not suspended while it sits unused. Oldest due first.
 *
 * Not on: a credit purchase (add funds); an invoice with a payment on its way (processing); an invoice of a service
 * with a gateway subscription (Stripe/PayPal charge the whole amount: credit first would be paid twice), except its
 * own renewal when the credit covers it all (a subscription sale that became credit pays it, as before MT-1e); an
 * invoice of a service the customer asked to cancel; an upgrade invoice (the cron reprices it); an invoice staff took
 * a credit payment off (deleted it: the sweep would put it back); a customer who opted out (users.credit_auto_apply).
 * Read under the payment locks, as the payment itself.
 *
 * Credit on an invoice the cron cancels goes back to the customer (giveBack), so partial credit is never lost.
 */
class CreditAutoApply
{
    public static function enabled(): bool
    {
        return (bool) config('settings.credits_enabled') && (bool) config('settings.credits_auto_use', true);
    }

    /**
     * Credit onto one invoice, as much as it covers. Null when nothing was paid. Without $notify no «credit applied»
     * message (a billing agreement charges the rest right after).
     */
    public function toInvoice(Invoice|int $invoice, bool $notify = true): ?InvoiceTransaction
    {
        if (!self::enabled()) {
            return null;
        }
        $transaction = (new RecordPaymentService)->payWithCredit($invoice, eligible: fn (Invoice $locked): bool => self::eligible($locked));
        if ($transaction !== null && $notify) {
            $this->notify($transaction);
        }

        return $transaction;
    }

    /**
     * The renewal of a service with a gateway subscription: only when the credit covers it all, as before MT-1e. A
     * subscription sale with no open invoice to land on becomes credit, and that credit is what pays the renewal.
     */
    public function toSubscriptionRenewal(Invoice $invoice): ?InvoiceTransaction
    {
        if (!self::enabled()) {
            return null;
        }

        return (new RecordPaymentService)->payWithCredit($invoice, onlyIfCovers: true,
            eligible: fn (Invoice $locked): bool => self::eligible($locked, subscriptionRenewal: true));
    }

    /** A customer's open invoices in a currency, oldest due first, while the credit lasts. Returns how many it paid into. */
    public function forCustomer(int $userId, string $currency): int
    {
        if (!self::enabled() || !self::customerAllows($userId)) {
            return 0;
        }
        $ledger = new CreditLedger;
        $paid = 0;
        $invoices = Invoice::query()->where('user_id', $userId)->where('currency_code', $currency)
            ->where('status', Invoice::STATUS_PENDING)->orderBy('due_at')->orderBy('id')->pluck('id');
        foreach ($invoices as $invoiceId) {
            if ($ledger->balanceCents($userId, $currency) <= 0) {
                break;
            }
            if ($this->toInvoice($invoiceId) !== null) {
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * The daily pass (cron, before renewals and suspensions): every customer with credit, not opted out, and an open
     * invoice in that currency. A customer's failure is handed to $failed and does not stop the others.
     *
     * @param  (\Closure(int, \Throwable): void)|null  $failed
     */
    public function sweep(?\Closure $failed = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $paid = 0;
        Credit::query()->where('amount', '>', 0)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.user_id', 'credits.user_id')->whereColumn('invoices.currency_code', 'credits.currency_code')
                ->where('invoices.status', Invoice::STATUS_PENDING))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('users')
                ->whereColumn('users.id', 'credits.user_id')->where('users.credit_auto_apply', true))
            ->orderBy('id')->get(['user_id', 'currency_code'])
            ->each(function (Credit $credit) use (&$paid, $failed) {
                try {
                    $paid += $this->forCustomer((int) $credit->user_id, $credit->currency_code);
                } catch (\Throwable $e) {
                    report($e);
                    if ($failed !== null) {
                        $failed((int) $credit->user_id, $e);
                    }
                }
            });

        return $paid;
    }

    /**
     * Credit payments on an invoice that is about to be cancelled go back to the customer's credit (journaled as a
     * deletion), so credit applied in part is not lost with the invoice. Returns how many it gave back.
     */
    public function giveBack(Invoice $invoice): int
    {
        $payments = $invoice->transactions()->where('is_credit_transaction', true)->get();
        foreach ($payments as $payment) {
            (new RecordPaymentService)->deleteTransaction($payment, cause: MoneyTrail::CAUSE_CANCELLATION);
        }

        return $payments->count();
    }

    /**
     * Whether credit may pay this (locked) invoice on its own. For a subscription's own renewal ($subscriptionRenewal,
     * paid only in full) the subscription and the opt-out do not stop it: that credit is the subscription's payment.
     */
    public static function eligible(Invoice $invoice, bool $subscriptionRenewal = false): bool
    {
        if ($invoice->status !== Invoice::STATUS_PENDING || $invoice->user_id === null) {
            return false;
        }
        // The customer's row is locked by the payment already (lockInvoice): this reads it as of now.
        if (!$subscriptionRenewal && !self::customerAllows((int) $invoice->user_id, lock: true)) {
            return false;
        }
        $items = $invoice->items()->lockForUpdate()->get(['id', 'reference_type', 'reference_id']);
        if ($items->contains('reference_type', Credit::class) || $items->contains('reference_type', ServiceUpgrade::class)) {
            return false;
        }
        if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Processing->value)->lockForUpdate()->exists()) {
            return false;
        }
        // Staff took a credit payment off this invoice: the credit stays where they put it.
        if (MoneyEvent::query()->where('invoice_id', $invoice->id)->where('kind', MoneyEventKind::Reversal->value)
            ->where('cause', MoneyTrail::CAUSE_TRANSACTION_DELETED)->where('source', MoneyTrail::SOURCE_CREDIT)->exists()) {
            return false;
        }
        $services = $items->where('reference_type', Service::class)->pluck('reference_id')->filter();
        if ($services->isEmpty()) {
            return true;
        }
        // Not locked: a service row is locked before the customer (billRenewal), never after. A subscription or a
        // cancellation set at this very moment is seen by the next pass; the gateway's charge stays the safeguard.
        if (ServiceCancellation::query()->whereIn('service_id', $services)->exists()) {
            return false;
        }
        // A service already ended (terminated, an unpaid order cancelled): an invoice of it left open for staff (it
        // holds gateway money, CancelOpenInvoices) is not something credit should pay.
        if (Service::query()->whereIn('id', $services)->where('status', Service::STATUS_CANCELLED)->exists()) {
            return false;
        }

        return $subscriptionRenewal || !Service::query()->whereIn('id', $services)->whereNotNull('subscription_id')
            ->where('subscription_id', '!=', '')->exists();
    }

    private static function customerAllows(int $userId, bool $lock = false): bool
    {
        $query = User::query()->whereKey($userId);

        return (bool) (($lock ? $query->lockForUpdate() : $query)->value('credit_auto_apply') ?? true);
    }

    /**
     * The customer is told what credit paid and what is left, once the payment commits. A fully paid invoice already
     * sends «Invoice paid».
     */
    private function notify(InvoiceTransaction $transaction): void
    {
        DB::afterCommit(function () use ($transaction) {
            $invoice = $transaction->invoice()->first();
            if ($invoice === null || $invoice->user === null || $invoice->status === Invoice::STATUS_PAID) {
                return;
            }
            try {
                NotificationHelper::creditAppliedNotification($invoice->user, $invoice, $transaction);
            } catch (\Throwable $e) {
                report($e); // a notification never undoes the payment
            }
        });
    }
}

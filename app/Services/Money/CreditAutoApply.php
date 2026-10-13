<?php

namespace App\Services\Money;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\NotificationHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Account credit pays open invoices on its own, also in part (docs/money-trail.md, decision 5, MT-1e): when an invoice
 * is made (renewals), when credit comes in (an overpayment) and once a day before suspensions, so a customer with
 * credit is not suspended while it sits unused. Oldest due first.
 *
 * Not on: a credit purchase (add funds); an invoice with a payment on its way (processing); an invoice of a service
 * with a gateway subscription (Stripe/PayPal charge the whole amount: credit first would be paid twice); a customer
 * who opted out (users.credit_auto_apply). All read under the payment locks, as the payment itself.
 */
class CreditAutoApply
{
    public static function enabled(): bool
    {
        return (bool) config('settings.credits_enabled') && (bool) config('settings.credits_auto_use', true);
    }

    /** Credit onto one invoice, as much as it covers. Null when nothing was paid. */
    public function toInvoice(Invoice|int $invoice): ?InvoiceTransaction
    {
        if (!self::enabled()) {
            return null;
        }
        $transaction = (new RecordPaymentService)->payWithCredit($invoice, eligible: fn (Invoice $locked): bool => self::eligible($locked));
        if ($transaction !== null) {
            $this->notify($transaction);
        }

        return $transaction;
    }

    /** A customer's open invoices in a currency, oldest due first, while the credit lasts. Returns how many it paid into. */
    public function forCustomer(int $userId, string $currency): int
    {
        if (!self::enabled()) {
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

    /** The daily pass (cron, before suspensions): every customer with credit and an open invoice in that currency. */
    public function sweep(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $paid = 0;
        Credit::query()->where('amount', '>', 0)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('invoices')
                ->whereColumn('invoices.user_id', 'credits.user_id')->whereColumn('invoices.currency_code', 'credits.currency_code')
                ->where('invoices.status', Invoice::STATUS_PENDING))
            ->orderBy('id')->get(['user_id', 'currency_code'])
            ->each(function (Credit $credit) use (&$paid) {
                try {
                    $paid += $this->forCustomer((int) $credit->user_id, $credit->currency_code);
                } catch (\Throwable $e) {
                    report($e); // one customer's failure does not stop the others
                }
            });

        return $paid;
    }

    /** Whether credit may pay this (locked) invoice on its own. */
    public static function eligible(Invoice $invoice): bool
    {
        if ($invoice->status !== Invoice::STATUS_PENDING || $invoice->user_id === null) {
            return false;
        }
        if (!(bool) (User::query()->whereKey($invoice->user_id)->value('credit_auto_apply') ?? true)) {
            return false;
        }
        $items = $invoice->items()->lockForUpdate()->get(['id', 'reference_type', 'reference_id']);
        if ($items->contains('reference_type', Credit::class)) {
            return false;
        }
        if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Processing->value)->lockForUpdate()->exists()) {
            return false;
        }
        $services = $items->where('reference_type', Service::class)->pluck('reference_id')->filter();

        return $services->isEmpty() || !Service::query()->whereIn('id', $services)->whereNotNull('subscription_id')
            ->where('subscription_id', '!=', '')->exists();
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

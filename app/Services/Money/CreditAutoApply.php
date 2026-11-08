<?php

namespace App\Services\Money;

use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Helpers\NotificationHelper;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\SavedMethodCharge;
use App\Services\Service\DunningRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Account credit pays open invoices on its own, also in part (docs/money-trail.md, decision 5, MT-1e): when an invoice
 * is made (renewals), when credit comes in (an overpayment) and once a day before suspensions, so a customer with
 * credit is not suspended while it sits unused. Oldest due first.
 *
 * Not on: a credit purchase (add funds); an order left unpaid past its first days (ORDER_DAYS: abandoned, the customer
 * pays it, as AutoCharge leaves orders; sweep 4 C-FIRST-RUN-1); an invoice with a payment on its way (processing); an invoice of a service
 * with a gateway subscription (Stripe/PayPal charge the whole amount: credit first would be paid twice), except its
 * own renewal when the credit covers it all (a subscription sale that became credit pays it, as before MT-1e; and once
 * it is overdue, the subscription not having paid it, once overdue by Suspend Days: ADV-A3-3, P2); an
 * invoice of a service the customer asked to cancel; an upgrade invoice (the cron reprices it); an invoice staff took
 * a credit payment off (deleted it: the sweep would put it back); a customer who opted out (clients.credit_auto_apply).
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
     *
     * Only Credits Enabled gates it, not «Automatically use credits» (myip: NoAutoApplyCredit), as the customer's
     * opt-out does not: the subscription has no other way to pay its renewal, and with the policy off its own sale sat
     * as credit while the renewal was dunned, suspended and billed again (sweep 3, L-SETTINGS-MATRIX-1).
     */
    public function toSubscriptionRenewal(Invoice $invoice): ?InvoiceTransaction
    {
        if (!(bool) config('settings.credits_enabled')) {
            return null;
        }

        return (new RecordPaymentService)->payWithCredit($invoice, onlyIfCovers: true,
            eligible: fn (Invoice $locked): bool => self::eligible($locked, subscriptionRenewal: true));
    }

    /** A customer's open invoices in a currency, oldest due first, while the credit lasts. Returns how many it paid into. */
    public function forCustomer(int $clientId, string $currency): int
    {
        if (!self::enabled() || !self::customerAllows($clientId)) {
            return 0;
        }
        $ledger = new CreditLedger;
        $paid = 0;
        $invoices = Invoice::query()->where('client_id', $clientId)->where('currency_code', $currency)
            ->where('status', Invoice::STATUS_UNPAID)->orderBy('due_at')->orderBy('id')->pluck('id');
        $overdueRenewals = null;
        foreach ($invoices as $invoiceId) {
            $balance = $ledger->balanceCents($clientId, $currency);
            if ($balance <= 0) {
                break;
            }
            if ($this->toInvoice($invoiceId) !== null) {
                $paid++;

                continue;
            }
            // Read once per customer, and only when credit refused one: never three queries per invoice refused (P4).
            $overdueRenewals ??= $this->overdueSubscriptionRenewals($clientId, $currency);
            if (isset($overdueRenewals[(int) $invoiceId]) && $this->toOverdueSubscriptionRenewal((int) $invoiceId, $balance) !== null) {
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * The customer's renewals of a service with a gateway subscription that the subscription did not pay (a card failed,
     * cancelled at the gateway), overdue by Suspend Days (cronjob_order_suspend, from the invoice's due date, the days the
     * cron counts): credit that covers it all pays it then, as toSubscriptionRenewal() does when it is billed, rather than
     * the service being suspended and ended with its credit unused (ADV-A3-3). Not before: a subscription charge a day or
     * two late (Stripe's retries, a PayPal sale) would find it paid and become credit, the customer charged twice (review
     * of #692, P2). A plain date rule (review 2): whatever the service's hold, exemption, the dunning window or the
     * suspension switch, and also once it is suspended for non-payment (credit that comes later brings it back).
     *
     * @return array<int, int> invoice id => its position
     */
    private function overdueSubscriptionRenewals(int $clientId, string $currency): array
    {
        $dueBy = today()->subDays(DunningRules::days('cronjob_order_suspend', 8))->toDateString();

        return Invoice::query()->where('client_id', $clientId)->where('currency_code', $currency)->where('status', Invoice::STATUS_UNPAID)
            ->whereNotNull('due_at')->whereDate('due_at', '<=', $dueBy)
            ->whereHas('items', fn ($items) => $items->where('reference_type', Service::class)->whereIn('reference_id', Service::query()
                ->whereNotNull('subscription_id')->where('subscription_id', '!=', '')
                ->where(fn ($status) => $status->where('status', Service::STATUS_ACTIVE)
                    ->orWhere(fn ($dunned) => $dunned->where('status', Service::STATUS_SUSPENDED)
                        ->where(fn ($unpaid) => $unpaid->whereNotNull('dunning_suspended_at')->orWhere('suspend_reason', Service::SUSPEND_REASON_PARENT))))
                ->select('services.id')))
            ->pluck('id')->map(fn ($id) => (int) $id)->flip()->all();
    }

    /**
     * One of overdueSubscriptionRenewals(), paid in full from the credit. Not when the balance cannot cover what is left
     * (no locks for nothing, P4). The customer's opt-out is read again under the lock (P3): unlike a subscription's sale
     * that became credit (toSubscriptionRenewal), this is credit the customer kept, and they may have opted out since.
     */
    private function toOverdueSubscriptionRenewal(int $invoiceId, int $balance): ?InvoiceTransaction
    {
        $invoice = Invoice::query()->with(['items', 'adjustmentNotes', 'transactions'])->find($invoiceId);
        if ($invoice === null || $balance < RecordPaymentService::toCents($invoice->remaining)) {
            return null;
        }

        return (new RecordPaymentService)->payWithCredit($invoice, onlyIfCovers: true,
            eligible: fn (Invoice $locked): bool => self::eligible($locked, subscriptionRenewal: true)
                && self::customerAllows((int) $locked->client_id, lock: true));
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
                ->whereColumn('invoices.client_id', 'credits.client_id')->whereColumn('invoices.currency_code', 'credits.currency_code')
                ->where('invoices.status', Invoice::STATUS_UNPAID))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('clients')
                ->whereColumn('clients.id', 'credits.client_id')->where('clients.credit_auto_apply', true))
            ->orderBy('id')->get(['client_id', 'currency_code'])
            ->each(function (Credit $credit) use (&$paid, $failed) {
                try {
                    $paid += $this->forCustomer((int) $credit->client_id, $credit->currency_code);
                } catch (\Throwable $e) {
                    report($e);
                    if ($failed !== null) {
                        $failed((int) $credit->client_id, $e);
                    }
                }
            });

        return $paid;
    }

    /**
     * Credit payments on an invoice that is about to be cancelled go back to the customer's credit (journaled as a
     * deletion), so credit applied in part is not lost with the invoice. Returns how many it gave back.
     *
     * One with refunds (staff refunded it to credit, PR 2c) is never deleted (its refund history stays): what is still
     * on the invoice goes back to credit as its credited part, and the row stays (sweep MONEY-LEDGER-2). Under the
     * caller's payment locks (customer, invoice).
     */
    public function giveBack(Invoice $invoice): int
    {
        $payments = $invoice->transactions()->where('is_credit_transaction', true)->orderBy('id')->lockForUpdate()->get();
        foreach ($payments as $payment) {
            if (RecordPaymentService::toCents($payment->refunded_amount) > 0 || RecordPaymentService::refundedToCredit($payment)) {
                $this->creditBack($invoice, $payment);

                continue;
            }
            (new RecordPaymentService)->deleteTransaction($payment, cause: MoneyTrail::CAUSE_CANCELLATION);
        }

        return $payments->count();
    }

    /** What a refunded credit payment still has on the invoice becomes credit again (credited_amount), journaled. */
    private function creditBack(Invoice $invoice, InvoiceTransaction $payment): void
    {
        if ($payment->status !== InvoiceTransactionStatus::Succeeded || $invoice->client_id === null) {
            return;
        }
        $cents = RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
            - RecordPaymentService::toCents($payment->credited_amount);
        if ($cents <= 0) {
            return;
        }
        $payment->increment('credited_amount', $cents / 100);
        (new CreditLedger)->add((int) $invoice->client_id, $invoice->currency_code, $cents, MoneyTrail::CAUSE_CANCELLATION,
            MoneyTrail::SOURCE_CREDIT, $invoice, $payment);
    }

    /**
     * Whether credit may pay this (locked) invoice on its own. For a subscription's own renewal ($subscriptionRenewal,
     * paid only in full) the subscription and the opt-out do not stop it: that credit is the subscription's payment.
     */
    public static function eligible(Invoice $invoice, bool $subscriptionRenewal = false): bool
    {
        if ($invoice->status !== Invoice::STATUS_UNPAID || $invoice->client_id === null) {
            return false;
        }
        // The customer's row is locked by the payment already (lockInvoice): this reads it as of now.
        if (!$subscriptionRenewal && !self::customerAllows((int) $invoice->client_id, lock: true)) {
            return false;
        }
        $items = $invoice->items()->lockForUpdate()->get(['id', 'reference_type', 'reference_id', 'kind']);
        if ($items->contains('reference_type', Credit::class) || $items->contains('reference_type', ServiceUpgrade::class)) {
            return false;
        }
        // An order left unpaid past its first days (a new domain's registration or transfer, a service not set up yet):
        // abandoned, as AutoCharge::due leaves every order to its customer (D8b). Credit paying it on its own would start
        // the service and buy the name at the registrar months later, for an order nobody wants any more (an imported
        // WHMCS one on the first run, sweep 4 C-FIRST-RUN-1; orders are not cancelled by default). A fresh order is paid
        // by credit as decision 5 has it (its partial credit goes back if the order is cancelled). The customer pays an
        // older one with credit from the invoice (Invoices\Show), staff with «Apply credit».
        if (self::abandonedOrder($invoice, $items)) {
            return false;
        }
        if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Processing->value)->lockForUpdate()->exists()) {
            return false;
        }
        // A saved-method charge not settled (on its way, of unknown outcome, taken and its payment not recorded yet) holds the
        // invoice for every way of paying: credit paying it now would leave that charge's money as a second payment (sweep 2,
        // F-CONCURRENCY-1). Read under the invoice's payment lock, as SavedMethodCharge::charge reads it.
        if (SavedMethodCharge::onItsWay($invoice)) {
            return false;
        }
        // Staff took a credit payment off this invoice: the credit stays where they put it. One made here (source credit)
        // or brought by the WHMCS import (source import: its credit_out, credit_payment, reversed; review of #523).
        if (MoneyEvent::query()->where('invoice_id', $invoice->id)->where('kind', MoneyEventKind::Reversal->value)
            ->where('cause', MoneyTrail::CAUSE_TRANSACTION_DELETED)
            ->where(fn ($reversal) => $reversal->where('source', MoneyTrail::SOURCE_CREDIT)
                ->orWhereIn('reverses_id', MoneyEvent::query()->select('id')->where('invoice_id', $invoice->id)
                    ->where('cause', MoneyTrail::CAUSE_CREDIT_PAYMENT)))
            ->exists()) {
            return false;
        }
        // Staff refunded money of this invoice to credit (PR 2c): paid straight back into it, the refund would be undone
        // and the invoice could never be cancelled (code review and adversarial review of 2c).
        if (MoneyEvent::query()->where('invoice_id', $invoice->id)->where('cause', MoneyTrail::CAUSE_REFUND_TO_CREDIT)->exists()) {
            return false;
        }
        // A domain's renewal the customer no longer renews, or of a domain gone (D5b, adversarial ADV-5): not credit's.
        $domains = $items->where('reference_type', Domain::class)->pluck('reference_id')->filter();
        if ($domains->isNotEmpty() && Domain::query()->whereIn('id', $domains)
            ->where(fn ($domain) => $domain->whereIn('status', DomainStatus::terminalValues())->orWhere('auto_renew', false))->exists()) {
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
        // Not a debt kept when its service ended (Δ3a): credit pays it as any other.
        if ($invoice->kept_as_debt_at === null && Service::query()->whereIn('id', $services)->whereIn('status', Service::ENDED_STATUSES)->exists()) {
            return false;
        }

        return $subscriptionRenewal || !Service::query()->whereIn('id', $services)->whereNotNull('subscription_id')
            ->where('subscription_id', '!=', '')->exists();
    }

    /**
     * Days an order's invoice is paid by credit on its own after it was made, or after its due date when that is later
     * (Order Days Grace, order_days_grace: an order not yet due is not abandoned, sweep 4 review REV4-C-2): the
     * order-cancel days when set (the cron cancels it then), else ORDER_DAYS, the time an order is paid in.
     */
    public const ORDER_DAYS = 7;

    /** @param  Collection<int, InvoiceItem>  $items */
    private static function abandonedOrder(Invoice $invoice, Collection $items): bool
    {
        $cancelDays = (int) config('settings.cronjob_order_cancel', 0);
        $made = $invoice->created_at ?? $invoice->due_at;
        if ($made !== null && $invoice->due_at !== null && $invoice->due_at->greaterThan($made)) {
            $made = $invoice->due_at;
        }
        if ($made === null || $made->greaterThanOrEqualTo(now()->subDays($cancelDays > 0 ? $cancelDays : self::ORDER_DAYS))) {
            return false;
        }
        if ($items->contains(fn ($item) => $item->reference_type === Domain::class
            && in_array($item->kind, [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER], true))) {
            return true;
        }
        $services = $items->where('reference_type', Service::class)->pluck('reference_id')->filter();

        return $services->isNotEmpty() && Service::query()->whereIn('id', $services)->where('status', Service::STATUS_PENDING)->exists();
    }

    private static function customerAllows(int $clientId, bool $lock = false): bool
    {
        $query = Client::query()->whereKey($clientId);

        return (bool) (($lock ? $query->lockForUpdate() : $query)->value('credit_auto_apply') ?? true);
    }

    /**
     * The customer is told what credit paid and what is left, once the payment commits. A fully paid invoice already
     * sends «Invoice paid».
     */
    public function notify(InvoiceTransaction $transaction): void
    {
        DB::afterCommit(function () use ($transaction) {
            $invoice = $transaction->invoice()->first();
            if ($invoice === null || $invoice->client === null || $invoice->status === Invoice::STATUS_PAID) {
                return;
            }
            try {
                NotificationHelper::creditAppliedNotification($invoice->client, $invoice, $transaction);
            } catch (\Throwable $e) {
                report($e); // a notification never undoes the payment
            }
        });
    }
}

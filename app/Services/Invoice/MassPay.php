<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mass Pay for customers (PR F2, decision 7, as WHMCS «Mass Payment»): the customer chooses unpaid invoices of the account
 * and pays them through a gateway as one amount, the whole of what is left on each. No container invoice: the gateway is
 * paid on the invoice due first (the lead) and, when the money arrives, what that one cannot take pays the others in
 * their order, as allocations of that payment (spread(), from RecordPaymentService under its locks), as staff do with
 * PayInvoices. Each invoice holds its own share, so the Ekdosi bridge can issue one document or one per invoice.
 *
 * The checkout carries the group (its ulid, in the provider's metadata) and the payment comes back with it: only that
 * payment is spread, never one guessed by its invoice or amount (F2 review CR-1, F2-ADV-1/5). Only gateways that carry it
 * are offered (Gateway::supportsPaymentGroups).
 *
 * A new group of the account replaces its open one (customers start again): a payment that still arrives for the old
 * one is spread over the old one's invoices. Bank transfers are not offered here: staff record them with «Add payment
 * to invoices» (F1).
 */
final class MassPay
{
    /** More would be a statement, not a payment. */
    public const MAX_INVOICES = 50;

    public static function enabled(): bool
    {
        return (bool) config('settings.mass_pay_enabled', true);
    }

    /**
     * Why the customer cannot pay this invoice together with others, or null. Read again under the locks when the group
     * is made, and before the gateway is opened.
     */
    public static function refusal(Invoice $invoice): ?string
    {
        $label = '#' . ($invoice->number ?: $invoice->id);
        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            return __('Invoice :invoice is not unpaid.', ['invoice' => $label]);
        }
        if (RecordPaymentService::toCents($invoice->remaining) <= 0) {
            return __('Nothing is left to pay on invoice :invoice.', ['invoice' => $label]);
        }
        // Credit bought with credit: the others are paid from what the payment leaves as credit.
        if ($invoice->items()->where('reference_type', Credit::class)->exists()) {
            return __('Invoice :invoice buys account credit: pay it on its own.', ['invoice' => $label]);
        }
        if ($invoice->billsEndedService()) {
            return __('Invoice :invoice bills a service that has ended: please contact us.', ['invoice' => $label]);
        }
        // A payment on its way (SEPA, a PayPal review): paying it again would pay it twice. A group's payment sits on its
        // first invoice only, so the group's others are held too while it is processed (F2 review CR-3).
        if ($invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists()
            || PaymentGroup::query()->whereIn('status', [PaymentGroup::STATUS_OPEN, PaymentGroup::STATUS_REPLACED])
                ->whereHas('invoices', fn ($query) => $query->whereKey($invoice->id))
                ->whereHas('transaction', fn ($query) => $query->where('status', InvoiceTransactionStatus::Processing))->exists()) {
            return __('A payment for invoice :invoice is still being processed.', ['invoice' => $label]);
        }

        return null;
    }

    /**
     * The group of the chosen invoices, oldest due first, replacing the account's open one.
     *
     * @param  list<int|string>  $invoiceIds
     *
     * @throws DisplayException nothing made: fewer than two, another account's, another currency, or one that cannot be paid
     */
    public function open(Client $client, array $invoiceIds, ?User $user = null): PaymentGroup
    {
        if (!self::enabled()) {
            throw new DisplayException(__('Paying several invoices at once is not available.'));
        }
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if (count($invoiceIds) < 2) {
            throw new DisplayException(__('Choose at least two invoices.'));
        }
        if (count($invoiceIds) > self::MAX_INVOICES) {
            throw new DisplayException(__('Choose at most :count invoices.', ['count' => self::MAX_INVOICES]));
        }

        return DB::transaction(function () use ($client, $invoiceIds, $user) {
            Client::lock($client->id);
            sort($invoiceIds);
            $invoices = Invoice::query()->whereKey($invoiceIds)->where('client_id', $client->id)->orderBy('id')->lockForUpdate()->get();
            if ($invoices->count() !== count($invoiceIds)) {
                throw new DisplayException(__('One of these invoices changed meanwhile: reload the page.'));
            }
            if ($invoices->pluck('currency_code')->unique()->count() !== 1) {
                throw new DisplayException(__('Only invoices in one currency are paid together.'));
            }
            foreach ($invoices as $invoice) {
                if (($refusal = self::refusal($invoice)) !== null) {
                    throw new DisplayException($refusal);
                }
            }
            $ordered = $invoices->sortBy(fn (Invoice $invoice) => [$invoice->due_at?->toDateString() ?? '9999-12-31', $invoice->id])->values();
            $amounts = $ordered->map(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->remaining));

            PaymentGroup::query()->where('client_id', $client->id)->where('status', PaymentGroup::STATUS_OPEN)
                ->update(['status' => PaymentGroup::STATUS_REPLACED]);
            $group = PaymentGroup::query()->create([
                'client_id' => $client->id,
                'user_id' => $user?->id,
                'currency_code' => $ordered->first()->currency_code,
                'invoice_id' => $ordered->first()->id,
                'total' => $amounts->sum() / 100,
                'status' => PaymentGroup::STATUS_OPEN,
            ]);
            foreach ($ordered as $position => $invoice) {
                $group->invoices()->attach($invoice->id, ['position' => $position, 'amount' => $amounts[$position] / 100]);
            }

            return $group;
        });
    }

    /**
     * Why this group cannot be paid now, or null: it is still the account's open one, and each invoice is still payable
     * with what was left on it when chosen (staff may have changed one meanwhile). The customer then chooses again.
     */
    public static function staleness(PaymentGroup $group): ?string
    {
        if ($group->status !== PaymentGroup::STATUS_OPEN) {
            return __('These invoices were paid or chosen again: choose them again from your invoices.');
        }
        // The invoice the gateway is paid on, or another, deleted by staff (its row goes with it): the total no longer
        // adds up (F2 review CR-2, F2-ADV-3/4).
        $invoices = $group->invoices()->get();
        if ($group->invoice_id === null || !$invoices->contains('id', $group->invoice_id)
            || $invoices->sum(fn (Invoice $invoice) => RecordPaymentService::toCents($invoice->pivot->amount)) !== RecordPaymentService::toCents($group->total)) {
            return __('One of these invoices changed: choose them again from your invoices.');
        }
        foreach ($invoices as $invoice) {
            if ((int) $invoice->client_id !== (int) $group->client_id || $invoice->currency_code !== $group->currency_code
                || self::refusal($invoice) !== null
                || RecordPaymentService::toCents($invoice->remaining) !== RecordPaymentService::toCents($invoice->pivot->amount)) {
                return __('One of these invoices changed: choose them again from your invoices.');
            }
        }

        return null;
    }

    /**
     * The group a gateway payment on $lead pays, locked, or null: the one its checkout was opened for ($reference, the
     * ulid the provider returned), else the one its processing payment was linked to (link()). It must be the account's,
     * led by that invoice, in its currency, and not paid yet (open, or replaced by a later choice). Called by
     * RecordPaymentService under the customer → invoice locks, for money the lead cannot take.
     */
    public static function groupPaidBy(Invoice $lead, ?string $reference, InvoiceTransaction $payment): ?PaymentGroup
    {
        return self::unpaidLedBy($lead)
            ->where(fn ($query) => $reference !== null
                ? $query->where('ulid', $reference)->orWhere('invoice_transaction_id', $payment->id)
                : $query->where('invoice_transaction_id', $payment->id))
            ->lockForUpdate()->first();
    }

    /**
     * A processing payment of a group's checkout (SEPA, a PayPal review) is kept on the group: its completion may come
     * back without the reference, and the group's invoices are held meanwhile (refusal()).
     */
    public static function link(Invoice $lead, ?string $reference, InvoiceTransaction $payment, bool $fromCheckout): void
    {
        if ($reference === null || !$fromCheckout) {
            return;
        }
        self::unpaidLedBy($lead)->where('ulid', $reference)->update(['invoice_transaction_id' => $payment->id]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<PaymentGroup> */
    private static function unpaidLedBy(Invoice $lead)
    {
        return PaymentGroup::query()
            ->where('invoice_id', $lead->id)->where('client_id', $lead->client_id)->where('currency_code', $lead->currency_code)
            ->whereIn('status', [PaymentGroup::STATUS_OPEN, PaymentGroup::STATUS_REPLACED]);
    }

    /**
     * What the payment left as credit pays the group's other invoices, in their order, each the whole of what is left
     * while it lasts (allocateFromTransaction: on the payment's own budget, dated as the payment). An invoice paid or
     * cancelled meanwhile takes nothing; what is left after the last stays account credit. The group is then paid.
     *
     * @return list<InvoiceTransaction> the allocations
     */
    public static function spread(PaymentGroup $group, InvoiceTransaction $payment): array
    {
        $group->update(['status' => PaymentGroup::STATUS_PAID, 'invoice_transaction_id' => $payment->id]);
        $paid = [];
        foreach ($group->invoices()->get() as $invoice) {
            if ((int) $invoice->id === (int) $payment->invoice_id) {
                continue;
            }
            $allocation = (new RecordPaymentService)->allocateFromTransaction($payment, $invoice->id, oneCyclePerService: false, chosenForPayment: true);
            if ($allocation !== null) {
                $paid[] = $allocation;
            }
        }

        return $paid;
    }
}

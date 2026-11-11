<?php

namespace App\Services\Payment;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\ChargeNotSent;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way a saved payment method is charged (PR H, docs/payments.md «Αυτόματη χρέωση»): by the cron on the due date,
 * by the customer («pay with a saved method», Mass Pay), and for a billing cycle change. Under the customer → invoice locks
 * (RecordPaymentService's order) it reads again what is left, refuses an invoice with a charge or a payment already on
 * its way (its Mass Pay groups' invoices included), and writes the attempt; then, after the commit, it calls the gateway
 * for that amount with the attempt's key as its idempotency key, and keeps the outcome. A second click, another tab, or
 * the cron while the customer pays cannot charge it twice (PAY-3). The payment is recorded as always, from the gateway's
 * answer or webhook.
 */
final class SavedMethodCharge
{
    /**
     * A charge taken whose payment is not recorded yet (Stripe's webhook late or failing): staff are told after this long
     * (AutoCharge). It holds the invoice until its payment is recorded or staff resolve it, however long (predeploy S1).
     */
    public const TAKEN_HOLD_DAYS = 3;

    /**
     * The longest a gateway keeps an idempotency key (Stripe 24 hours): an unknown outcome is sent again with its key until
     * then; a gateway may keep its own shorter (keyReuseHours). Past it a resend would be a new charge, so it is not sent:
     * the invoice stays held until the outcome is known (predeploy S1).
     */
    public const KEY_REUSE_HOURS = 23;

    /**
     * A gateway's checkout the customer opened on the invoice (a Stripe payment form, Cardlink's or Mollie's page, PayPal):
     * the cron does not charge a saved method of it for this long after (openCheckout), as the checkout may still take the
     * money (sweep 3 L-NO-DOUBLE-CHARGE-2). The cron's next run charges it, if it is still open then.
     */
    public const CHECKOUT_HOLD_MINUTES = 60;

    /** The hours a gateway keeps a charge's key: its own (`idempotencyKeyHours`), never above KEY_REUSE_HOURS. */
    public static function keyReuseHours(?BillingAgreement $agreement): int
    {
        $gateway = $agreement?->gateway;
        $own = $gateway !== null && rescue(fn () => ExtensionHelper::hasFunction($gateway, 'idempotencyKeyHours'), false, false)
            ? (int) rescue(fn () => ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings)->idempotencyKeyHours(), 0, false)
            : self::KEY_REUSE_HOURS;

        return max(0, min(self::KEY_REUSE_HOURS, $own));
    }

    /**
     * An earlier charge to this method of this invoice whose outcome is unknown (a timeout, a 5xx, or one started and never
     * finished), still within its gateway's key window: sent again with its key, not a new charge. Only a charge of the
     * same thing: of the invoice alone ($groupId null), or of that Mass Pay group. The gateway keeps a key for the request
     * it first came with, so an invoice's own charge resent as a group's of the same total (or the other way round) is
     * answered with an error and settles nothing (sweep-8 reviews, #791). The other one is not this charge's to resend: it
     * holds the invoice (unresolved), and charge() refuses.
     */
    public static function unknownAttempt(int $invoiceId, int $agreementId, ?int $groupId = null): ?ChargeAttempt
    {
        $attempt = self::current(ChargeAttempt::query()->where('invoice_id', $invoiceId)->where('billing_agreement_id', $agreementId)
            ->where('payment_group_id', $groupId)
            ->where(fn ($query) => $query->where('status', ChargeAttempt::STATUS_UNKNOWN)
                ->orWhere(fn ($stale) => $stale->where('status', ChargeAttempt::STATUS_STARTED)
                    ->where('sent_at', '<=', now()->subMinutes(ChargeAttempt::HOLD_MINUTES))))
            ->latest('id'))->first();

        return $attempt !== null && $attempt->started_at->greaterThan(now()->subHours(self::keyReuseHours($attempt->billingAgreement))) ? $attempt : null;
    }

    /**
     * Charges whose outcome is not settled (predeploy S1) on these invoices, or of these Mass Pay groups (on their first
     * invoice: PR F2): sent and not answered (started, any age: a crash is an unknown outcome), of unknown outcome, or
     * taken and its payment not recorded. None of them is released by time: only by the payment recorded (notRecorded) or
     * by staff (App\Admin\Actions\ResolveCharge: not taken, or recorded another way).
     *
     * @param  list<int>  $invoiceIds
     * @param  list<int>  $groupIds
     * @return Collection<int, ChargeAttempt>
     */
    public static function unresolved(array $invoiceIds, ?int $except = null, array $groupIds = []): Collection
    {
        if ($invoiceIds === [] && $groupIds === []) {
            return new Collection;
        }

        // By invoice and by group apart: each a read of one index, so the locks of current() stay on these invoices' and
        // groups' own attempts (one query with «or» may read, and lock, the whole table).
        $found = new Collection;
        foreach (['invoice_id' => $invoiceIds, 'payment_group_id' => $groupIds] as $column => $ids) {
            if ($ids === []) {
                continue;
            }
            $attempts = ChargeAttempt::query()->whereIn($column, $ids)
                ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
                ->whereIn('status', [ChargeAttempt::STATUS_STARTED, ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::STATUS_SUCCEEDED]);
            self::notRecorded($attempts);
            $found = $found->merge(self::current($attempts->with('billingAgreement'))->get());
        }

        return $found->unique('id')->sortBy('id')->values();
    }

    /**
     * What decides whether an invoice is held is read as it is NOW, not as the transaction's snapshot has it (S7-PAYMENTS-1,
     * REV-774-1/-2). Under MariaDB's REPEATABLE READ the snapshot is fixed at the transaction's first plain read: often
     * before it waited for the payment locks (lockInvoice reads the owner first), or by a caller's own earlier read. A plain
     * read after the wait then misses the attempt or the payment the locks' last holder committed, and the invoice is paid
     * twice (a card and credit, two charges) or moved while its card is charged. A locking read (shared) reads the latest
     * committed rows whatever the snapshot, and waits for a charge being written. Inside a transaction only: outside one
     * every read is current. After the customer's and the invoice's locks where the caller takes them (docs/money-trail.md
     * §2): these rows are written under those locks or in statements of their own.
     *
     * @template T of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function current(Builder|\Illuminate\Database\Query\Builder $query): Builder|\Illuminate\Database\Query\Builder
    {
        return DB::transactionLevel() > 0 ? $query->sharedLock() : $query;
    }

    /**
     * The unsettled charges that could pay this invoice (or this group of it): those staff resolve on its page.
     *
     * @return Collection<int, ChargeAttempt>
     */
    public static function holding(Invoice $invoice, ?PaymentGroup $group = null): Collection
    {
        [$ids, $groups] = self::payable($invoice, $group);

        return self::unresolved($ids, null, $groups);
    }

    /**
     * Staff may settle it by hand: never one still on its way (started, under HOLD_MINUTES: its gateway call may be running,
     * and nobody can have checked it at the gateway yet: adversarial ADV-UNRES-3).
     */
    public static function resolvable(ChargeAttempt $attempt): bool
    {
        return $attempt->status !== ChargeAttempt::STATUS_STARTED || $attempt->sent_at->lessThanOrEqualTo(now()->subMinutes(ChargeAttempt::HOLD_MINUTES));
    }

    /**
     * @param  PaymentGroup|null  $group  a customer's Mass Pay group: its total, charged on its first invoice (PR F2)
     *
     * @throws DisplayException nothing was sent: the invoice is not payable now, or a payment for it is already on its way
     */
    public function charge(Invoice $invoice, BillingAgreement $agreement, string $source, ?PaymentGroup $group = null): ChargeAttempt
    {
        $attempt = DB::transaction(function () use (&$invoice, $agreement, $source, $group) {
            // The invoice itself: nothing is read before the locks (S7-PAYMENTS-1); what holds it is read current anyway.
            $locked = (new RecordPaymentService)->lockInvoice($invoice);
            // The gateway is asked with the invoice as it is under the locks, not the caller's copy (REV-774-6).
            $invoice = $locked;
            if ($locked->status !== Invoice::STATUS_UNPAID) {
                throw new DisplayException(__('This invoice is not open for payment.'));
            }
            // Kept from the cron's charge since the run read it (staff's «Do not auto-charge», the customer's «I'll pay
            // another way» on its page with a transfer maybe on its way, a service's own «Do not charge automatically»), or
            // another method now its: the run's choice read again under the lock the invoice's flag is set under.
            if ($source === ChargeAttempt::SOURCE_CRON && AutoCharge::agreementFor($locked)?->id !== $agreement->id) {
                throw new DisplayException(__('This invoice is not charged automatically.'));
            }
            // The customer opened a checkout of it a moment ago (openCheckout): it may still take the money, so the cron
            // leaves it to its next run (sweep 3 L-NO-DOUBLE-CHARGE-2). Read under the lock it is written under.
            if ($source === ChargeAttempt::SOURCE_CRON && self::checkoutOpen($locked)) {
                throw new DisplayException(__('The customer is paying this invoice at a checkout.'));
            }
            // Only the invoice's own account's method pays it: the owner as it is under the locks, not as the caller read it
            // (staff moved the invoice meanwhile: REV-774-6). Before an attempt is written.
            if ($locked->client_id === null || (int) $locked->client_id !== (int) $agreement->client_id) {
                throw new DisplayException(__('This payment method belongs to another account.'));
            }
            $amount = $group !== null ? RecordPaymentService::toCents($group->total) : RecordPaymentService::toCents($locked->withLockedBalance()->remaining);
            if ($amount <= 0) {
                throw new DisplayException(__('Nothing is left to pay on this invoice.'));
            }
            // An earlier charge of the same amount to the same method whose outcome is unknown (a timeout, a 5xx): sent again
            // with its key, so the gateway answers with that charge instead of taking the money twice (adversarial ADV-3).
            // Of the same thing only: the invoice alone, or this group. An unknown charge of the other kind is another charge.
            $unknown = self::unknownAttempt($locked->id, $agreement->id, $group?->id);
            if ($unknown !== null && RecordPaymentService::toCents($unknown->amount) !== $amount) {
                $unknown = null; // another amount is another charge: held below until its outcome is known
            }
            // A method of a gateway staff disabled is not charged, from anywhere, before an attempt is written. Only a charge
            // of unknown outcome is resent with its key: it takes no new money and records the one maybe taken (ADV-540-2).
            if ($unknown === null && !ExtensionHelper::gatewayEnabled($agreement->gateway_id)) {
                throw new DisplayException(__('This payment method cannot be used now.'));
            }
            if (self::onItsWay($locked, $group, except: $unknown?->id)) {
                throw new DisplayException(__('A payment for this invoice is already on its way.'));
            }
            if ($unknown !== null) {
                // Its error is kept: a resend that does not confirm the payment leaves it of unknown outcome (below).
                $unknown->update(['status' => ChargeAttempt::STATUS_STARTED, 'source' => $source, 'sent_at' => now(), 'finished_at' => null]);

                return $unknown;
            }

            return ChargeAttempt::query()->create([
                'key' => (string) Str::uuid(),
                'invoice_id' => $locked->id,
                'client_id' => $locked->client_id,
                'billing_agreement_id' => $agreement->id,
                'payment_group_id' => $group?->id,
                'amount' => $amount / 100,
                'currency_code' => $locked->currency_code,
                'source' => $source,
                'status' => ChargeAttempt::STATUS_STARTED,
                'started_at' => now(),
                'sent_at' => now(),
            ]);
        });

        // The gateway is called outside any transaction: a payment it records commits on its own. It is charged the amount
        // read under the locks, not the caller's copy (adversarial ADV-4).
        try {
            if ($agreement->gateway === null) {
                throw new DisplayException(__('This payment method was removed.'));
            }
            $charged = $group !== null
                ? ExtensionHelper::chargeGroup($agreement->gateway, $group, $agreement, $attempt->key)
                : ExtensionHelper::charge($agreement->gateway, $invoice, $agreement, (float) $attempt->amount, $attempt->key);
            if ($charged !== true) {
                throw new DisplayException(__('Could not process payment. Please try again or use a different payment method.'));
            }
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_SUCCEEDED);
        } catch (Throwable $e) {
            if (!$e instanceof DisplayException) {
                report($e);
            }
            [$status, $error] = match (true) {
                // The bank asks the customer to confirm: only ever the gateway's answer to the charge itself, and no money
                // was taken, so it settles a resend too and the customer pays at the card form (REV-791-R2-2).
                $e instanceof ChargeNeedsAction => [ChargeAttempt::STATUS_ACTION_REQUIRED, $e->getMessage()],
                // Else a resend of an unknown outcome only ever confirms the payment. Anything else it meets (not sent,
                // refused before the charge, the key still in use, a decline, no answer again) does not prove the first
                // send took nothing: it stays unknown, with its first error, its key and its hold, for staff to resolve
                // (S8-PAYMENTS-1, REV-791-1/-2).
                !$attempt->wasRecentlyCreated => [ChargeAttempt::STATUS_UNKNOWN, $attempt->error],
                // The gateway was not reached before the charge (an outage): nothing taken, nothing declined (L-FAILURES-2).
                $e instanceof ChargeNotSent => [ChargeAttempt::STATUS_NOT_SENT, $e->getMessage()],
                $e instanceof DisplayException => [ChargeAttempt::STATUS_FAILED, $e->getMessage()],
                // No answer about the money (a timeout, a 5xx): maybe taken. Kept with its key, sent again with it.
                default => [ChargeAttempt::STATUS_UNKNOWN, $e->getMessage()],
            };
            $this->finish($attempt, $agreement, $status, $error);
        }

        return $attempt;
    }

    /**
     * Paying this invoice now could pay it twice: a charge whose outcome is not settled (unresolved: on its way, of unknown
     * outcome, or taken and its payment not recorded; held for every method and every way of paying, however long, until
     * its payment is recorded or staff resolve it: predeploy S1, S2) that could pay it (its own, or of a Mass Pay group it
     * is in: the group's charge pays it too, adversarial ADV-1), or a payment the gateway is still processing (SEPA, a
     * PayPal review). A charge of another invoice alone never holds it, whatever group they were once in together
     * (ADV-UNRES-5). The expiry of a gateway's idempotency key never proves a charge failed.
     */
    public static function onItsWay(Invoice $invoice, ?PaymentGroup $group = null, ?int $except = null): bool
    {
        [$ids, $groups] = self::payable($invoice, $group);
        if (self::unresolved($ids, $except, $groups)->isNotEmpty()) {
            return true;
        }
        // A group's payment is recorded on the invoice its charge went to, and kept as the group's (MassPay). A charge that
        // took nothing (declined, or the bank asked to confirm) makes no group payment: the lead's own payment on its way
        // holds only the lead (adversarial ADV-593-5).
        $leads = $groups === [] ? [] : self::current(ChargeAttempt::query()->whereIn('payment_group_id', $groups)
            ->whereNotIn('status', [ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_ACTION_REQUIRED]))->pluck('invoice_id')->map(fn ($id) => (int) $id)->all();
        $payments = $groups === [] ? [] : self::current(PaymentGroup::query()->whereIn('id', $groups)->whereNotNull('invoice_transaction_id'))->pluck('invoice_transaction_id')->all();

        $processing = fn (string $column, array $in): bool => $in !== [] && self::current(InvoiceTransaction::query()
            ->where('status', InvoiceTransactionStatus::Processing)->whereIn($column, $in)->limit(1))->value('id') !== null;

        return $processing('invoice_id', [...$ids, ...$leads]) || $processing('id', $payments);
    }

    /**
     * The customer opens a gateway's checkout on the invoice (or on $group, a Mass Pay group of it): refused (false) when a
     * payment of it is on its way (onItsWay, predeploy S2); else stamped on it (and on each of the group's invoices) under
     * the same payment locks the cron's charge reads it under, so that charge waits CHECKOUT_HOLD_MINUTES: a checkout
     * opened before the cron's charge would take the same debt again when the customer completes it (sweep 3
     * L-NO-DOUBLE-CHARGE-2). Written without touching the invoice's own updated_at or observers: it is no change of it.
     */
    public static function openCheckout(Invoice $invoice, ?PaymentGroup $group = null): bool
    {
        return DB::transaction(function () use ($invoice, $group) {
            $locked = (new RecordPaymentService)->lockInvoice($invoice);
            if (self::onItsWay($locked, $group)) {
                return false;
            }
            [$ids] = self::payable($locked, $group);
            DB::table('invoices')->whereIn('id', $ids)->update(['checkout_opened_at' => now()]);

            return true;
        });
    }

    /** A checkout of it opened under CHECKOUT_HOLD_MINUTES ago (openCheckout): the cron does not charge it yet. */
    public static function checkoutOpen(Invoice $invoice): bool
    {
        $opened = self::current(DB::table('invoices')->where('id', $invoice->id))->value('checkout_opened_at');

        return $opened !== null && Carbon::parse($opened)->greaterThan(now()->subMinutes(self::CHECKOUT_HOLD_MINUTES));
    }

    /**
     * A saved method's charge its gateway took as on its way (Stripe «processing», a bank debit; a PayPal PENDING capture)
     * and then reported failed in a signed event, $payment being that payment now failed: settled as failed, so the invoice
     * is payable and charged again (left «succeeded» with no payment, it reads as taken and not recorded and holds the
     * invoice for ever: REV-PAYMENTS-1, sweep 3 L-NO-DOUBLE-CHARGE-1). The attempt of $key when the gateway carried it back;
     * else the one «succeeded» attempt on its invoice through its gateway (of $agreementId, when known), of at most its
     * amount, sent by the day it was recorded, that no other payment records now (notRecorded). None or more than one such
     * attempt is not known to be this payment's: left to staff (ResolveCharge). Only from «succeeded», so a charge staff
     * settled meanwhile is kept. @return int|null its id
     */
    public static function failedAtGateway(InvoiceTransaction $payment, string $error, ?int $agreementId = null, ?string $key = null): ?int
    {
        $candidates = ChargeAttempt::query()->where('invoice_id', $payment->invoice_id)
            ->where('status', ChargeAttempt::STATUS_SUCCEEDED)->where('amount', '<=', $payment->amount)
            ->whereHas('billingAgreement', fn ($agreement) => $agreement->where('gateway_id', $payment->gateway_id))
            ->when($agreementId !== null, fn ($query) => $query->where('billing_agreement_id', $agreementId))
            ->when($key !== null && $key !== '', fn ($query) => $query->where('key', $key))
            ->get()
            ->filter(fn (ChargeAttempt $attempt) => $attempt->sent_at->copy()->startOfDay()->lte($payment->created_at)
                && !self::recordedSince($attempt));
        if ($candidates->count() !== 1) {
            return null;
        }
        $attempt = $candidates->sole();
        $updated = ChargeAttempt::query()->whereKey($attempt->id)->where('status', ChargeAttempt::STATUS_SUCCEEDED)
            ->update(['status' => ChargeAttempt::STATUS_FAILED, 'error' => mb_substr($error, 0, 250)]);

        return $updated === 1 ? (int) $attempt->id : null;
    }

    /** The charge's payment was recorded: see notRecorded. */
    public static function recordedSince(ChargeAttempt $attempt): bool
    {
        return self::notRecorded(ChargeAttempt::query()->whereKey($attempt->getKey()))->doesntExist();
    }

    /**
     * The attempts whose payment was not recorded (from the gateway's answer, its webhook, or staff with its transaction
     * id): one on its invoice through its method's gateway, of at least its amount, that went through (a failed one is no
     * money taken: ADV-UNRES-1), from the day it was sent (staff date a payment by its day, at the time they record it:
     * ADV-UNRES-4). Credit, a transfer staff record or a smaller payment is not it (code review CR-7). In SQL, for any
     * number of attempts at once (ADV-UNRES-6).
     *
     * @param  Builder<ChargeAttempt>  $attempts
     * @return Builder<ChargeAttempt>
     */
    public static function notRecorded(Builder $attempts): Builder
    {
        return $attempts->whereNotExists(fn ($payment) => $payment->selectRaw('1')->from('invoice_transactions')
            ->whereColumn('invoice_transactions.invoice_id', 'charge_attempts.invoice_id')
            ->whereRaw('invoice_transactions.gateway_id = (select billing_agreements.gateway_id from billing_agreements where billing_agreements.id = charge_attempts.billing_agreement_id)')
            ->where('invoice_transactions.is_credit_transaction', false)
            ->whereIn('invoice_transactions.status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value])
            ->whereColumn('invoice_transactions.amount', '>=', 'charge_attempts.amount')
            ->whereRaw('invoice_transactions.created_at >= DATE(charge_attempts.sent_at)'));
    }

    /**
     * What a charge of it would pay (the invoice, or every invoice of $group), and the Mass Pay groups any of them is in:
     * a charge of one of those groups pays them too. @return array{0: list<int>, 1: list<int>}
     */
    private static function payable(Invoice $invoice, ?PaymentGroup $group): array
    {
        $ids = $group === null ? [(int) $invoice->id]
            : array_values(array_unique([(int) $invoice->id, ...self::current(DB::table('payment_group_invoice')->where('payment_group_id', $group->id))->pluck('invoice_id')->map(fn ($id) => (int) $id)->all()]));
        $groups = self::current(DB::table('payment_group_invoice')->whereIn('invoice_id', $ids))->pluck('payment_group_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        return [$ids, $groups];
    }

    private function finish(ChargeAttempt $attempt, BillingAgreement $agreement, string $status, ?string $error = null): void
    {
        $attempt->update(['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 250), 'finished_at' => now()]);
        // The days in a row the method was declined by the cron (decision 5): the cron stops at the cap, a success starts
        // again. Once a day, whatever the number of invoices declined that day (adversarial ADV-9).
        if ($status === ChargeAttempt::STATUS_SUCCEEDED) {
            BillingAgreement::query()->whereKey($agreement->id)->update(['failed_count' => 0]);
        } elseif ($attempt->source === ChargeAttempt::SOURCE_CRON && !in_array($status, [ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::STATUS_NOT_SENT], true)) {
            BillingAgreement::query()->whereKey($agreement->id)
                ->where(fn ($query) => $query->whereNull('last_failed_at')->orWhere('last_failed_at', '<', today()))
                ->update(['failed_count' => DB::raw('failed_count + 1')]);
            BillingAgreement::query()->whereKey($agreement->id)->update(['last_failed_at' => now()]);
        }
    }
}

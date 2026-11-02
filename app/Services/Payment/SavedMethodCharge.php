<?php

namespace App\Services\Payment;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGroup;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Collection;
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
     * finished), still within its gateway's key window: sent again with its key, not a new charge.
     */
    public static function unknownAttempt(int $invoiceId, int $agreementId): ?ChargeAttempt
    {
        $attempt = ChargeAttempt::query()->where('invoice_id', $invoiceId)->where('billing_agreement_id', $agreementId)
            ->where(fn ($query) => $query->where('status', ChargeAttempt::STATUS_UNKNOWN)
                ->orWhere(fn ($stale) => $stale->where('status', ChargeAttempt::STATUS_STARTED)
                    ->where('sent_at', '<=', now()->subMinutes(ChargeAttempt::HOLD_MINUTES))))
            ->latest('id')->first();

        return $attempt !== null && $attempt->started_at->greaterThan(now()->subHours(self::keyReuseHours($attempt->billingAgreement))) ? $attempt : null;
    }

    /**
     * Its charges whose outcome is not settled (predeploy S1): sent and not answered (started, any age: a crash is an
     * unknown outcome), of unknown outcome, or taken and its payment not recorded; none of them released by time, only by
     * the payment recorded (recordedSince) or by staff («not taken», ChargeAttempt::resolveNotTaken).
     *
     * @param  list<int>  $invoiceIds
     * @return Collection<int, ChargeAttempt>
     */
    public static function unresolved(array $invoiceIds, ?int $except = null): Collection
    {
        return ChargeAttempt::query()->whereIn('invoice_id', $invoiceIds)->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->whereIn('status', [ChargeAttempt::STATUS_STARTED, ChargeAttempt::STATUS_UNKNOWN, ChargeAttempt::STATUS_SUCCEEDED])
            ->with('billingAgreement')->orderBy('id')->get()
            ->reject(fn (ChargeAttempt $attempt) => self::recordedSince($attempt))->values();
    }

    /**
     * @param  PaymentGroup|null  $group  a customer's Mass Pay group: its total, charged on its first invoice (PR F2)
     *
     * @throws DisplayException nothing was sent: the invoice is not payable now, or a payment for it is already on its way
     */
    public function charge(Invoice $invoice, BillingAgreement $agreement, string $source, ?PaymentGroup $group = null): ChargeAttempt
    {
        $attempt = DB::transaction(function () use ($invoice, $agreement, $source, $group) {
            $locked = (new RecordPaymentService)->lockInvoice($invoice->id);
            if ($locked->status !== Invoice::STATUS_UNPAID) {
                throw new DisplayException(__('This invoice is not open for payment.'));
            }
            $amount = $group !== null ? RecordPaymentService::toCents($group->total) : RecordPaymentService::toCents($locked->remaining);
            if ($amount <= 0) {
                throw new DisplayException(__('Nothing is left to pay on this invoice.'));
            }
            // An earlier charge of the same amount to the same method whose outcome is unknown (a timeout, a 5xx): sent again
            // with its key, so the gateway answers with that charge instead of taking the money twice (adversarial ADV-3).
            $unknown = self::unknownAttempt($locked->id, $agreement->id);
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
                $unknown->update(['status' => ChargeAttempt::STATUS_STARTED, 'source' => $source, 'error' => null, 'sent_at' => now(), 'finished_at' => null]);

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
        } catch (ChargeNeedsAction $e) {
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_ACTION_REQUIRED, $e->getMessage());
        } catch (DisplayException $e) {
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_FAILED, $e->getMessage());
        } catch (Throwable $e) {
            // No answer about the money (a timeout, a 5xx): maybe taken. Kept with its key, sent again with it.
            report($e);
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_UNKNOWN, $e->getMessage());
        }

        return $attempt;
    }

    /**
     * Paying this invoice now could pay it twice: a charge whose outcome is not settled (unresolved: on its way, of unknown
     * outcome, or taken and its payment not recorded; held for every method and every way of paying, however long, until
     * its payment is recorded or staff resolve it: predeploy S1, S2) or a payment the gateway is still processing (SEPA, a
     * PayPal review), on it or on an invoice of a Mass Pay group it is in: the group's charge pays it too (adversarial
     * ADV-1). The expiry of a gateway's idempotency key never proves a charge failed.
     */
    public static function onItsWay(Invoice $invoice, ?PaymentGroup $group = null, ?int $except = null): bool
    {
        $ids = self::related($invoice, $group);

        return self::unresolved($ids, $except)->isNotEmpty()
            || InvoiceTransaction::query()->whereIn('invoice_id', $ids)->where('status', InvoiceTransactionStatus::Processing)->exists();
    }

    /**
     * The charge's payment was recorded (from the gateway's answer or its webhook): a payment on its invoice since it was
     * sent, through its method's gateway, of at least its amount. Credit, a transfer staff record or a smaller payment is
     * not it, and does not release the hold (code review CR-7).
     */
    public static function recordedSince(ChargeAttempt $attempt): bool
    {
        $gatewayId = $attempt->billingAgreement?->gateway_id;

        return $gatewayId !== null && InvoiceTransaction::query()->where('invoice_id', $attempt->invoice_id)
            ->where('gateway_id', $gatewayId)->where('is_credit_transaction', false)->where('amount', '>=', $attempt->amount)
            ->where('created_at', '>=', $attempt->sent_at->copy()->subMinute())->exists();
    }

    /** @return list<int> the invoice, and the invoices of the Mass Pay groups it is in (or of $group) */
    private static function related(Invoice $invoice, ?PaymentGroup $group): array
    {
        $groups = DB::table('payment_group_invoice')->where('invoice_id', $invoice->id)->pluck('payment_group_id')->all();
        if ($group !== null) {
            $groups[] = $group->id;
        }
        $ids = $groups === [] ? [] : DB::table('payment_group_invoice')->whereIn('payment_group_id', $groups)->pluck('invoice_id')->all();

        return array_values(array_unique(array_map('intval', [...$ids, $invoice->id])));
    }

    private function finish(ChargeAttempt $attempt, BillingAgreement $agreement, string $status, ?string $error = null): void
    {
        $attempt->update(['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 250), 'finished_at' => now()]);
        // The days in a row the method was declined by the cron (decision 5): the cron stops at the cap, a success starts
        // again. Once a day, whatever the number of invoices declined that day (adversarial ADV-9).
        if ($status === ChargeAttempt::STATUS_SUCCEEDED) {
            BillingAgreement::query()->whereKey($agreement->id)->update(['failed_count' => 0]);
        } elseif ($attempt->source === ChargeAttempt::SOURCE_CRON && $status !== ChargeAttempt::STATUS_UNKNOWN) {
            BillingAgreement::query()->whereKey($agreement->id)
                ->where(fn ($query) => $query->whereNull('last_failed_at')->orWhere('last_failed_at', '<', today()))
                ->update(['failed_count' => DB::raw('failed_count + 1')]);
            BillingAgreement::query()->whereKey($agreement->id)->update(['last_failed_at' => now()]);
        }
    }
}

<?php

namespace App\Services\Payment;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\PaymentGroup;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way a saved payment method is charged (PR H, docs/payments.md «Αυτόματη χρέωση»): by the cron on the due date,
 * by the customer («pay with a saved method», Mass Pay), and for a billing cycle change. Under the customer → invoice locks
 * (RecordPaymentService's order) it reads again what is left, refuses an invoice with a charge or a payment already on
 * its way, and writes the attempt; then, after the commit, it calls the gateway with the attempt's key as its idempotency
 * key and keeps the outcome. A second click, another tab, or the cron while the customer pays cannot charge it twice
 * (PAY-3). The payment is recorded as always, from the gateway's answer or webhook.
 */
final class SavedMethodCharge
{
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
            if (self::onItsWay($locked)) {
                throw new DisplayException(__('A payment for this invoice is already on its way.'));
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
            ]);
        });

        // The gateway is called outside any transaction: a payment it records commits on its own.
        try {
            if ($agreement->gateway === null) {
                throw new DisplayException(__('This payment method was removed.'));
            }
            $charged = $group !== null
                ? ExtensionHelper::chargeGroup($agreement->gateway, $group, $agreement, $attempt->key)
                : ExtensionHelper::charge($agreement->gateway, $invoice, $agreement, null, $attempt->key);
            if ($charged !== true) {
                throw new DisplayException(__('Could not process payment. Please try again or use a different payment method.'));
            }
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_SUCCEEDED);
        } catch (ChargeNeedsAction $e) {
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_ACTION_REQUIRED, $e->getMessage());
        } catch (Throwable $e) {
            if (!$e instanceof DisplayException) {
                report($e);
            }
            $this->finish($attempt, $agreement, ChargeAttempt::STATUS_FAILED, $e->getMessage());
        }

        return $attempt;
    }

    /**
     * A charge sent in the last HOLD_MINUTES and not declined (no answer yet, or taken by the gateway while its payment is
     * still to be recorded from the webhook), or a payment the gateway is still processing (SEPA, a PayPal review):
     * paying again would pay twice.
     */
    public static function onItsWay(Invoice $invoice): bool
    {
        return ChargeAttempt::query()->where('invoice_id', $invoice->id)
            ->whereIn('status', [ChargeAttempt::STATUS_STARTED, ChargeAttempt::STATUS_SUCCEEDED])
            ->where('started_at', '>', now()->subMinutes(ChargeAttempt::HOLD_MINUTES))->exists()
            || $invoice->transactions()->where('status', InvoiceTransactionStatus::Processing)->exists();
    }

    private function finish(ChargeAttempt $attempt, BillingAgreement $agreement, string $status, ?string $error = null): void
    {
        $attempt->update(['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 250), 'finished_at' => now()]);
        // The failures in a row of the method (decision 5): the cron stops at the cap, a success starts again.
        if ($status === ChargeAttempt::STATUS_SUCCEEDED) {
            BillingAgreement::query()->whereKey($agreement->id)->update(['failed_count' => 0]);
        } elseif ($attempt->source === ChargeAttempt::SOURCE_CRON) {
            BillingAgreement::query()->whereKey($agreement->id)->update(['failed_count' => DB::raw('failed_count + 1'), 'last_failed_at' => now()]);
        }
    }
}

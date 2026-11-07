<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Helpers\NotificationHelper;
use App\Models\GatewayRefund;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Services\Money\GatewayLog;
use App\Services\Money\MoneyTrail;
use App\Services\Money\PaymentsLog;
use App\Services\Payment\SubscriptionEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The safety net for WHMCS's PayPal IPN after the move (PR H2b, sweep Π7.9, decision (a)): the legacy PayPal
 * subscriptions are moved to the PayPal vault, and one that still pays (or a WHMCS-era one-off payment, a refund, a
 * cancellation) calls WHMCS's IPN URL. No money is recorded from here (a subscription that ended is only cleared from the
 * services that still carry it: SubscriptionEnded): an IPN that PayPal confirms is genuine is kept in the
 * gateway log and told to staff once, with what they need to find it in PayPal and record it by hand. A forged one is
 * refused quietly, and so is a genuine one for someone else's PayPal account (anyone can point an IPN of their own at
 * this address) or from PayPal's sandbox in production (code review CR-1). PayPal down: 503, so it sends the IPN again.
 */
class LegacyPayPalIpnController extends Controller
{
    private const VERIFY_LIVE = 'https://ipnpb.paypal.com/cgi-bin/webscr';

    private const VERIFY_SANDBOX = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    /** An IPN is a few KB: a larger body is not PayPal's (code review CR-3). */
    public const MAX_BYTES = 20000;

    /**
     * Forged requests (PayPal answered INVALID) an address may make a minute before we stop calling PayPal for it (CR-3).
     * Only forged ones count, so a genuine IPN never spends the budget; one that comes past it is answered 503 and PayPal
     * sends it again later (adversarial ADV-3). Behind a proxy, set the trusted proxies so that each caller has its address.
     */
    private const FORGED_PER_MINUTE = 30;

    /** MyCS's PayPal gateways: their payments are recorded by their own answers and webhooks. */
    private const GATEWAYS = ['PayPal', 'PayPal_IPN'];

    /**
     * The notices of a subscription or recurring profile that has ended for good: cancelled, at its end, expired. Not a
     * suspension: it may be activated again, and an IPN has no status to ask (code review of #605).
     */
    private const ENDED = ['subscr_cancel', 'subscr_eot', 'recurring_payment_profile_cancel', 'recurring_payment_expired'];

    public function __invoke(Request $request): Response
    {
        // PayPal checks the message as it sent it: the raw body, not the parsed fields (which trimming changes).
        $raw = $request->getContent();
        if ($raw === '') {
            $raw = http_build_query($request->request->all());
        }
        if (strlen($raw) > self::MAX_BYTES) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'too_large');

            return response('', 413);
        }
        $limit = 'paypal-legacy-ipn-forged:' . $request->ip();
        if (RateLimiter::tooManyAttempts($limit, self::FORGED_PER_MINUTE)) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'throttled');

            return response('', 503);
        }
        parse_str($raw, $fields);
        $sandbox = ($fields['test_ipn'] ?? null) === '1';
        if ($sandbox && app()->isProduction()) {
            // PayPal's IPN simulator answers VERIFIED to anyone: never in production (CR-1).
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'sandbox_refused');

            return response('', 400);
        }
        $url = $sandbox ? self::VERIFY_SANDBOX : self::VERIFY_LIVE;
        try {
            $answer = Http::connectTimeout(5)->timeout(10)->withBody('cmd=_notify-validate&' . $raw, 'application/x-www-form-urlencoded')->post($url);
        } catch (Throwable $e) {
            report($e);

            return response('', 503);
        }
        if (!$answer->successful()) {
            return response('', 503);
        }
        $verified = trim($answer->body()) === 'VERIFIED';
        GatewayLog::current()->signature($verified);
        if (!$verified) {
            RateLimiter::hit($limit, 60);
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'not_verified');

            return response('', 400);
        }

        // Ours only: a genuine IPN of someone else's PayPal account pointed at this address is not our money (CR-1).
        if (self::receivers() === []) {
            // Not looked at until the accounts are set: 503, so PayPal sends it again (for days) and nothing is lost in
            // the meantime (adversarial ADV-4). Staff are told daily to set them.
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_ERROR, 'receivers_not_set');
            if (Cache::add('paypal-legacy-ipn:receivers-not-set', true, now()->addDay())) {
                NotificationHelper::sendSystemEmailNotification('PayPal legacy notice not checked', e(
                    "A PayPal notice reached WHMCS's old IPN address, but no PayPal account of the WHMCS era is set (Settings, «PayPal accounts of the WHMCS era»). PayPal sends it again for a few days: set it so that such notices are told to staff."));
            }

            return response('', 503);
        }
        $receiver = self::receiver($fields);
        if ($receiver === null) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'foreign_receiver');

            return response('', 200);
        }

        $type = self::field($fields, 'txn_type') ?: 'none';
        $status = self::field($fields, 'payment_status');
        $txn = self::field($fields, 'txn_id');
        // The subscription or profile it is about: notices without a transaction carry one of these (adversarial ADV-1).
        $subscription = self::field($fields, 'subscr_id') ?: self::field($fields, 'recurring_payment_id') ?: self::field($fields, 'mp_id');
        $amount = self::field($fields, 'mc_gross') ?: self::field($fields, 'mc_amount3');
        $currency = self::field($fields, 'mc_currency');
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, substr('legacy_' . $type, 0, 50), [
            'amount' => is_numeric($amount) ? $amount : null, 'currency_code' => $currency, 'external_id' => $txn ?: $subscription,
        ]);

        // The one thing done from here: a service that still carries the ended subscription no longer does, so its
        // invoices are charged and paid as any other (SubscriptionEnded), its PayPal marker with it (ADV-605-4). Nothing
        // about money is recorded. Only an end for good: nothing activates a cancelled or expired subscription again.
        $cleared = in_array($type, self::ENDED, true) && $subscription !== ''
            ? SubscriptionEnded::clear('PayPal', $subscription, 'legacy IPN ' . $type, ['has_paypal_subscription']) : [];

        $kind = match (true) {
            $status === 'Completed' => 'money',
            in_array($status, ['Refunded', 'Reversed', 'Canceled_Reversal'], true) => 'reversal',
            default => 'notice',
        };
        // MyCS's own transaction (the account's IPN setting points every one at this old address: vault charges, checkouts,
        // MyCS's refunds) or one imported with its invoice: recorded already, never «record it by hand» (ADV-A4-4).
        $recorded = $kind === 'notice' ? null : self::recordedHere($kind, $status, $txn, self::field($fields, 'parent_txn_id'));
        if ($recorded !== null) {
            PaymentsLog::info("PayPal sent a {$type} notice ({$status}) of {$txn} to WHMCS's old IPN address: already recorded here ({$recorded}), nothing to do.");

            return response('', 200);
        }
        $message = sprintf(
            "PayPal sent a %s notice (%s) for %s to WHMCS's old IPN address. %s\n- transaction: %s\n- subscription: %s\n- amount: %s %s\n- WHMCS invoice (item_number): %s",
            $type, $status ?: 'no payment status', $receiver,
            match ($kind) {
                'money' => 'The money WAS taken by PayPal and is NOT recorded here: find it in PayPal by its transaction and record it on the customer\'s invoice by hand.',
                // A refund or a chargeback of a WHMCS-era payment: staff act on it (a credit note, the service) (CR-5).
                'reversal' => 'Money was given back or taken back by PayPal (a refund, a chargeback) and is NOT recorded here: check it in PayPal and act on the customer\'s invoice and service.',
                default => 'No money moved; check it in PayPal (a cancelled or ended legacy subscription has nothing left to do).'
                    . ($cleared !== [] ? ' Its subscription id was cleared from service #' . implode(', #', $cleared) . ': its invoices are charged and paid as any other now.' : ''),
            },
            $txn ?: '-', $subscription ?: '-', $amount ?: '-', $currency ?: '', self::field($fields, 'item_number') ?: '-');
        PaymentsLog::warning($message);
        // Once each transaction and status: PayPal sends an IPN again until answered, and the IPN of a payment may come
        // more than once. Marked once the mail is queued: a mail that fails is sent with PayPal's next try (CR-6).
        $key = 'paypal-legacy-ipn:' . sha1($type . '|' . ($txn ?: $subscription ?: self::field($fields, 'ipn_track_id')) . '|' . $status);
        if (!Cache::has($key)) {
            NotificationHelper::sendSystemEmailNotification(match ($kind) {
                'money' => 'PayPal payment not recorded',
                'reversal' => 'PayPal refund or reversal not recorded',
                default => 'PayPal legacy notice',
            }, e($message));
            Cache::put($key, true, now()->addDays(30));
        }

        return response('', 200);
    }

    /**
     * Where this site has the IPN's money already («invoice #N»), or null: a payment (txn_id) recorded on an invoice; a
     * refund or reversal recorded by its id (PayPal's refund id is its txn_id), or of a payment MyCS took through its own
     * PayPal gateway (parent_txn_id: its webhook records what PayPal gives or takes back). Not a WHMCS-era payment's
     * reversal, nor one of a payment staff recorded by hand: nothing here records that, staff do (ADV-A4-4, R4-1).
     */
    private static function recordedHere(string $kind, string $status, string $txn, string $parent): ?string
    {
        $invoice = fn (?int $id) => $id === null ? null : 'invoice #' . $id;
        if ($kind === 'money') {
            // A PayPal payment of ours (its gateways, or one staff recorded by hand, no gateway, as gatewayPayment matches),
            // succeeded; or processing on a PayPal gateway: its own webhook completes it. Never another gateway's row with
            // the same id, nor a hand row not succeeded (review of #694, F3).
            return $txn === '' ? null : $invoice(InvoiceTransaction::query()->where('transaction_id', $txn)->where('is_credit_transaction', false)
                ->where(fn ($ours) => $ours
                    ->where(fn ($gateway) => $gateway->whereHas('gateway', fn ($gw) => $gw->whereIn('extension', self::GATEWAYS))
                        ->whereIn('status', [InvoiceTransactionStatus::Succeeded, InvoiceTransactionStatus::Processing]))
                    ->orWhere(fn ($hand) => $hand->whereNull('gateway_id')->where('status', InvoiceTransactionStatus::Succeeded)))
                ->orderBy('id')->value('invoice_id'));
        }
        // A refund's own id only for money that went back: a Canceled_Reversal is money coming back to us (R4-3). Recorded
        // only when it was: a row that recorded nothing (another currency, nothing left on the payment) is staff's, as its
        // webhook said; one matched to a refund by hand, WHMCS's own imported or a won chargeback is the money's record
        // even at 0 (review of #694, F8).
        $refund = $txn === '' || $status === 'Canceled_Reversal' ? null : GatewayRefund::query()->where('gateway', 'PayPal')->where('reference', $txn)
            ->whereIn('origin', [...GatewayRefund::RECORDED_REFUND_ORIGINS, GatewayRefund::ORIGIN_CHARGEBACK, GatewayRefund::ORIGIN_CHARGEBACK_WON])
            ->where(fn ($recorded) => $recorded->where('recorded_amount', '>', 0)
                ->orWhereIn('origin', [GatewayRefund::ORIGIN_HAND, GatewayRefund::ORIGIN_IMPORT, GatewayRefund::ORIGIN_CHARGEBACK_WON]))
            ->first();
        if ($refund !== null) {
            return $invoice(InvoiceTransaction::query()->whereKey($refund->invoice_transaction_id)->value('invoice_id')) ?? 'refund ' . $refund->id;
        }

        return $parent === '' ? null : $invoice(InvoiceTransaction::query()->where('transaction_id', $parent)->where('is_credit_transaction', false)
            ->whereHas('gateway', fn ($gw) => $gw->where('extension', 'PayPal'))
            // The gateway recorded it itself: its payment event carries the provider's id, which one staff entered by hand
            // (a legacy Standard sale this controller told them to record) or the importer wrote never does (R4-1).
            ->whereExists(fn ($own) => $own->from('money_events')->whereColumn('money_events.invoice_transaction_id', 'invoice_transactions.id')
                ->where('money_events.kind', MoneyEventKind::Payment->value)->whereNotNull('money_events.external_reference')
                ->where('money_events.source', '!=', MoneyTrail::SOURCE_IMPORT))
            ->orderBy('id')->value('invoice_id'));
    }

    /**
     * The receiver the IPN names (receiver_email, receiver_id or business), when it is one of our PayPal accounts.
     *
     * @param  array<mixed>  $fields
     */
    private static function receiver(array $fields): ?string
    {
        $ours = self::receivers();
        foreach (['receiver_email', 'receiver_id', 'business'] as $name) {
            $value = strtolower(self::field($fields, $name));
            if ($value !== '' && in_array($value, $ours, true)) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> our PayPal accounts of the WHMCS era, lower case */
    private static function receivers(): array
    {
        return array_values(array_filter(array_map(fn (string $value) => strtolower(trim($value)),
            explode(',', (string) config('settings.paypal_legacy_receivers', '')))));
    }

    /** @param  array<mixed>  $fields */
    private static function field(array $fields, string $name): string
    {
        $value = $fields[$name] ?? '';

        // No control characters: a field never starts a new line of the log or the mail (adversarial ADV-2).
        return is_string($value) ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 100) : '';
    }
}

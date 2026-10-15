<?php

namespace App\Http\Controllers;

use App\Helpers\NotificationHelper;
use App\Models\PaymentGatewayEvent;
use App\Services\Money\GatewayLog;
use App\Services\Money\PaymentsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The safety net for WHMCS's PayPal IPN after the move (PR H2b, sweep Π7.9, decision (a)): the legacy PayPal
 * subscriptions are moved to the PayPal vault, and one that still pays (or a WHMCS-era one-off payment, a refund, a
 * cancellation) calls WHMCS's IPN URL. Nothing is recorded from here: an IPN that PayPal confirms is genuine is kept in the
 * gateway log and told to staff once, with what they need to find it in PayPal and record it by hand. A forged one is
 * refused quietly. PayPal down: 503, so it sends the IPN again.
 */
class LegacyPayPalIpnController extends Controller
{
    private const VERIFY_LIVE = 'https://ipnpb.paypal.com/cgi-bin/webscr';

    private const VERIFY_SANDBOX = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    public function __invoke(Request $request): Response
    {
        // PayPal checks the message as it sent it: the raw body, not the parsed fields (which trimming changes).
        $raw = $request->getContent();
        if ($raw === '') {
            $raw = http_build_query($request->request->all());
        }
        parse_str($raw, $fields);
        $url = ($fields['test_ipn'] ?? null) === '1' ? self::VERIFY_SANDBOX : self::VERIFY_LIVE;
        try {
            $answer = Http::timeout(30)->withBody('cmd=_notify-validate&' . $raw, 'application/x-www-form-urlencoded')->post($url);
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
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'not_verified');

            return response('', 400);
        }

        $type = self::field($fields, 'txn_type') ?: 'none';
        $status = self::field($fields, 'payment_status');
        $txn = self::field($fields, 'txn_id');
        $subscription = self::field($fields, 'subscr_id');
        $amount = self::field($fields, 'mc_gross') ?: self::field($fields, 'mc_amount3');
        $currency = self::field($fields, 'mc_currency');
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, substr('legacy_' . $type, 0, 50), [
            'amount' => is_numeric($amount) ? $amount : null, 'currency_code' => $currency, 'external_id' => $txn ?: $subscription,
        ]);

        $money = $status === 'Completed';
        $message = sprintf(
            "PayPal sent a %s notice (%s) to WHMCS's old IPN address. %s\n- transaction: %s\n- subscription: %s\n- amount: %s %s\n- WHMCS invoice (item_number): %s",
            $type, $status ?: 'no payment status',
            $money ? 'The money WAS taken by PayPal and is NOT recorded here: find it in PayPal by its transaction and record it on the customer\'s invoice by hand.'
                : 'No money was recorded here; check it in PayPal (a cancelled or ended legacy subscription has nothing left to do).',
            $txn ?: '-', $subscription ?: '-', $amount ?: '-', $currency ?: '', self::field($fields, 'item_number') ?: '-');
        PaymentsLog::warning($message);
        // Once each: PayPal sends an IPN again until answered, and the IPN of a payment may come more than once.
        $key = 'paypal-legacy-ipn:' . sha1($type . '|' . ($txn ?: $subscription) . '|' . $status);
        if (Cache::add($key, true, now()->addDays(30))) {
            NotificationHelper::sendSystemEmailNotification($money ? 'PayPal payment not recorded' : 'PayPal legacy notice', e($message));
        }

        return response('', 200);
    }

    /** @param  array<mixed>  $fields */
    private static function field(array $fields, string $name): string
    {
        $value = $fields[$name] ?? '';

        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }
}

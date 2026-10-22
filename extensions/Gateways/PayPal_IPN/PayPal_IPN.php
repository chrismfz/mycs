<?php

namespace MyCS\Extensions\Gateways\PayPal_IPN;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Http\Controllers\LegacyPayPalIpnController;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Services\Money\GatewayLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

#[ExtensionMeta(
    name: 'PayPal IPN',
    description: 'Accept payments via PayPal. With only your email',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgNTEyIDUxMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICAgIDxyZWN0IHdpZHRoPSI1MTIiIGhlaWdodD0iNTEyIiBmaWxsPSIjRjVGNkY4IiAvPgogICAgPHBhdGggZD0iTTMzNi4zOTcgMTgxLjQ4QzMzNi4zOTcgMjE1LjY2NyAzMDQuODQ3IDI1NiAyNTcuMTExIDI1NkgyMTEuMTI5TDIwOC44NzIgMjcwLjI0MkwxOTguMTQ1IDMzOC44SDE0MUwxNzUuMzc4IDExOEgyNjcuOTYxQzI5OS4xMzcgMTE4IDMyMy42NjQgMTM1LjM3NiAzMzIuNjk4IDE1OS41MjNDMzM1LjMwNCAxNjYuNTQzIDMzNi41NTkgMTczLjk5MyAzMzYuMzk3IDE4MS40OFoiIGZpbGw9IiMwMDI5OTEiIC8+CiAgICA8cGF0aCBkPSJNMzY5LjMzMSAyNDQuOTZDMzYzLjAzMSAyODMuMjM3IDMyOS44OTggMzExLjI5MyAyOTEuMTA2IDMxMS4ySDI1OS4xNzZMMjQ1Ljg4NSAzOTRIMTg5LjA0N0wxOTguMTQzIDMzOC44TDIwOC44NzYgMjcwLjI0MUwyMTEuMTI3IDI1NkgyNTcuMTA5QzMwNC43ODMgMjU2IDMzNi4zOTUgMjE1LjY2NyAzMzYuMzk1IDE4MS40NzlDMzU5Ljg1NSAxOTMuNTg3IDM3My41MzIgMjE4LjA1MyAzNjkuMzMxIDI0NC45NloiIGZpbGw9IiM2MENERkYiIC8+CiAgICA8cGF0aCBkPSJNMzM2LjM5NyAxODEuNDhDMzI2LjU1OSAxNzYuMzM0IDMxNC42MjkgMTczLjIgMzAxLjY0NSAxNzMuMkgyMjQuMTE5TDIxMS4xMjkgMjU2SDI1Ny4xMTFDMzA0Ljc4NSAyNTYgMzM2LjM5NyAyMTUuNjY3IDMzNi4zOTcgMTgxLjQ4WiIgZmlsbD0iIzAwOENGRiIgLz4KPC9zdmc+Cg=='
)]
class PayPal_IPN extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    /**
     * Get all the configuration for the extension
     *
     * @param  array  $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'email',
                'label' => 'PayPal Email',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'required' => false,
            ],
        ];
    }

    public function supportsPaymentGroups(): bool
    {
        return true;
    }

    /**
     * Return a view or a url to redirect to
     *
     * @param  float  $total
     * @return string
     */
    public function pay(Invoice $invoice, $total)
    {
        $paypal_url = $this->config('test_mode') ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
        $paypal_email = $this->config('email');
        $return_url = route('invoices.show', $invoice);
        $cancel_url = route('invoices.show', $invoice);

        $notify_url = route('extensions.gateways.paypal_ipn.notify');

        $query = '?cmd=_xclick';
        $query .= '&item_name=' . urlencode('Invoice #' . $invoice->id);
        $query .= '&item_number=' . $invoice->id;
        if ($invoice->payingGroup !== null) {
            $query .= '&custom=' . urlencode($invoice->payingGroup);
        }
        $query .= '&amount=' . number_format($total, 2, '.', '');
        $query .= '&currency_code=' . $invoice->currency_code;
        $query .= '&business=' . $paypal_email;
        $query .= '&notify_url=' . $notify_url;
        $query .= '&return=' . $return_url;
        $query .= '&cancel_return=' . $cancel_url;

        return $paypal_url . $query;
    }

    /** Handle the IPN request */
    public function notify(Request $request): Response
    {
        // PayPal checks the message as it sent it: the raw body, not the parsed fields (which the app's middleware trims
        // and nulls), as the WHMCS-era IPN does (LegacyPayPalIpnController).
        $raw = $request->getContent();
        if ($raw === '') {
            $raw = http_build_query($request->request->all());
        }
        if (strlen($raw) > LegacyPayPalIpnController::MAX_BYTES) {
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_REJECTED, 'too_large');

            return response('', 413);
        }
        $url = $this->config('test_mode') ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
        try {
            $response = Http::connectTimeout(5)->timeout(10)->withBody('cmd=_notify-validate&' . $raw, 'application/x-www-form-urlencoded')->post($url);
        } catch (\Throwable $e) {
            report($e);

            return response('', 503);
        }
        // PayPal itself did not answer: it sends the IPN again later. A forged one is refused (400), which counts toward
        // the callbacks' limit (security audit PAY-SEC-8), as the WHMCS-era IPN's own does.
        if (!$response->successful()) {
            return response('', 503);
        }
        $verified = trim($response->body()) === 'VERIFIED';
        GatewayLog::current()->signature($verified);
        if (!$verified) {
            return response('', 400);
        }
        // The fields as PayPal sent them (the app's middleware trims and nulls the parsed ones).
        parse_str($raw, $fields);
        $field = fn (string $key): ?string => is_string($fields[$key] ?? null) ? $fields[$key] : null;
        // Validate owner and amount
        if (strtolower((string) $field('receiver_email')) !== strtolower((string) $this->config('email'))) {
            return response('');
        }
        // Money given or taken back on a payment of ours (security audit PAY-SEC-5): recorded as Stripe's are, once by
        // its own id, the invoice owed again and staff told (a refund, a chargeback, a chargeback won back).
        $parent = (string) $field('parent_txn_id');
        $amount = abs((float) $field('mc_gross'));
        $reference = (string) $field('txn_id');
        $backward = in_array($field('payment_status'), ['Refunded', 'Reversed', 'Canceled_Reversal'], true);
        if ($backward && ($parent === '' || $reference === '')) {
            return response(''); // nothing to match it to: the gateway log keeps it for staff
        }
        switch ($field('payment_status')) {
            case 'Refunded':
                ExtensionHelper::refundedAtGateway('PayPal', $parent, $reference, $amount, $field('mc_currency'));

                return response('');
            case 'Reversed':
                ExtensionHelper::chargebackAtGateway('PayPal', $parent, $reference, $amount, $field('mc_currency'));

                return response('');
            case 'Canceled_Reversal':
                ExtensionHelper::chargebackReversedAtGateway('PayPal', $parent, null, $amount, $field('mc_currency'), $reference !== '' ? $reference : null);

                return response('');
            case 'Completed':
                break;
            default:
                return response('');
        }
        // Another currency is refused by RecordPaymentService (logged, staff told), not skipped here.
        $invoice = Invoice::find($field('item_number'));
        if (!$invoice) {
            return response('');
        }
        ExtensionHelper::addPayment($invoice, 'PayPal', $field('mc_gross'), $field('mc_fee'), transactionId: $field('txn_id'),
            currency: $field('mc_currency'), paymentGroup: $field('custom'));

        return response('');
    }
}

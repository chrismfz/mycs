<?php

namespace MyCS\Extensions\Gateways\Mollie;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayReference;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[ExtensionMeta(
    name: 'Mollie Gateway',
    description: 'Accept payments via Mollie.',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODciIGhlaWdodD0iNTgiIHZpZXdib3g9IjAgMCA4NyA1OCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBjbGFzcz0iZ3JvdXAtaG92ZXIvYXBwOnNjYWxlLTEyMCBmbGV4IGl0ZW1zLWNlbnRlciBqdXN0aWZ5LWNlbnRlciBzaXplLTE4IHJvdW5kZWQteGwgcC00IGJnLWJsYWNrIHJpbmctNiByaW5nLXdoaXRlLzEwIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGNsaXAtcnVsZT0iZXZlbm9kZCIgZD0iTTYzLjkyMiAwLjg1MjA5NkM2My4xOTkgMC43NjE3MTkgNjIuNDc2IDAuNzYxNzE5IDYxLjc1MjkgMC43NjE3MTlDNTQuNzkzOSAwLjc2MTcxOSA0OC4xOTY1IDMuNTYzMzkgNDMuNDk2OSA4LjYyNDQ4QzM4Ljc5NzMgMy42NTM3NyAzMi4xOTk4IDAuNzYxNzE5IDI1LjMzMTIgMC43NjE3MTlDMTEuNTAzNiAwLjc2MTcxOSAwLjI5Njg3NSAxMS45Njg0IDAuMjk2ODc1IDI1LjYxNTNWNTYuOTc1OUgxMy43NjNWMjYuMDY3MkMxMy43NjMgMjAuMzczNCAxOC40NjI2IDE1LjEzMTYgMjMuOTc1NSAxNC41ODkzQzI0LjMzNyAxNC41ODkzIDI0Ljc4ODkgMTQuNDk5IDI1LjE1MDQgMTQuNDk5QzMxLjM4NjQgMTQuNDk5IDM2LjQ0NzUgMTkuNTYgMzYuNDQ3NSAyNS43OTZWNTcuMDY2M0g1MC4xODQ3VjI1Ljk3NjhDNTAuMTg0NyAyMC4yODMxIDU0Ljg4NDMgMTUuMDQxMiA2MC4zOTczIDE0LjQ5OUM2MC43NTg4IDE0LjQ5OSA2MS4yMTA3IDE0LjQwODYgNjEuNTcyMiAxNC40MDg2QzY3LjgwODIgMTQuNDA4NiA3Mi44NjkzIDE5LjQ2OTcgNzIuOTU5NiAyNS42MTUzVjU2Ljk3NTlIODYuNjk2OVYyNi4wNjcyQzg2LjY5NjkgMTkuNzQwOCA4NC4zNDcxIDEzLjc3NTkgODAuMTg5OCA5LjA3NjM2Qzc1Ljk0MjEgNC4yODY0MSA3MC4xNTggMS4zOTQzNiA2My45MjIgMC44NTIwOTZaIiBmaWxsPSJ3aGl0ZSI+PC9wYXRoPjwvc3ZnPg==',
)]
class Mollie extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
    }

    /** @throws Exception Mollie's error, with its HTTP status as the code */
    private function request($url, $method = 'get', $data = [], ?string $idempotencyKey = null)
    {
        $response = Http::withHeaders(array_filter([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Content-Type' => 'application/json',
            'Idempotency-Key' => $idempotencyKey,
        ]))->$method('https://api.mollie.com' . $url, $data);

        if (!$response->successful()) {
            $detail = $response->json('detail');
            throw new Exception('Mollie API error: ' . (is_string($detail) ? $detail : 'HTTP ' . $response->status()), $response->status());
        }

        return $response->json();
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
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'text',
                'encrypted' => true,
                'required' => true,
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
        $response = $this->request('/v2/payments', 'post', [
            'amount' => [
                'currency' => $invoice->currency_code,
                'value' => number_format($total, 2, '.', ''),
            ],
            'description' => 'Invoice #' . $invoice->id,
            'redirectUrl' => route('invoices.show', $invoice) . '?checkPayment=true',
            'cancelUrl' => route('invoices.show', $invoice),
            'webhookUrl' => route('extensions.gateways.mollie.webhook', $invoice),
            'metadata' => array_filter([
                'mycs' => GatewayReference::mark(), // this site's payment (S0-6)
                'invoice_id' => $invoice->id,
                'payment_group' => $invoice->payingGroup,
            ]),
        ]);

        return $response['_links']['checkout']['href'];
    }

    /** @return list<array<string, mixed>> A payment's refunds or chargebacks at Mollie (an error: Mollie retries). */
    private function listOf(string $paymentId, string $what): array
    {
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->config('api_key')])
            ->get('https://api.mollie.com/v2/payments/' . $paymentId . '/' . $what);
        if (!$response->successful()) {
            throw new Exception('Mollie API error: ' . $response->status());
        }

        return array_values(array_filter((array) ($response->json('_embedded.' . $what) ?? []), 'is_array'));
    }

    public function webhook(Request $request)
    {
        // Mollie only sends an id: one it does not know (anyone can post one) is a 404, not an error that mails staff
        // (MT-2a). Mollie being down stays an error, so it retries.
        $id = $request->input('id');
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $id) !== 1) {
            return response('', 400);
        }
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->config('api_key')])->get('https://api.mollie.com/v2/payments/' . $id);
        if ($response->clientError()) {
            return response('', 404);
        }
        if (!$response->successful()) {
            throw new Exception('Mollie API error: ' . $response->status());
        }
        $payment = $response->json();
        if (!GatewayReference::isOurs($payment['metadata']['mycs'] ?? null)) {
            // Another system's payment on this account: never ours, staff told (S0-6).
            GatewayReference::foreign('Mollie', 'payment ' . ($payment['status'] ?? '?'), $payment['id'] ?? $id, $payment['amount']['value'] ?? null, $payment['amount']['currency'] ?? null);

            return response('', 200);
        }

        if ($payment['status'] == 'paid') {
            ExtensionHelper::addPayment($payment['metadata']['invoice_id'], 'Mollie', $payment['amount']['value'], transactionId: $payment['id'], currency: $payment['amount']['currency'] ?? null, paymentGroup: $payment['metadata']['payment_group'] ?? null);
        }

        // Money given or taken back later (security audit PAY-SEC-5): Mollie calls this webhook again, for the same
        // payment. Each refund and chargeback is recorded once by its own id, as Stripe's are; a chargeback reversed
        // (won back) undoes it.
        // A refund that failed takes amountRefunded back to 0: its link still says there are refunds (review of #349, F2).
        if ((float) ($payment['amountRefunded']['value'] ?? 0) > 0 || isset($payment['_links']['refunds'])) {
            foreach ($this->listOf($id, 'refunds') as $refund) {
                if (!is_string($refund['id'] ?? null)) {
                    continue;
                }
                $amount = (float) ($refund['amount']['value'] ?? 0);
                $currency = $refund['amount']['currency'] ?? null;
                match ($refund['status'] ?? null) {
                    'pending', 'processing', 'refunded' => ExtensionHelper::refundedAtGateway('Mollie', $id, $refund['id'], $amount, $currency),
                    // Recorded earlier as money given back (or started here) and then not: staff told, as for Stripe.
                    'failed', 'canceled' => ExtensionHelper::refundFailedAtGateway('Mollie', $refund['id'], $id, $amount, $currency),
                    default => null, // queued (Mollie's balance too low): recorded once it is pending
                };
            }
        }
        if ((float) ($payment['amountChargedBack']['value'] ?? 0) > 0 || isset($payment['_links']['chargebacks'])) {
            foreach ($this->listOf($id, 'chargebacks') as $chargeback) {
                if (!is_string($chargeback['id'] ?? null)) {
                    continue;
                }
                $amount = (float) ($chargeback['amount']['value'] ?? 0);
                $currency = $chargeback['amount']['currency'] ?? null;
                ExtensionHelper::chargebackAtGateway('Mollie', $id, $chargeback['id'], $amount, $currency);
                if (!empty($chargeback['reversedAt'])) {
                    ExtensionHelper::chargebackReversedAtGateway('Mollie', $id, $chargeback['id'], $amount, $currency, $chargeback['id'] . ':reversed');
                }
            }
        }

        return response('', 200);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /** @return string|bool Mollie's id of the refund, when it gives one */
    public function refund(InvoiceTransaction $transaction, $amount): string|bool
    {
        $paymentId = $transaction->transaction_id;

        if (!$paymentId) {
            throw new Exception('Transaction has no Mollie payment ID.');
        }

        $data = [
            'amount' => [
                'currency' => $transaction->invoice->currency_code,
                'value' => number_format($amount, 2, '.', ''),
            ],
        ];

        // Mollie's refunds of this payment, read before anything is sent (AUD2-1). One giving money back that MyCS has not
        // recorded by its id is a refund whose answer was lost and whose webhook has not come yet: refused, so a retry never
        // pays it a second time, however late (Mollie keeps an idempotency key for an hour only). By id, not by amount: a
        // refund recorded by hand or a chargeback must not hide it (ADV-517-R2-1, R2-2).
        $listed = $this->listOf($paymentId, 'refunds');
        $known = GatewayRefund::query()->where('invoice_transaction_id', $transaction->id)->where('gateway', 'Mollie')
            ->whereIn('origin', [GatewayRefund::ORIGIN_MYCS, GatewayRefund::ORIGIN_GATEWAY, GatewayRefund::ORIGIN_HAND])
            ->pluck('reference')->all();
        foreach ($listed as $made) {
            if (in_array($made['status'] ?? null, ['queued', 'pending', 'processing', 'refunded'], true) && !in_array($made['id'] ?? null, $known, true)) {
                throw new Exception('Mollie shows refund ' . ($made['id'] ?? '?') . ' of this payment, which MyCS has not recorded yet: wait for its webhook, or check Mollie, before refunding again.');
            }
        }

        // Two clicks of the same refund before Mollie lists the first share a key: Mollie gives the first answer back.
        // Any refund Mollie lists since, made, failed or canceled, makes a new key, so the next one is a refund of its own
        // (ADV-517-1, ADV-517-2).
        $key = 'refund-' . $transaction->id . '-' . count($listed) . '-' . RecordPaymentService::toCents($amount);
        try {
            $refund = $this->request('/v2/payments/' . $paymentId . '/refunds', 'post', $data, $key);
        } catch (ConnectionException $e) {
            $this->outcomeUnknown($transaction, $paymentId, $amount, $key, 'no answer');

            throw $e;
        } catch (Exception $e) {
            // Mollie's own failure (5xx), or the same key still being processed (409), may end in a refund: unknown, as a
            // timeout. Any other 4xx is a refusal.
            if ($e->getCode() >= 500 || $e->getCode() === 409) {
                $this->outcomeUnknown($transaction, $paymentId, $amount, $key, 'HTTP ' . $e->getCode());
            }

            throw $e;
        }

        // A refund that does not give money back is not one, whatever the key handed back (ADV-517-2).
        if (in_array($refund['status'] ?? null, ['failed', 'canceled'], true)) {
            throw new Exception('Mollie answered with refund ' . ($refund['id'] ?? '?') . ', which is ' . $refund['status'] . ': nothing was refunded.');
        }

        return (string) ($refund['id'] ?? '') ?: true;
    }

    private function outcomeUnknown(InvoiceTransaction $transaction, string $paymentId, $amount, string $key, string $why): void
    {
        Log::critical('Mollie refund outcome unknown (' . $why . '): check Mollie before retrying; MyCS refuses another refund of this payment until Mollie\'s refunds are recorded.', [
            'transaction_id' => $transaction->id, 'payment_id' => $paymentId, 'amount' => $amount, 'idempotency_key' => $key,
        ]);
    }
}

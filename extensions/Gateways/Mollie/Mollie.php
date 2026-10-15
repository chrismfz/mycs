<?php

namespace MyCS\Extensions\Gateways\Mollie;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    private function request($url, $method = 'get', $data = [])
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Content-Type' => 'application/json',
        ])->$method('https://api.mollie.com' . $url, $data);

        if (!$response->successful()) {
            throw new Exception('Mollie API error: ' . $response->json()['detail']);
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

    /**
     * Return a view or a url to redirect to
     *
     * @param  float  $total
     * @return string
     */
    public function supportsPaymentGroups(): bool
    {
        return true;
    }

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
                'invoice_id' => $invoice->id,
                'payment_group' => $invoice->payingGroup,
            ]),
        ]);

        return $response['_links']['checkout']['href'];
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

        if ($payment['status'] == 'paid') {
            ExtensionHelper::addPayment($payment['metadata']['invoice_id'], 'Mollie', $payment['amount']['value'], transactionId: $payment['id'], currency: $payment['amount']['currency'] ?? null, paymentGroup: $payment['metadata']['payment_group'] ?? null);
        }
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

        $refund = $this->request('/v2/payments/' . $paymentId . '/refunds', 'post', $data);

        return (string) ($refund['id'] ?? '') ?: true;
    }
}

<?php

namespace MyCS\Extensions\Gateways\PayPal;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Service\RenewalTaxChanged;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayLog;
use App\Support\CurrentClient;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

#[ExtensionMeta(
    name: 'PayPal Gateway',
    description: 'Accept payments via PayPal.',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgNTEyIDUxMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICAgIDxyZWN0IHdpZHRoPSI1MTIiIGhlaWdodD0iNTEyIiBmaWxsPSIjRjVGNkY4IiAvPgogICAgPHBhdGggZD0iTTMzNi4zOTcgMTgxLjQ4QzMzNi4zOTcgMjE1LjY2NyAzMDQuODQ3IDI1NiAyNTcuMTExIDI1NkgyMTEuMTI5TDIwOC44NzIgMjcwLjI0MkwxOTguMTQ1IDMzOC44SDE0MUwxNzUuMzc4IDExOEgyNjcuOTYxQzI5OS4xMzcgMTE4IDMyMy42NjQgMTM1LjM3NiAzMzIuNjk4IDE1OS41MjNDMzM1LjMwNCAxNjYuNTQzIDMzNi41NTkgMTczLjk5MyAzMzYuMzk3IDE4MS40OFoiIGZpbGw9IiMwMDI5OTEiIC8+CiAgICA8cGF0aCBkPSJNMzY5LjMzMSAyNDQuOTZDMzYzLjAzMSAyODMuMjM3IDMyOS44OTggMzExLjI5MyAyOTEuMTA2IDMxMS4ySDI1OS4xNzZMMjQ1Ljg4NSAzOTRIMTg5LjA0N0wxOTguMTQzIDMzOC44TDIwOC44NzYgMjcwLjI0MUwyMTEuMTI3IDI1NkgyNTcuMTA5QzMwNC43ODMgMjU2IDMzNi4zOTUgMjE1LjY2NyAzMzYuMzk1IDE4MS40NzlDMzU5Ljg1NSAxOTMuNTg3IDM3My41MzIgMjE4LjA1MyAzNjkuMzMxIDI0NC45NloiIGZpbGw9IiM2MENERkYiIC8+CiAgICA8cGF0aCBkPSJNMzM2LjM5NyAxODEuNDhDMzI2LjU1OSAxNzYuMzM0IDMxNC42MjkgMTczLjIgMzAxLjY0NSAxNzMuMkgyMjQuMTE5TDIxMS4xMjkgMjU2SDI1Ny4xMTFDMzA0Ljc4NSAyNTYgMzM2LjM5NyAyMTUuNjY3IDMzNi4zOTcgMTgxLjQ4WiIgZmlsbD0iIzAwOENGRiIgLz4KPC9zdmc+Cg=='
)]
class PayPal extends Gateway
{
    public function supportsBillingAgreements(): bool
    {
        return $this->config('paypal_support_billing_agreements') ?? false;
    }

    public function createBillingAgreement(Client $user)
    {
        // Using PayPal Vaulting
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $result = $this->request('post', $url . '/v3/vault/setup-tokens', [
            'payment_source' => [
                'paypal' => [
                    'description' => 'Billing Agreement for ' . config('settings.company_name'),
                    'usage_type' => 'MERCHANT',
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'return_url' => route('extensions.gateways.paypal.setup-agreement'),
                        'cancel_url' => route('account.payment-methods'),
                        'brand_name' => config('settings.company_name'),
                        'shipping_preference' => 'NO_SHIPPING',
                        'vault_instruction' => 'ON_PAYER_APPROVAL',
                    ],
                ],
            ],
        ]);

        if (!isset($result->links[1]->href)) {
            throw new Exception('Failed to create billing agreement.');
        }

        return $result->links[1]->href;
    }

    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        try {
            $this->request('delete', $url . '/v3/vault/payment-tokens/' . $billingAgreement->external_reference);
        } catch (PayPalApiException $e) {
            if ($e->status !== 404) { // 404: already deleted at PayPal, nothing left to cancel
                report($e);

                return false; // kept locally: PayPal still holds the token
            }
        }

        return true;
    }

    public function setupAgreement(Request $request)
    {
        $request->validate([
            'approval_token_id' => 'required|string|max:255',
        ]);

        $tokenId = $request->input('approval_token_id');
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $request = $this->request('post', $url . '/v3/vault/payment-tokens', [
            'payment_source' => [
                'token' => [
                    'id' => $tokenId,
                    'type' => 'SETUP_TOKEN',
                ],
            ],
        ]);

        $client = CurrentClient::model();
        abort_if($client === null, 403);
        ExtensionHelper::makeBillingAgreement(
            $client,
            'PayPal',
            'PayPal ' . $request->payment_source->paypal->email_address,
            $request->id,
            'paypal',
        );

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
        ]);
    }

    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement)
    {
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        try {
            $result = $this->request('post', $url . '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'invoice_id' => $invoice->id,
                        'amount' => [
                            'currency_code' => $invoice->currency_code,
                            'value' => number_format((float) $total, 2, '.', ''),
                        ],
                    ] + self::groupOf($invoice),
                ],
                'payment_source' => [
                    'paypal' => [
                        'vault_id' => $billingAgreement->external_reference,
                    ],
                ],
            ], $invoice->chargeKey); // the charge attempt's key (PR H): PayPal answers a repeated request with the same order
        } catch (PayPalApiException $e) {
            report($e);
            // A 5xx says nothing about the money: not a decline. Thrown as it is, so the attempt keeps its request id and is
            // sent again with it (PR H, code review).
            if ($e->status >= 500) {
                throw $e;
            }

            throw new DisplayException(__('Could not process payment. Please try again or use a different payment method.'), 0, $e);
        }

        $capture = $result->purchase_units[0]->payments->captures[0] ?? null;
        if (($result->status ?? null) !== 'COMPLETED' || !in_array($capture->status ?? null, ['COMPLETED', 'PENDING'], true)) {
            report(new Exception('PayPal did not capture the payment for invoice #' . $invoice->id . ' (order ' . ($result->status ?? 'unknown') . ', capture ' . ($capture->status ?? 'none') . ').'));

            throw new DisplayException(__('Could not process payment. Please try again or use a different payment method.'));
        }

        // Recorded now (a PENDING capture as processing, like capture()); the PAYMENT.CAPTURE.COMPLETED webhook
        // carries the same capture id, so it completes or repeats this idempotently. The money is taken: a
        // recording failure is reported, not shown as a failed charge (the webhook records it).
        try {
            ExtensionHelper::addPayment(
                $invoice->id,
                'PayPal',
                $capture->amount->value,
                $capture->seller_receivable_breakdown->paypal_fee->value ?? null,
                $capture->id,
                $capture->status === 'PENDING' ? InvoiceTransactionStatus::Processing : InvoiceTransactionStatus::Succeeded,
                currency: $capture->amount->currency_code ?? null,
                paymentGroup: $invoice->payingGroup,
            );
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.paypal', __DIR__ . '/resources/views');

        // The renewal's tax changed (Δ1): the subscription bills the new amount, as the renewal invoices.
        Event::listen(RenewalTaxChanged::class, function (RenewalTaxChanged $event) {
            if ($event->service->properties()->where('key', 'has_paypal_subscription')->value('value') !== '1') {
                return;
            }
            try {
                $this->updateSubscription($event->service);
            } catch (Exception $e) {
                report($e);
            }
        });
        Event::listen(Updated::class, function (Updated $event) {
            // From the database: an earlier event of the same commit may have cancelled and removed the marker.
            if ($event->service->properties()->where('key', 'has_paypal_subscription')->value('value') !== '1') {
                return;
            }
            // A failure is reported, never thrown: the service change is committed; a failed cancel keeps the marker.
            if ($event->changed('price')) {
                try {
                    $this->updateSubscription($event->service);
                } catch (Exception $e) {
                    report($e);
                }
            }
            if ($event->endedBySave()) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    report($e);
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if ($service->properties->where('key', 'has_paypal_subscription')->first()?->value !== '1') {
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                report($e);
            }
        });
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'client_id',
                'label' => 'Client ID',
                'type' => 'text',
                'description' => 'Find your API keys at https://developer.paypal.com/developer/applications',
                'required' => true,
            ],
            [
                'name' => 'client_secret',
                'label' => 'Client Secret',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Find your API keys at https://developer.paypal.com/developer/applications',
                'required' => true,
            ],
            [
                'name' => 'webhook_id',
                'label' => 'Webhook ID',
                'type' => 'text',
                'description' => 'Find your webhook ID at https://developer.paypal.com/developer/webhooks',
                'required' => true,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'description' => 'Enable test mode',
                'required' => false,
            ],
            [
                'name' => 'paypal_support_billing_agreements',
                'label' => 'Supports Billing Agreements',
                'type' => 'checkbox',
                'description' => new HtmlString('Enable this option if your PayPal account supports billing agreements.'),
                'required' => false,
            ],
        ];
    }

    private ?string $accessToken = null;

    /**
     * Cached on the instance; request() drops it on a 401 (a long-lived worker outlives the ~9h token).
     */
    private function generateAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        $result = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->config('client_id') . ':' . $this->config('client_secret')),
        ])->asForm()->post($url . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if ($result->failed()) {
            throw PayPalApiException::fromResponse($result);
        }

        return $this->accessToken = $result->json()['access_token'];
    }

    /**
     * @param  string|null  $requestId  PayPal-Request-Id: the same id for a retry of the same operation lets PayPal
     *                                  answer the first result instead of doing it twice (refunds); null = a new one
     *
     * @throws PayPalApiException when PayPal does not answer 2xx: an error body must never read as success
     */
    public function request($method, $url, $data = [], ?string $requestId = null)
    {
        $requestId ??= (string) Str::uuid();
        $send = fn () => Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->generateAccessToken(),
            'PayPal-Request-Id' => $requestId,
        ])->$method($url, $data);

        $response = $send();
        if ($response->status() === 401) { // expired token: a new one, same request id
            $this->accessToken = null;
            $response = $send();
        }

        if (!$response->successful()) {
            throw PayPalApiException::fromResponse($response);
        }

        return $response->object();
    }

    public function supportsPaymentGroups(): bool
    {
        return true;
    }

    /** The Mass Pay group the order is for (PR F2), as custom_id: PayPal returns it with the order. */
    private static function groupOf(Invoice $invoice): array
    {
        return $invoice->payingGroup === null ? [] : ['custom_id' => $invoice->payingGroup];
    }

    public function pay($invoice, $total)
    {
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $order = $this->request('post', $url . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => [
                        'currency_code' => $invoice->currency_code,
                        'value' => number_format((float) $total, 2, '.', ''),
                    ],
                ] + self::groupOf($invoice),
            ],
            'application_context' => [
                'return_url' => route('invoices.show', $invoice),
                'cancel_url' => route('invoices.show', $invoice),
                // Disable shipping information
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ]);

        return view('gateways.paypal::pay', ['invoice' => $invoice, 'total' => $total, 'order' => $order ?? null, 'clientId' => $this->config('client_id')]);
    }

    public function capture(Invoice $invoice, string $orderID)
    {
        // Validate the order ID format
        if (!preg_match('/^[A-Z0-9-]+$/', $orderID)) {
            abort(400);
        }
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        // First check if the order is already captured
        try {
            $order = $this->request('get', $url . '/v2/checkout/orders/' . $orderID);
        } catch (PayPalApiException $e) {
            if (!in_array($e->status, [404, 422], true)) {
                throw $e; // PayPal unavailable or our credentials: not the buyer's request
            }
            abort(400, 'PayPal order not found');
        }

        // Verify if the invoice id matches the one from the order
        if (!isset($order->purchase_units[0]->invoice_id) || $order->purchase_units[0]->invoice_id != $invoice->id) {
            abort(400, 'PayPal order is not associated with an invoice');
        }

        if ($order->status === 'COMPLETED') {
            return $order;
        }

        if ($order->status !== 'APPROVED') {
            abort(400, 'PayPal order is not approved for capture');
        }

        // Verify currency matches the invoice
        if ($order->purchase_units[0]->amount->currency_code !== $invoice->currency_code) {
            abort(400, 'PayPal order currency does not match invoice currency');
        }

        try {
            $response = $this->request('post', $url . '/v2/checkout/orders/' . $orderID . '/capture', [
                'intent' => 'CAPTURE',
            ]);
        } catch (PayPalApiException $e) {
            // PayPal's error as the JS SDK reads it: INSTRUMENT_DECLINED lets the buyer pick another funding source.
            return response()->json($e->toClientArray(), 422);
        }

        $capture = $response->purchase_units[0]->payments->captures[0] ?? null;
        if (($response->status ?? null) !== 'COMPLETED' || !in_array($capture->status ?? null, ['COMPLETED', 'PENDING'], true)) {
            abort(400, 'Failed to capture PayPal order');
        }

        // A PENDING capture (review, eCheck, unheld currency) is not money yet: processing until PAYMENT.CAPTURE.COMPLETED.
        ExtensionHelper::addPayment(
            $order->purchase_units[0]->invoice_id,
            'PayPal',
            $capture->amount->value,
            $capture->seller_receivable_breakdown->paypal_fee->value ?? null,
            $capture->id,
            $capture->status === 'PENDING' ? InvoiceTransactionStatus::Processing : InvoiceTransactionStatus::Succeeded,
            currency: $capture->amount->currency_code ?? null,
            paymentGroup: $order->purchase_units[0]->custom_id ?? null,
        );

        return $response;
    }

    /**
     * One subscription sale pays every service of the subscription (docs/payments.md): the oldest open invoice
     * of each service, one cycle per service. The sale is recorded ONCE, on the first of those invoices; what
     * that invoice cannot take becomes account credit and pays the others (RecordPaymentService::
     * allocateFromTransaction), only up to what this sale left: credit the customer already had is never spent.
     *
     * Resumable: a retried webhook (the sale already recorded) finishes an interrupted allocation, and a failure
     * of one step (e.g. a renewal after its commit) does not stop the others; the first failure is rethrown at
     * the end, so PayPal retries.
     *
     * @param  array<string, mixed>  $resource  the PAYMENT.SALE.COMPLETED resource
     */
    public function recordSubscriptionSale(array $resource): void
    {
        $services = Service::where('subscription_id', $resource['billing_agreement_id'])->orderBy('id')->get();
        $targets = $services
            ->map(fn (Service $service) => $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)
                ->orderBy('invoices.created_at')->orderBy('invoices.id')->first())
            ->filter()->unique('id')->values();
        // No open invoice at all: the sale goes to the first service's latest invoice and becomes credit.
        $first = $targets->first()
            ?? $services->map(fn (Service $service) => $service->invoices()->latest('invoices.created_at')->first())->filter()->first();
        if ($first === null) {
            return;
        }

        $failure = null;
        try {
            $sale = ExtensionHelper::addPayment($first->id, 'PayPal', $resource['amount']['total'], $resource['transaction_fee']['value'] ?? null, $resource['id'], currency: $resource['amount']['currency'] ?? null);
        } catch (Throwable $e) {
            // The sale may be committed and only something after its commit failed (a renewal): allocate anyway.
            $failure = $e;
            $sale = InvoiceTransaction::query()->where('transaction_id', (string) $resource['id'])
                ->where('status', InvoiceTransactionStatus::Succeeded)
                ->whereHas('gateway', fn ($query) => $query->where('extension', 'PayPal'))
                ->whereHas('invoice', fn ($query) => $query->where('client_id', $first->client_id))
                ->first();
        }

        if ($sale !== null) {
            foreach ($targets as $invoice) {
                if ($invoice->id === $sale->invoice_id) {
                    continue;
                }
                try {
                    (new RecordPaymentService)->allocateFromTransaction($sale, $invoice);
                } catch (Throwable $e) {
                    $failure ??= $e;
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function webhook(Request $request)
    {
        $body = $request->getContent();
        try {
            $verification = $this->request('post', ($this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com') . '/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $this->config('webhook_id'),
                'webhook_event' => json_decode($body, true),
            ]);
        } catch (PayPalApiException $e) {
            if ($e->status >= 500) {
                throw $e; // PayPal unavailable: a 500 here makes PayPal retry the webhook
            }
            $verification = null;
        }

        $verified = ($verification->verification_status ?? null) === 'SUCCESS';
        GatewayLog::current()->signature($verified);
        if (!$verified) {
            return response()->json(['status' => 'error'], 400);
        }

        $body = $request->json()->all();

        // Handle the subscription event
        if ($body['event_type'] === 'BILLING.SUBSCRIPTION.CREATED' && isset($body['resource']['custom_id'])) {
            // Its activated so we can now add the subscription to the user (custom is the order id)
            Invoice::findOrFail($body['resource']['custom_id'])->items()->where('reference_type', Service::class)->each(function (InvoiceItem $item) use ($body) {
                $service = $item->reference;
                if ($service === null) {
                    return; // a service deleted since
                }
                $service->subscription_id = $body['resource']['id'];
                $service->save();
                $service->properties()->updateOrCreate([
                    'key' => 'has_paypal_subscription',
                    'name' => 'Has PayPal Subscription',
                ], [
                    'value' => true,
                ]);
            });
        } elseif ($body['event_type'] === 'PAYMENT.SALE.COMPLETED' && isset($body['resource']['billing_agreement_id'])) {
            $this->recordSubscriptionSale($body['resource']);
        } elseif ($body['event_type'] === 'VAULT.PAYMENT-TOKEN.DELETED') {
            // Find the billing agreement with this external reference
            $billingAgreement = BillingAgreement::where('external_reference', $body['resource']['id'])->first();
            if ($billingAgreement) {
                $billingAgreement->delete();
            }
        } elseif ($body['event_type'] === 'PAYMENT.CAPTURE.REVERSED' && isset($body['resource']['id'])) {
            // The capture taken back for a dispute or chargeback (PR 2c-2b): the invoice is owed again.
            $captureId = self::upCapture($body['resource']);
            if ($captureId !== '') {
                ExtensionHelper::chargebackAtGateway('PayPal', $captureId, (string) $body['resource']['id'],
                    abs((float) ($body['resource']['amount']['value'] ?? 0)), $body['resource']['amount']['currency_code'] ?? null);
            }
        } elseif (in_array($body['event_type'], ['CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED'], true) && isset($body['resource']['dispute_id'])) {
            $disputed = $body['resource']['disputed_transactions'][0] ?? [];
            $captureId = (string) ($disputed['seller_transaction_id'] ?? '');
            $amount = (float) ($body['resource']['dispute_amount']['value'] ?? $disputed['gross_amount']['value'] ?? 0);
            $currency = $body['resource']['dispute_amount']['currency_code'] ?? $disputed['gross_amount']['currency_code'] ?? null;
            if ($body['event_type'] === 'CUSTOMER.DISPUTE.CREATED') {
                ExtensionHelper::chargebackOpened('PayPal', $captureId, (string) $body['resource']['dispute_id'], $amount, $currency);
            } elseif (in_array($body['resource']['dispute_outcome']['outcome_code'] ?? null, ['RESOLVED_SELLER_FAVOUR', 'CANCELED_BY_BUYER'], true) && $captureId !== '') {
                // Won (or withdrawn): the capture's chargeback is undone, once per dispute (kept by its id).
                ExtensionHelper::chargebackReversedAtGateway('PayPal', $captureId, null, $amount, $currency, (string) $body['resource']['dispute_id']);
            } elseif ($captureId !== '') {
                // Lost: its chargeback stays; kept, so another dispute's win on this capture never undoes it.
                ExtensionHelper::disputeLostAtGateway('PayPal', $captureId, (string) $body['resource']['dispute_id'], $amount, $currency);
            }
        } elseif ($body['event_type'] === 'PAYMENT.CAPTURE.REFUNDED' && isset($body['resource']['id'])) {
            // A refund made here or in PayPal's dashboard (PR 2c-2): recorded once, by its id. Its capture is the «up» link.
            $captureId = self::upCapture($body['resource']);
            if ($captureId !== '' && in_array($body['resource']['status'] ?? null, ['COMPLETED', 'PENDING'], true)) {
                ExtensionHelper::refundedAtGateway('PayPal', $captureId, (string) $body['resource']['id'],
                    (float) ($body['resource']['amount']['value'] ?? 0), $body['resource']['amount']['currency_code'] ?? null);
            }
        } elseif ($body['event_type'] === 'PAYMENT.SALE.REFUNDED' && isset($body['resource']['id'], $body['resource']['sale_id'])
            && in_array($body['resource']['state'] ?? 'completed', ['completed', 'pending'], true)) {
            // A subscription sale refunded (PR 2c-2). The total is negative in some payloads: its size is the refund.
            ExtensionHelper::refundedAtGateway('PayPal', (string) $body['resource']['sale_id'], (string) $body['resource']['id'],
                abs((float) ($body['resource']['amount']['total'] ?? 0)), $body['resource']['amount']['currency'] ?? null);
        } elseif ($body['event_type'] === 'PAYMENT.CAPTURE.COMPLETED' && isset($body['resource']['supplementary_data']['related_ids']['order_id'])) {
            $orderID = $body['resource']['supplementary_data']['related_ids']['order_id'];
            try {
                $order = $this->request('get', ($this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com') . '/v2/checkout/orders/' . $orderID);
            } catch (PayPalApiException $e) {
                if ($e->status >= 500) {
                    throw $e; // PayPal unavailable: retried
                }
                // An order this app cannot read (another app on the same account, a deleted order): retrying never helps.
                Log::warning('PayPal capture webhook for an order this app cannot read', ['order_id' => $orderID, 'error' => $e->getMessage()]);

                return response()->json(['status' => 'ignored']);
            }

            if (isset($order->purchase_units[0]->invoice_id)) {
                // A capture in another currency is refused by RecordPaymentService (logged, staff told), not skipped here.
                $invoice = Invoice::find($order->purchase_units[0]->invoice_id);
                if ($invoice) {
                    ExtensionHelper::addPayment($order->purchase_units[0]->invoice_id, 'PayPal', $order->purchase_units[0]->payments->captures[0]->amount->value, $order->purchase_units[0]->payments->captures[0]->seller_receivable_breakdown->paypal_fee->value ?? null, $body['resource']['id'], currency: $order->purchase_units[0]->payments->captures[0]->amount->currency_code, paymentGroup: $order->purchase_units[0]->custom_id ?? null);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }

    public function updateSubscription(Service $service)
    {
        if ($service->properties->where('key', 'has_paypal_subscription')->first()?->value !== '1') {
            return false;
        }
        // Update subscription price
        // With tax (Δ1: prices are net; a renewal line adds the tax), as Stripe and as the renewal invoices.
        $newPrice = number_format(Service::where('subscription_id', $service->subscription_id)->get()
            ->sum(fn (Service $subscribed) => $subscribed->recurringGrossCents()) / 100, 2, '.', '');
        // Grab currenct subscription ID
        $subscriptionId = $service->subscription_id;
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        // Update subscription price
        $this->request('PATCH', $url . '/v1/billing/subscriptions/' . $subscriptionId, [
            [
                'op' => 'replace',
                'path' => '/plan/billing_cycles/@sequence==2/pricing_scheme/fixed_price',
                'value' => [
                    'value' => $newPrice,
                    'currency_code' => $service->currency_code,
                ],
            ],
        ]);

        return true;
    }

    public function cancelSubscription(Service $service)
    {
        // Not a PayPal subscription: false lets ExtensionHelper::cancelSubscription try the other gateways. The
        // marker, or a PayPal subscription id (I-...) when staff cancel one whose marker was lost.
        if (!$service->subscription_id
            || ($service->properties()->where('key', 'has_paypal_subscription')->value('value') !== '1' && !str_starts_with($service->subscription_id, 'I-'))) {
            return false;
        }

        // Cancel subscription
        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        try {
            $this->request('post', $url . '/v1/billing/subscriptions/' . $service->subscription_id . '/cancel', [
                'reason' => 'User canceled',
            ]);
        } catch (PayPalApiException $e) {
            if ($e->issue !== 'SUBSCRIPTION_STATUS_INVALID') { // already cancelled or expired at PayPal
                throw $e;
            }
        }

        $service->properties()->where('key', 'has_paypal_subscription')->delete();
        $service->unsetRelation('properties');

        return true;
    }

    /**
     * The capture a refund or reversal belongs to: its «up» link.
     *
     * @param  array<string, mixed>  $resource
     */
    private static function upCapture(array $resource): string
    {
        $capture = collect($resource['links'] ?? [])->firstWhere('rel', 'up')['href'] ?? '';

        return basename((string) parse_url((string) $capture, PHP_URL_PATH));
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /** @return string|bool PayPal's id of the refund (its PAYMENT.CAPTURE.REFUNDED webhook is then recognised) */
    public function refund(InvoiceTransaction $transaction, $amount): string|bool
    {
        $captureId = $transaction->transaction_id;

        if (!$captureId) {
            throw new Exception('Transaction has no PayPal capture ID.');
        }

        $url = $this->config('test_mode') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        // The same refund (transaction, what was refunded before, amount) keeps the same request id: a retry after
        // a timeout gets PayPal's first answer instead of a second refund.
        // Counted from MyCS's own refunds only: one the webhook recorded meanwhile does not change it (F4 of 2c-2a).
        $requestId = 'refund-' . $transaction->id . '-' . GatewayRefund::refundedByMycsCents($transaction) . '-' . RecordPaymentService::toCents($amount);
        try {
            $refund = $this->request('post', $url . '/v2/payments/captures/' . $captureId . '/refund', [
                'amount' => [
                    'currency_code' => $transaction->invoice->currency_code,
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ], $requestId);
        } catch (ConnectionException $e) {
            Log::critical('PayPal refund outcome unknown (no answer): check PayPal before retrying; a retry reuses the request id.', [
                'transaction_id' => $transaction->id, 'capture_id' => $captureId, 'amount' => $amount, 'request_id' => $requestId,
            ]);

            throw $e;
        }

        if (!in_array($refund->status ?? null, ['COMPLETED', 'PENDING'], true)) {
            throw new Exception('PayPal did not refund capture ' . $captureId . ' (status ' . ($refund->status ?? 'none') . ').');
        }

        return (string) ($refund->id ?? '') ?: true;
    }
}

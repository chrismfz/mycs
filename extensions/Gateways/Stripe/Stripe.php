<?php

namespace MyCS\Extensions\Gateways\Stripe;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Service\RenewalTaxChanged;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\ChargeNeedsAction;
use App\Exceptions\ChargeNotSent;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Extension;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayLog;
use App\Services\Money\GatewayReference;
use App\Services\Money\PaymentsLog;
use App\Services\Payment\SavedMethodCharge;
use App\Services\Payment\SubscriptionEnded;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Str;
use Throwable;

#[ExtensionMeta(
    name: 'Stripe Gateway',
    description: 'Accept payments via Stripe.',
    version: 'builtin',
    author: 'MyCS',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgZmlsbD0iIzUzM0FGRCIvPjxwYXRoIGQ9Ik0xMjAgMzkyTDM5MiAzMzRWMTEyTDEyMCAxNz hWMzk yWiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg=='
)]
class Stripe extends Gateway
{
    private const API_VERSION = '2025-07-30.basil';

    public function boot()
    {
        View::addNamespace('gateways.stripe', __DIR__ . '/resources/views');

        // The renewal's tax changed (Δ1): the subscription bills the new amount, as the renewal invoices.
        Event::listen(RenewalTaxChanged::class, function (RenewalTaxChanged $event) {
            if (!$this->ownsSubscription($event->service)) {
                return;
            }
            try {
                $this->updateSubscription($event->service, ['price' => $event->service->price]);
            } catch (Exception $e) {
                report($e);
            }
        });
        Event::listen(Updated::class, function (Updated $event) {
            if (!$this->ownsSubscription($event->service)) {
                // Not a Stripe subscription now; ended by this save, one that ended here but may bill again goes with it.
                if ($event->endedBySave()) {
                    rescue(fn () => $this->cancelEndedSubscriptions($event->service), report: true);
                }

                return;
            }
            if ($event->changed('price', 'expires_at')) {
                try {
                    $this->updateSubscription($event->service, $event->changes);
                } catch (Exception $e) {
                    report($e);
                }
            }
            // Check if the service is canceled (by this save, and still is at commit)
            if ($event->endedBySave()) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    $this->cancelFailed($event->service, $e);
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if (!$this->ownsSubscription($service)) {
                rescue(fn () => $this->cancelEndedSubscriptions($service), report: true); // ADV-607-1

                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                $this->cancelFailed($service, $e);
            }
        });
    }

    /**
     * The subscription of a service that ended (or is to be cancelled) could not be cancelled at Stripe: it still bills.
     * Reported, and staff told (once a day for the same subscription); its next invoice is not paid but cancelled again
     * (invoice.created, sweep 3 L-FAILURES-1).
     */
    private function cancelFailed(Service $service, Throwable $e): void
    {
        report($e);
        $message = sprintf('The Stripe subscription %s of service #%d could not be cancelled at Stripe (%s). It may still bill the customer. Its next invoice is not paid here and its cancel is tried again then; cancel it in the Stripe dashboard to be sure.',
            (string) $service->subscription_id, $service->id, Str::limit($e->getMessage(), 200));
        PaymentsLog::warning($message, ['gateway' => 'Stripe', 'service_id' => $service->id]);
        if (Cache::add('stripe-cancel-failed:' . $service->subscription_id, true, now()->addDay())) {
            NotificationHelper::sendSystemEmailNotification('Stripe subscription not cancelled', e($message));
        }
    }

    /** The service no longer bills: ended, or a cancellation asked for (its subscription is cancelled with it). */
    private static function noLongerBills(Service $service): bool
    {
        return in_array($service->status, Service::ENDED_STATUSES, true) || $service->cancellation()->exists();
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'stripe_secret_key',
                'label' => 'Stripe Restricted key',
                'placeholder' => 'Enter your Stripe Restricted API key',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_publishable_key',
                'label' => 'Stripe Publishable Key',
                'placeholder' => 'Enter your Stripe Publishable API key',
                'type' => 'text',
                'description' => 'Find your API keys at https://dashboard.stripe.com/apikeys',
                'required' => true,
            ],
            [
                'name' => 'stripe_create_customers',
                'label' => 'Create detailed Stripe customers',
                'type' => 'checkbox',
                'description' => 'Controls the level of detail for Stripe customers. When enabled, customers include full address, phone, and company details. When disabled, customers include only basic information (name, email, user ID). Applies to both one-time payments and billing agreements.',
                'required' => false,
            ],
            [
                'name' => 'stripe_webhook_secret',
                'label' => 'Stripe webhook secret (auto generated)',
                'type' => 'text',
                'encrypted' => true,
                'description' => 'Stripe webhook secret',
                'required' => false,
            ],
        ];
    }

    /**
     * Its webhook as staff see it on the gateway's page (owner 2026-11-02: to know it is saved): saved for this key and
     * this site's address, or not, and what to do. From what is stored; Stripe is not asked. Never the secret itself.
     *
     * @return array{ok: bool, text: string}
     */
    public function webhookStatus(Extension $gateway): array
    {
        $setting = fn (string $key) => $gateway->settings()->where('key', $key)->first();
        $secret = (string) $setting('stripe_webhook_secret')?->value;
        if ($secret === '') {
            return ['ok' => false, 'text' => 'Not saved: payments made with Stripe are not recorded. Press Save to create it (a restricted key needs the Webhook Endpoints permission).'];
        }
        $url = rescue(fn () => route('extensions.gateways.stripe.webhook'), null, false);
        if ($url === null || !hash_equals((string) $setting('stripe_webhook_for')?->value, $this->webhookFor($url, $secret))) {
            return ['ok' => false, 'text' => 'Saved, but not made for this key and this site\'s address (another key, another address, or typed in by hand): press Save to create it again.'];
        }
        // When it was made: the id's row, which only making it writes (every Save rewrites the secret's: R3).
        $made = $setting('stripe_webhook_id') ?? $setting('stripe_webhook_for');
        $id = (string) $setting('stripe_webhook_id')?->value;

        return ['ok' => true, 'text' => sprintf('Saved %s%s: Stripe sends its events to %s.', $made?->updated_at?->format('Y-m-d H:i') ?? '',
            $id !== '' ? " (endpoint {$id})" : '', $url)];
    }

    /**
     * What its webhook was made for: this key, this site's address, and the secret Stripe gave it. A secret typed in over
     * it, a key or an address changed, and it no longer matches (review of #586, R2): Save makes it again.
     */
    private function webhookFor(string $url, string $secret): string
    {
        return substr(hash('sha256', (string) $this->config('stripe_secret_key') . '|' . $url . '|' . $secret), 0, 16);
    }

    /** The events its webhook asks for. */
    private const WEBHOOK_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'payment_intent.processing',
        'setup_intent.succeeded',
        'subscription_schedule.canceled',
        'customer.subscription.deleted',
        'customer.subscription.updated',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'invoice.created',
        'invoice.payment_succeeded',
        'charge.updated',
        'refund.created',
        'refund.updated',
        'refund.failed',
        'charge.dispute.created',
        'charge.dispute.funds_withdrawn',
        'charge.dispute.funds_reinstated',
    ];

    private static function webhookEventsMark(): string
    {
        return substr(hash('sha256', implode(',', self::WEBHOOK_EVENTS)), 0, 16);
    }

    /**
     * An endpoint made before an event was added (the subscription ones, #607) gets the list as it is now, on the next
     * Save: otherwise it never receives them (ADV-607-3). A failure is told to staff and tried again on the next Save.
     */
    private function syncWebhookEvents(Extension $gateway, string $id, string $mark): void
    {
        if ($id === '' || hash_equals($mark, self::webhookEventsMark())) {
            return;
        }
        try {
            $this->request('post', '/webhook_endpoints/' . $id, ['enabled_events' => self::WEBHOOK_EVENTS]);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->persistent()->title('Stripe webhook not updated')
                ->body('Stripe refused to add the newer events to the webhook: a subscription ended at Stripe is not seen here until it is. Save again to retry.')
                ->send();

            return;
        }
        $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_events'], ['value' => self::webhookEventsMark()]);
    }

    public function enabled(Extension $gateway)
    {
        $this->updated($gateway);
    }

    /**
     * Its webhook, on the account and mode of the secret key saved now: made once per key and address (review SW-1 of the
     * payment sweep, 2026-11-02: keys changed from sandbox to live kept the sandbox's secret, and no live payment was
     * recorded). What it was made for is kept as a fingerprint (never the key); a stored secret for another key or
     * address, or none, makes it again. The new endpoint first, then the old ones of this address (no moment without
     * one); a failure is told to staff on the page and kept for the next save (review 2 of #580), never a silent success.
     */
    public function updated(Extension $gateway)
    {
        $url = route('extensions.gateways.stripe.webhook');
        // Decrypted (the model, not ->value(): that one returns the ciphertext, never empty: review of #586, R1).
        $stored = fn (string $key) => (string) $gateway->settings()->where('key', $key)->first()?->value;
        $secret = $stored('stripe_webhook_secret');
        if ($secret !== '' && hash_equals($stored('stripe_webhook_for'), $this->webhookFor($url, $secret))) {
            $this->syncWebhookEvents($gateway, $stored('stripe_webhook_id'), $stored('stripe_webhook_events'));

            return;
        }

        try {
            $old = collect($this->request('get', '/webhook_endpoints')->data ?? [])->filter(fn ($webhook) => ($webhook->url ?? null) === $url);
            $webhook = $this->request('post', '/webhook_endpoints', [
                'url' => $url,
                'description' => 'MyCS Stripe Webhook',
                'enabled_events' => self::WEBHOOK_EVENTS,
                'api_version' => self::API_VERSION,
            ]);
            $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $webhook->secret, 'encrypted' => true]);
            $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_for'], ['value' => $this->webhookFor($url, (string) $webhook->secret)]);
            $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_id'], ['value' => (string) ($webhook->id ?? '')]);
            $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_events'], ['value' => self::webhookEventsMark()]);
            foreach ($old as $previous) {
                rescue(fn () => $this->request('delete', '/webhook_endpoints/' . $previous->id), report: true); // one left over is harmless
            }
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->persistent()
                ->title('Stripe webhook not created')
                ->body('Stripe refused to create the webhook for this key (a restricted key needs the Webhook Endpoints permission): payments will not be recorded until it is. Save again to retry.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Webhook created')
            ->body('We\'ve created a webhook for you on Stripe (refresh the page to see the secret)')
            ->send();
    }

    private function request($method, $url, $data = [], ?string $idempotencyKey = null)
    {
        // Every intent this site starts carries its mark: an event of one it did not start is known (S0-6).
        if (strtolower((string) $method) === 'post' && in_array($url, ['/payment_intents', '/setup_intents'], true)) {
            $data['metadata'] = ['mycs' => GatewayReference::mark()] + ($data['metadata'] ?? []);
        }

        return Http::withHeaders(array_filter([
            'Authorization' => 'Bearer ' . $this->config('stripe_secret_key'),
            'Stripe-Version' => self::API_VERSION,
            'Idempotency-Key' => $idempotencyKey,
        ]))->asForm()->$method('https://api.stripe.com/v1' . $url, $data)->throw()->object();
    }

    /**
     * What the account holds (Bank totals, owner 2026-10-24): each currency's available and pending, decimal strings.
     *
     * @return list<array{currency: string, available: string, pending: string}>
     */
    public function balance(): array
    {
        $balance = $this->request('get', '/balance');
        $minor = fn (int $cents) => ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
        $rows = [];
        foreach (['available', 'pending'] as $kind) {
            foreach ((array) ($balance->{$kind} ?? []) as $part) {
                $currency = strtoupper((string) ($part->currency ?? ''));
                $rows[$currency] ??= ['currency' => $currency, 'available' => '0.00', 'pending' => '0.00'];
                $rows[$currency][$kind] = $minor((int) ($part->amount ?? 0));
            }
        }

        return array_values($rows);
    }

    public function supportsPaymentGroups(): bool
    {
        return true;
    }

    public function pay($invoice, $total)
    {
        $intentData = [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => (int) round($total * 100),
            'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => array_filter(['invoice_id' => $invoice->id, 'payment_group' => $invoice->payingGroup]),
        ];

        try {
            $includeDetails = (bool) $this->config('stripe_create_customers');
            $customer = $this->createOrGetStripeCustomer($invoice->client, $includeDetails);
            if (!empty($customer->id)) {
                $intentData['customer'] = $customer->id;
            }
        } catch (DisplayException $e) {
            throw $e; // Stripe did not answer about the account's customer: no checkout now, the customer kept (S7-PAYMENTS-2)
        } catch (Exception $e) {
            // ignore customer creation errors and continue without customer
        }

        $intent = $this->request('post', '/payment_intents', $intentData);

        // Pay the invoice using Stripe
        return view('gateways.stripe::pay', ['invoice' => $invoice, 'total' => $total, 'intent' => $intent, 'stripePublishableKey' => $this->config('stripe_publishable_key')]);
    }

    public function webhook(Request $request)
    {
        $verified = $this->isValidSignature($request->getContent(), $request->header('Stripe-Signature'), $this->config('stripe_webhook_secret'));
        GatewayLog::current()->signature($verified);
        if (!$verified) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($request->getContent());

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.processing':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break; // a subscription's invoice pays through its own events (invoice.payment_succeeded)
                }
                if (!GatewayReference::isOurs($paymentIntent->metadata->mycs ?? null)) {
                    // Another system's payment on this account (WHMCS's, with its own invoice numbers): never ours (S0-6).
                    GatewayReference::foreign('Stripe', $event->type, $paymentIntent->id ?? null, isset($paymentIntent->amount) ? $paymentIntent->amount / 100 : null, $paymentIntent->currency ?? null);
                    break;
                }
                ExtensionHelper::addProcessingPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id, currency: $paymentIntent->currency ?? null, paymentGroup: $paymentIntent->metadata->payment_group ?? null,
                    onItsWayNow: $this->intentNow((string) $paymentIntent->id, InvoiceTransactionStatus::Failed) === 'processing');
                break;
                // Normal payment
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break; // a subscription's invoice pays through its own events (invoice.payment_succeeded)
                }
                if (!GatewayReference::isOurs($paymentIntent->metadata->mycs ?? null)) {
                    // Another system's payment on this account (WHMCS's, with its own invoice numbers): never ours (S0-6).
                    GatewayReference::foreign('Stripe', $event->type, $paymentIntent->id ?? null, isset($paymentIntent->amount) ? $paymentIntent->amount / 100 : null, $paymentIntent->currency ?? null);
                    break;
                }
                ExtensionHelper::addPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id, currency: $paymentIntent->currency ?? null, paymentGroup: $paymentIntent->metadata->payment_group ?? null);
                break;
            case 'payment_intent.payment_failed':
            case 'payment_intent.canceled': // cancelled at Stripe (also one that was processing): no money comes, as a failure (S7-PAYMENTS-3)
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break; // a subscription's invoice pays through its own events (invoice.payment_succeeded)
                }
                if (!GatewayReference::isOurs($paymentIntent->metadata->mycs ?? null)) {
                    // Another system's payment on this account (WHMCS's, with its own invoice numbers): never ours (S0-6).
                    GatewayReference::foreign('Stripe', $event->type, $paymentIntent->id ?? null, isset($paymentIntent->amount) ? $paymentIntent->amount / 100 : null, $paymentIntent->currency ?? null);
                    break;
                }
                // A late or repeated copy of an old failure, while a later attempt on the same intent is on its way (or
                // came): the payment stays as it is, and the hold on its invoice with it (S10-PAYMENTS-1).
                if (in_array($this->intentNow((string) $paymentIntent->id, InvoiceTransactionStatus::Processing), ['processing', 'succeeded'], true)) {
                    GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'intent_not_failed', ['external_id' => $paymentIntent->id, 'invoice_id' => $paymentIntent->metadata->invoice_id]);
                    break;
                }
                $failed = ExtensionHelper::addFailedPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id);
                $this->savedChargeFailed($failed, $paymentIntent);
                break;
            case 'charge.updated':
                $charge = $event->data->object; // contains a StripeCharge
                // A charge with no payment intent (the Charges API: WHMCS's older module, another app on the account) is
                // no payment of ours: `transaction_id = null` would find one staff entered with none (ADV-A4-3).
                $paymentIntent = is_string($charge->payment_intent ?? null) ? $charge->payment_intent : '';
                $payment = $paymentIntent === '' ? null : ExtensionHelper::feePayment($paymentIntent, 'Stripe');
                if ($payment === null) {
                    break;
                }
                // Get fee from charge
                $fee = 0;
                if ($charge->balance_transaction) {
                    $balanceTransaction = $this->request('get', '/balance_transactions/' . $charge->balance_transaction);
                    $fee = $balanceTransaction->fee / 100;
                }
                // The payment found above, once: no second lookup that a row changed meanwhile could miss (review of #694, F4).
                $payment->fee = $fee;
                $payment->save();

                break;
            case 'refund.created':
            case 'refund.updated':
            case 'refund.failed':
                // A refund made here or in Stripe's dashboard (PR 2c-2): recorded once, by its id.
                $refund = $event->data->object; // contains a StripeRefund
                if (!isset($refund->payment_intent, $refund->id)) {
                    break;
                }
                // WHMCS kept a refund it made by its balance transaction (txn_...): a late event of it is that refund (P1, F4).
                $balanceTransaction = $refund->balance_transaction ?? null;
                $balanceTransaction = is_string($balanceTransaction) ? $balanceTransaction : (is_object($balanceTransaction) ? ($balanceTransaction->id ?? null) : null);
                $balanceTransaction = is_string($balanceTransaction) ? $balanceTransaction : null;
                if (in_array($refund->status ?? null, ['succeeded', 'pending'], true)) {
                    ExtensionHelper::refundedAtGateway('Stripe', $refund->payment_intent, $refund->id, $refund->amount / 100, $refund->currency ?? null, $balanceTransaction);
                } elseif (in_array($refund->status ?? null, ['failed', 'canceled'], true)) {
                    ExtensionHelper::refundFailedAtGateway('Stripe', $refund->id, $refund->payment_intent, $refund->amount / 100, $refund->currency ?? null, $balanceTransaction);
                }
                break;
            case 'charge.dispute.created':
            case 'charge.dispute.funds_withdrawn':
            case 'charge.dispute.funds_reinstated':
                // A dispute (PR 2c-2b): opened = staff told; funds withdrawn = a chargeback, the invoice owed again;
                // funds reinstated = won, the chargeback undone. Recorded once, by the dispute's id.
                $dispute = $event->data->object; // contains a StripeDispute
                if (!isset($dispute->id, $dispute->payment_intent)) {
                    break;
                }
                $paymentIntent = is_string($dispute->payment_intent) ? $dispute->payment_intent : ($dispute->payment_intent->id ?? '');
                if ($event->type === 'charge.dispute.created') {
                    ExtensionHelper::chargebackOpened('Stripe', $paymentIntent, $dispute->id, $dispute->amount / 100, $dispute->currency ?? null);
                } elseif ($event->type === 'charge.dispute.funds_withdrawn') {
                    ExtensionHelper::chargebackAtGateway('Stripe', $paymentIntent, $dispute->id, $dispute->amount / 100, $dispute->currency ?? null);
                } else {
                    ExtensionHelper::chargebackReversedAtGateway('Stripe', $paymentIntent, $dispute->id, $dispute->amount / 100, $dispute->currency ?? null);
                }
                break;
            case 'setup_intent.succeeded':
                $setupIntent = $event->data->object; // contains a StripeSetupIntent
                if (!GatewayReference::isOurs($setupIntent->metadata->mycs ?? null)) {
                    // Not started here: never a saved method or a subscription on one of our customers (S0-6).
                    GatewayReference::foreign('Stripe', $event->type, $setupIntent->id ?? null);
                    break;
                }
                // If it's a billing agreement, call setupBillingAgreement
                if (!isset($setupIntent->metadata->is_billing_agreement) || $setupIntent->metadata->is_billing_agreement !== '1') {
                    $this->setupSubscription($setupIntent);
                } else {
                    $this->setupBillingAgreement($setupIntent);
                }
                break;
            case 'subscription_schedule.canceled':
                // A schedule cancelled cancels its subscription. The services carry the subscription's id
                // (setupSubscription), never the schedule's: looked up by that, it never matched (review of #605).
                $this->subscriptionChanged((string) ($event->data->object->subscription ?? ''), 'canceled');
                break;
            case 'customer.subscription.deleted':
            case 'customer.subscription.updated':
            case 'customer.subscription.paused':
            case 'customer.subscription.resumed':
                $this->subscriptionChanged((string) ($event->data->object->id ?? ''), self::statusOf($event->data->object));
                break;
            case 'invoice.created':
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($this->config('stripe_use_subscriptions') !== true) {
                    break;
                }

                // Check if its draft and does exist in our database
                if ($invoice->status === 'draft' && $invoice->parent->type === 'subscription_details') {
                    $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();

                    if ($service && self::noLongerBills($service)) {
                        // Its cancel failed when it ended (cancelFailed): never paid here. Cancelled again, and the
                        // draft deleted so Stripe does not finalise and charge it by itself.
                        try {
                            $this->cancelSubscription($service);
                        } catch (Exception $e) {
                            $this->cancelFailed($service, $e);
                        }
                        $deleted = rescue(function () use ($invoice) {
                            try {
                                $this->request('delete', '/invoices/' . $invoice->id);
                            } catch (RequestException $e) {
                                if ($e->response->status() !== 404) { // already gone is deleted
                                    throw $e;
                                }
                            }

                            return true;
                        }, false, report: true);
                        if (!$deleted && $service->subscription_id !== null) {
                            // Neither went through: a 2xx would let Stripe finalise the draft in about an hour and charge
                            // it. Not answered, Stripe holds the draft and sends the event again, when both are tried
                            // again (sweep 3 REV3-LF-1).
                            return response()->json(['error' => 'Subscription not cancelled, draft not deleted: try again'], 503);
                        }
                    } elseif ($service) {
                        $this->request('post', '/invoices/' . $invoice->id . '/finalize');
                        // Pay the invoice using Stripe
                        $this->request('post', '/invoices/' . $invoice->id . '/pay');
                    }
                }
                break;
            case 'invoice.payment_succeeded':
                // Mark invoice as paid
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($invoice->parent->type !== 'subscription_details') {
                    break;
                }

                // Also a subscription that ended here before this payment came (SubscriptionEnded): its money is still theirs.
                $subscription = (string) $invoice->parent->subscription_details->subscription;
                $service = SubscriptionEnded::services($subscription)->first();
                if ($service === null) {
                    // No service of ours carries it: never ours to record, and never lost without staff knowing (as PayPal).
                    GatewayReference::foreign('Stripe', 'invoice.payment_succeeded of subscription ' . $subscription, (string) ($invoice->id ?? ''),
                        ($invoice->amount_paid ?? 0) / 100, $invoice->currency ?? null);
                } else {
                    $invoiceModel = $service->invoiceItems->sortByDesc('created_at')->first()->invoice;
                    $paymentIntents = $this->request('get', '/invoice_payments', ['invoice' => $invoice->id]);
                    $paymentIntent = collect($paymentIntents->data)->first();

                    if ($paymentIntent->payment->type !== 'payment_intent') {
                        break;
                    }

                    ExtensionHelper::addPayment($invoiceModel->id, 'Stripe', $invoice->amount_paid / 100, null, $paymentIntent->payment->payment_intent, currency: $invoice->currency ?? null);
                }
                break;
            default:
                // Not a event type we care about, just return 200
        }

        http_response_code(200);
    }

    private function setupSubscription($setupIntent)
    {
        $invoice = Invoice::findOrFail($setupIntent->metadata->invoice_id);
        $user = $invoice->client;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new Exception('Stripe customer not found');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        // Make payment method default
        $paymentMethod = $this->request('post', '/customers/' . $customer->id, [
            'invoice_settings' => ['default_payment_method' => $setupIntent->payment_method],
        ]);

        // Create subscription
        /** @var InvoiceItem $item */
        foreach ($invoice->items as $item) {
            // A charge of the service (an imported setup fee, promotion): not a subscription of its own.
            if ($item->reference_type !== Service::class || $item->kind === InvoiceItem::KIND_CHARGE) {
                continue;
            }
            /** @var Service $service */
            $service = $item->reference;
            $product = $service->product;

            // Check if the service->product already exists in Stripe
            $stripeProduct = $this->request('get', '/products/search', ['query' => 'metadata[\'product_id\']:\'' . $product->id . '\'']);

            if (empty($stripeProduct->data)) {
                // Create product
                $stripeProduct = $this->request('post', '/products', [
                    'name' => $product->name,
                    'metadata' => ['product_id' => $product->id],
                ]);
            } else {
                $stripeProduct = $stripeProduct->data[0];
            }

            $phases = [];
            // The first invoice's line differs from a renewal (a setup fee, a first-cycle coupon): a first phase of
            // its amount. Both with tax (Δ1: prices are net, the line carries the tax).
            if ($item->totalCents() !== $service->recurringGrossCents()) {
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => [
                                'currency' => $invoice->currency_code,
                                'product' => $stripeProduct->id,
                                'unit_amount' => $item->totalCents(),
                                'recurring' => [
                                    'interval' => $service->plan->billing_unit,
                                    'interval_count' => $service->plan->billing_period,
                                ],
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'iterations' => 1,
                    'metadata' => [
                        'service_id' => $service->id,
                    ],
                    'proration_behavior' => 'none',
                ];
            }
            $phases[] = [
                'items' => [
                    [
                        'price_data' => [
                            'currency' => $invoice->currency_code,
                            'product' => $stripeProduct->id,
                            'unit_amount' => $service->recurringGrossCents(),
                            'recurring' => [
                                'interval' => $service->plan->billing_unit,
                                'interval_count' => $service->plan->billing_period,
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'metadata' => [
                    'service_id' => $service->id,
                ],
                'proration_behavior' => 'none',
            ];

            $subscription = $this->request('post', '/subscription_schedules', [
                'customer' => $customer->id,
                'start_date' => now()->startOfDay()->timestamp,
                'phases' => $phases,
                'metadata' => ['service_id' => $service->id],
            ]);

            // Update service with subscription id
            $service->update(['subscription_id' => $subscription->subscription]);
            $service->properties()->updateOrCreate(['key' => 'has_stripe_subscription'], ['value' => true]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $changes  what the save changed (Service\Updated::$changes); null = the dirty attributes
     */
    public function updateSubscription(Service $service, ?array $changes = null)
    {
        $changes ??= $service->getDirty();

        // Grab the schedule from Stripe
        $scheduleId = $this->request('get', '/subscriptions/' . $service->subscription_id);

        if (array_key_exists('price', $changes)) {
            if ($scheduleId->schedule) {

                $oldPhases = $this->request('get', '/subscription_schedules/' . $scheduleId->schedule)->phases;
                // Overwrite phase 2 item 0 with the new price
                $phases = [];
                // Only keep items and end, start date
                foreach ($oldPhases as $phase) {
                    $phases[] = [
                        'items' => $phase->items,
                        'end_date' => $phase->end_date,
                        'start_date' => $phase->start_date,
                    ];
                }
                // Check if the service->product already exists in Stripe
                $product = $service->product;
                $stripeProduct = $this->request('get', '/products/search', ['query' => 'metadata[\'product_id\']:\'' . $product->id . '\'']);

                if (empty($stripeProduct->data)) {
                    // Create product
                    $stripeProduct = $this->request('post', '/products', [
                        'name' => $product->name,
                        'metadata' => ['product_id' => $product->id],
                    ]);
                } else {
                    $stripeProduct = $stripeProduct->data[0];
                }
                // Latest phase is the current one
                $key = count($phases) - 1;
                $phases[$key]['items'][0]->price_data = [
                    'currency' => $service->currency->code,
                    'unit_amount' => $this->recurringAmount($service),
                    'product' => $stripeProduct->id,
                    'recurring' => [
                        'interval' => $service->plan->billing_unit,
                        'interval_count' => $service->plan->billing_period,
                    ],
                ];
                $phases[$key]['items'][0]->price = null;
                $phases[$key]['items'][0]->plan = null;

                // Update the schedule
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule, [
                    'phases' => $phases,
                    'proration_behavior' => 'none',
                ]);
            } else {
                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => $service->currency->code,
                        'unit_amount' => $this->recurringAmount($service),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
        }

        if (array_key_exists('expires_at', $changes)) {
            // API 2025-03-31.basil+ (pinned here) has the period on the item, older versions on the subscription.
            $periodEnd = $scheduleId->items->data[0]->current_period_end ?? $scheduleId->current_period_end ?? null;
            if ($periodEnd === null) {
                throw new Exception('Stripe subscription ' . $service->subscription_id . ' has no current_period_end.');
            }
            $subDate = Carbon::createFromTimestamp($periodEnd)->startOfDay();
            // Check if current date is before the end date of the subscription
            if ($subDate == $service->expires_at || $service->expires_at <= $subDate) {
                return;
            }

            if ($scheduleId->schedule) {
                // As phases are only used for the setup fee, we can remove the phases
                $this->request('post', '/subscription_schedules/' . $scheduleId->schedule . '/release', []);

                // Get subscription
                $subscription = $this->request('get', '/subscriptions/' . $service->subscription_id);
                // Get first item
                $item = $subscription->items->data[0];
                // Update price
                $this->request('post', '/subscription_items/' . $item->id, [
                    'price_data' => [
                        'currency' => $service->currency->code,
                        'unit_amount' => $this->recurringAmount($service),
                        'product' => $item->price->product,
                        'recurring' => [
                            'interval' => $service->plan->billing_unit,
                            'interval_count' => $service->plan->billing_period,
                        ],
                    ],
                    'proration_behavior' => 'none',
                ]);
            }
            // Update the subscription
            $this->request('post', '/subscriptions/' . $service->subscription_id, [
                'trial_end' => $service->expires_at->timestamp,
                'proration_behavior' => 'none',
            ]);
        }
    }

    /**
     * What the subscription bills per cycle, in cents: the renewal with tax (setupSubscription bills the same).
     */
    private function recurringAmount(Service $service): int
    {
        return $service->recurringGrossCents();
    }

    /**
     * A Stripe subscription: the marker, or a Stripe id (sub_...) whose marker was lost (staff-entered, legacy).
     */
    private function ownsSubscription(Service $service): bool
    {
        return $service->subscription_id
            && ($service->properties()->where('key', 'has_stripe_subscription')->value('value') === '1'
                || str_starts_with($service->subscription_id, 'sub_'));
    }

    public function cancelSubscription(Service $service)
    {
        // Not a Stripe subscription: false lets ExtensionHelper::cancelSubscription try the other gateways.
        if (!$this->ownsSubscription($service)) {
            return false;
        }
        try {
            $this->request('delete', '/subscriptions/' . $service->subscription_id);
        } catch (RequestException $e) {
            // Already gone at Stripe (cancelled or deleted): nothing left to cancel. Anything else is a failure.
            if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') {
                throw $e;
            }
        }

        // Kept aside: a payment of it already on its way still finds the service (SubscriptionEnded).
        SubscriptionEnded::keep($service, (string) $service->subscription_id);
        // Remove subscription id from service
        $service->update(['subscription_id' => null]);
        // Remove has_stripe_subscription property
        $service->properties()->where('key', 'has_stripe_subscription')->delete();

        return true;
    }

    /**
     * The statuses of a subscription that bills no more: cancelled, never paid, unpaid (retries over), paused (and one
     * whose payment collection is paused: statusOf).
     */
    private const SUBSCRIPTION_ENDED = ['canceled', 'incomplete_expired', 'unpaid', 'paused'];

    /** Ended, but the customer (paying its open invoice) or staff (resuming it) can make it bill again. */
    private const SUBSCRIPTION_MAY_BILL_AGAIN = ['unpaid', 'paused'];

    /**
     * Its status, with a paused payment collection («Pause payment collection» in the dashboard: the status stays
     * active, its invoices are voided, uncollectible or left as drafts) read as paused (code review of #607).
     */
    private static function statusOf(mixed $subscription): string
    {
        $status = (string) ($subscription->status ?? '');

        return !empty($subscription->pause_collection) && in_array($status, ['active', 'trialing', 'past_due'], true) ? 'paused' : $status;
    }

    /**
     * The service ends: a subscription that ended here as unpaid or paused (so no longer carried, and not cancelled with
     * it) can bill again, a terminated service each cycle. Cancelled now, as one it still carried would be (ADV-607-1).
     */
    public function cancelEndedSubscriptions(Service $service): void
    {
        $kept = $service->properties()->where('key', 'like', SubscriptionEnded::KEPT . ':%')->pluck('value')
            ->filter(fn ($id) => is_string($id) && preg_match('/^sub_[A-Za-z0-9]{1,250}\z/', $id) === 1);
        foreach ($kept as $id) {
            if (Service::query()->where('subscription_id', $id)->exists()) {
                continue; // staff set it on a service again: it pays that one
            }
            $status = $this->subscriptionStatus($id);
            if ($status === null || in_array($status, ['canceled', 'incomplete_expired'], true)) {
                continue; // over for good
            }
            try {
                $this->request('delete', '/subscriptions/' . $id);
            } catch (RequestException $e) {
                if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') {
                    throw $e;
                }
            }
            PaymentsLog::info('Stripe subscription ' . $id . ' (' . $status . ') cancelled with service #' . $service->id . ', which ended.',
                ['subscription_id' => $id, 'service_id' => $service->id]);
        }
    }

    /** The statuses of one that bills (again). */
    private const SUBSCRIPTION_BILLING = ['active', 'trialing', 'past_due'];

    /**
     * A subscription's status changed at Stripe ($status: what the event says). Ended there (cancelled in the dashboard
     * or the customer portal, unpaid after the retries, paused): its services no longer carry it, so their invoices are
     * charged and paid as any other (SubscriptionEnded). Only once Stripe says so now: an old event sent again never
     * clears one that still bills. One that ended here and bills again: staff are told, as for PayPal.
     */
    private function subscriptionChanged(string $id, string $status): void
    {
        if (preg_match('/^sub_[A-Za-z0-9]{1,250}\z/', $id) !== 1) {
            return;
        }
        $carried = Service::query()->where('subscription_id', $id)->exists();
        if ($carried && in_array($status, self::SUBSCRIPTION_ENDED, true)) {
            $now = $this->subscriptionStatus($id);
            if (in_array($now, self::SUBSCRIPTION_ENDED, true)) {
                $cleared = SubscriptionEnded::clear('Stripe', $id, 'subscription ' . $status . ', ' . $now . ' at Stripe', ['has_stripe_subscription']);
                if ($cleared !== [] && in_array($now, self::SUBSCRIPTION_MAY_BILL_AGAIN, true)) {
                    // Not over for good: paying its open invoice (Stripe's link, the portal) or resuming it makes it bill
                    // again, while the invoices here are charged too. Staff decide whether to cancel it (code review of #607).
                    NotificationHelper::sendSystemEmailNotification('Stripe subscription stopped billing', e(sprintf(
                        'The Stripe subscription %s is %s: it was cleared from service #%s, whose invoices are charged and payable as any other now. It may bill again (its open invoice paid, or resumed): cancel it in Stripe if it should not, so that nothing is paid twice.',
                        $id, $now, implode(', #', $cleared))));
                }

                return;
            }
            GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_IGNORED, 'subscription_still_' . ($now ?? 'unknown'), ['external_id' => $id]);

            return;
        }
        if ($carried || !in_array($status, self::SUBSCRIPTION_BILLING, true)) {
            return; // a subscription that bills as before (a routine update), or one none of ours carries
        }
        $ended = SubscriptionEnded::ended($id)
            ->where(fn ($unset) => $unset->whereNull('subscription_id')->orWhere('subscription_id', '!=', $id))
            ->orderBy('id')->pluck('id');
        if ($ended->isEmpty() || !in_array($this->subscriptionStatus($id), self::SUBSCRIPTION_BILLING, true)) {
            return;
        }
        $message = sprintf('The Stripe subscription %s bills again. It was cleared from service #%s when it ended, so those invoices are charged and payable as any other while the subscription bills too: what is paid twice becomes credit. Set it back as the service\'s subscription id if it should pay them, or cancel it in Stripe.',
            $id, $ended->implode(', #'));
        PaymentsLog::warning($message, ['subscription_id' => $id, 'services' => $ended->all()]);
        if (Cache::add('stripe-subscription-active-again:' . $id, true, now()->addDay())) {
            NotificationHelper::sendSystemEmailNotification('Stripe subscription active again', e($message));
        }
    }

    /**
     * The subscription's status as Stripe has it now; null when Stripe does not know it (another account's). Any other
     * failure is thrown: the webhook answers 500 and Stripe sends the event again.
     */
    private function subscriptionStatus(string $id): ?string
    {
        try {
            $subscription = $this->request('get', '/subscriptions/' . $id);
        } catch (RequestException $e) {
            if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') {
                throw $e;
            }
            PaymentsLog::warning('Stripe does not know subscription ' . $id . ': nothing changed here.', ['subscription_id' => $id]);

            return null;
        }

        return is_string($subscription->status ?? null) ? self::statusOf($subscription) : null;
    }

    public function supportsBillingAgreements(): bool
    {
        return true;
    }

    /**
     * Ensure a Stripe Customer exists for the given customer account (its stripe_id property). If a customer already
     * exists it will be returned; otherwise a new customer will be created.
     *
     * @param  bool  $includeDetails  Whether to include address, phone, and company details
     * @return object Stripe customer object
     */
    private function createOrGetStripeCustomer(Client $user, $includeDetails = true)
    {
        $stripeCustomerId = $user->properties()->where('key', 'stripe_id')->first();

        if ($stripeCustomerId) {
            // Only Stripe's own «no such customer» (or one it deleted) makes a new one. Anything else (a timeout, a 5xx, a
            // 429, a key it refuses) says nothing of the customer: the account's saved cards are attached to it, and a new
            // customer in its place could never charge them again (S7-PAYMENTS-2). The checkout or the new card is refused
            // for now. A key of the other mode is answered with the same 404 («a similar object exists in live mode, but a
            // test mode key was used», and the reverse): the customer exists, the key is wrong (REV-774-5).
            try {
                $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
                if (!isset($customer->deleted)) {
                    return $customer;
                }
            } catch (Exception $e) {
                $gone = $e instanceof RequestException && $e->response->status() === 404 && $e->response->json('error.code') === 'resource_missing';
                $otherMode = $gone && str_contains((string) $e->response->json('error.message'), 'similar object exists in');
                if (!$gone || $otherMode) {
                    report($e);
                    if (self::noAnswer($e)) {
                        throw new DisplayException(__('The payment provider did not answer. Nothing was charged: please try again in a few minutes.'));
                    }
                    // Not an outage: Stripe refuses the key, or the key is of the other mode. Trying again does not help
                    // the customer; staff are told (once an hour) and fix the gateway's settings.
                    if (Cache::add('stripe-customer-read-refused', true, now()->addHour())) {
                        $message = $otherMode
                            ? 'Stripe says the customer ' . $stripeCustomerId->value . ' of client #' . $user->id . ' exists in the other mode (live/test) than the secret key saved in the Stripe gateway: the key\'s mode does not match the stored customers. Their checkouts and new cards are refused, and the customer was kept, until the key is the right one.'
                            : 'Stripe refused to read the customer ' . $stripeCustomerId->value . ' of client #' . $user->id . ' (HTTP ' . ($e instanceof RequestException ? $e->response->status() : '?') . '): check the secret key saved in the Stripe gateway. Checkouts and new cards of accounts with a Stripe customer are refused until it works.';
                        PaymentsLog::warning($message, ['client_id' => $user->id]);
                        NotificationHelper::sendSystemEmailNotification('Stripe: customers cannot be read', e($message));
                    }

                    throw new DisplayException(__('This payment method is not available right now. We have been told and are looking into it: please pay another way, or try again later.'));
                }
            }
        }

        // Build basic payload
        $payload = [
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['client_id' => $user->id],
        ];

        // Add detailed information if requested
        if ($includeDetails) {
            // The account's details are its primary contact's (docs/accounts.md, PR 3a).
            $contact = $user->primaryContact;

            if (!empty($contact->company_name)) {
                $payload['description'] = $contact->company_name;
            }

            $address = array_filter([
                'line1' => $contact?->address1,
                'line2' => $contact?->address2,
                'city' => $contact?->city,
                'state' => $contact?->state,
                'postal_code' => $contact?->postcode,
                'country' => $contact?->country,
            ]);

            if ($address) {
                $payload['address'] = $address;
            }

            if (!empty($contact->phone)) {
                $payload['phone'] = $contact->phone;
            }
        }

        $customer = $this->request('post', '/customers', $payload);

        $user->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => $customer->id]);

        return $customer;
    }

    public function createBillingAgreement(Client $user)
    {
        // Create or fetch a Stripe customer for this user, use detailed info based on setting
        $includeDetails = (bool) $this->config('stripe_create_customers');
        $customer = $this->createOrGetStripeCustomer($user, $includeDetails);

        $setupIntent = $this->request('post', '/setup_intents', [
            'metadata' => [
                'client_id' => $user->id,
                'is_billing_agreement' => '1',
            ],
            'usage' => 'off_session',
            'customer' => $customer->id,
        ]);

        // Return the client secret to the frontend to complete the setup
        return view('gateways.stripe::billing-agreement', ['intent' => $setupIntent, 'type' => 'setup', 'stripePublishableKey' => $this->config('stripe_publishable_key')]);
    }

    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        try {
            $request = $this->request('post', '/payment_methods/' . $billingAgreement->external_reference . '/detach');
        } catch (Exception $e) {
            // If the payment method is already removed, we still delete the billing agreement
        }

        return true;
    }

    public function setupAgreement(Request $request)
    {
        $request->validate([
            'setup_intent' => 'required|string|regex:/^seti_[a-zA-Z0-9]{24,}$/|max:255',
        ]);

        $setupIntent = $this->request('get', '/setup_intents/' . $request->input('setup_intent'));

        if ($setupIntent->status !== 'succeeded' || !GatewayReference::isOurs($setupIntent->metadata->mycs ?? null)) {
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Could not add payment method, setup not completed successfully.',
            ]);
        }
        // The account the method is saved on must be one the logged-in user sees (docs/accounts.md, PR 2a). Intents made
        // before the split carry the user id, which was the account's id.
        $clientId = (int) ($setupIntent->metadata->client_id ?? $setupIntent->metadata->user_id ?? 0);
        if (!Auth::user() instanceof User || !Auth::user()->clients()->whereKey($clientId)->exists()) {
            abort(403, 'Unauthorized');
        }

        $this->setupBillingAgreement($setupIntent);

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
        ]);
    }

    private function setupBillingAgreement($setupIntent)
    {
        $user = Client::findOrFail((int) ($setupIntent->metadata->client_id ?? $setupIntent->metadata->user_id));

        // Get setup intent with expanded data
        $setupIntent = $this->request('get', '/setup_intents/' . $setupIntent->id, [
            'expand' => ['latest_attempt'],
        ]);

        // Get the actual payment method that will be used for future charges
        $actualPaymentMethod = $this->getActualPaymentMethod($setupIntent);

        $data = $this->getPaymentDetails($actualPaymentMethod);

        // Save billing agreement
        ExtensionHelper::makeBillingAgreement(
            $user,
            'Stripe',
            $data['name'],
            $actualPaymentMethod->id,
            $data['type'],
            $data['expiry'],
        );
    }

    private function getActualPaymentMethod($setupIntent)
    {
        $latestAttempt = $setupIntent->latest_attempt;

        // Check if the payment method was converted to another type
        if ($latestAttempt && isset($latestAttempt->payment_method_details)) {
            $details = $latestAttempt->payment_method_details;

            if (in_array($details->type, ['ideal', 'bancontact', 'sofort'])) {
                // These payment methods can be converted to SEPA Direct Debit
                return $this->request('get', '/payment_methods/' . $details->{$details->type}->generated_sepa_debit);
            }
        }

        // If no conversion, use the original payment method
        return $this->request('get', '/payment_methods/' . $setupIntent->payment_method);
    }

    private function getPaymentDetails($paymentMethod)
    {
        $name = match ($paymentMethod->type) {
            'card' => match ($paymentMethod->card->brand) {
                'amex' => 'American Express',
                'diners' => 'Diners Club',
                'discover' => 'Discover',
                'jcb' => 'JCB',
                'mastercard' => 'Mastercard',
                'unionpay' => 'UnionPay',
                'visa' => 'Visa',
                default => ucfirst($paymentMethod->card->brand),
            } . ' **** ' . $paymentMethod->card->last4,

            'sepa_debit' => 'SEPA Direct Debit **** ' . $paymentMethod->sepa_debit->last4,
            'ideal' => 'iDEAL **** ' . $paymentMethod->ideal->bank_code,
            'bancontact' => 'Bancontact **** ' . $paymentMethod->bancontact->bank_code,
            'sofort' => 'SOFORT **** ' . strtoupper($paymentMethod->sofort->country),
            'us_bank_account' => 'US Bank Account **** ' . $paymentMethod->us_bank_account->last4,
            'bacs_debit' => 'Bacs Direct Debit **** ' . $paymentMethod->bacs_debit->last4,
            'au_becs_debit' => 'BECS Direct Debit **** ' . $paymentMethod->au_becs_debit->last4,

            default => ucfirst(str_replace('_', ' ', $paymentMethod->type)),
        };
        $type = match ($paymentMethod->type) {
            'card' => $paymentMethod->card->brand,
            // For the others, just return the type as is
            default => $paymentMethod->type,
        };
        $expiry = null;
        if ($paymentMethod->type === 'card') {
            $expiry = Carbon::createFromDate(
                $paymentMethod->card->exp_year,
                $paymentMethod->card->exp_month,
                1
            )->endOfMonth()->format('Y-m-d');
        }

        return [
            'name' => $name,
            'type' => $type,
            'expiry' => $expiry,
        ];

    }

    /**
     * An event that would turn an intent's payment here from $from to the other of failed and processing: «processing»
     * for a failed one (S6-PAYMENTS-1), a failure for one that is processing (S10-PAYMENTS-1). It is a late copy of an old
     * event, or news (a card declined, then a bank debit on the same form: REV-766-1; a bank debit that bounced). The
     * event cannot tell, so Stripe is asked once what the intent is now: its status, or null when the payment here is not
     * $from (nothing to ask). A failure of the call is thrown: nothing changes and Stripe sends the event again.
     */
    private function intentNow(string $id, InvoiceTransactionStatus $from): ?string
    {
        $is = InvoiceTransaction::query()->where('transaction_id', $id)->where('status', $from)
            ->whereHas('gateway', fn ($gateway) => $gateway->where('extension', 'Stripe'))->exists();

        return $is ? ($this->request('get', '/payment_intents/' . $id)->status ?? null) : null;
    }

    /**
     * A saved method's charge Stripe took as «processing» (a SEPA or other bank debit: kept «succeeded», PR H) that then
     * failed: no money came. Its charge attempt is settled as failed, so the invoice is payable again and the cron charges
     * it again as set; left «succeeded» with no payment it would read as taken and not recorded, holding the invoice for
     * every way of paying, for ever, and telling staff to record a payment that never came (sweep 3 L-NO-DOUBLE-CHARGE-1;
     * PayPal's denied capture, REV-PAYMENTS-1). Only a payment intent of a saved method's charge (its billing agreement in
     * its metadata), whose payment row is failed now (a payment completed meanwhile stays succeeded). Staff are told.
     */
    private function savedChargeFailed(mixed $failed, object $paymentIntent): void
    {
        $agreementId = $paymentIntent->metadata->billing_agreement_id ?? null;
        if (!$failed instanceof InvoiceTransaction || $failed->status !== InvoiceTransactionStatus::Failed || !is_numeric($agreementId)) {
            return;
        }
        $key = $paymentIntent->metadata->charge_attempt ?? null;
        $attempt = SavedMethodCharge::failedAtGateway($failed, 'Stripe: the payment intent ' . $paymentIntent->id . ' failed', (int) $agreementId, is_string($key) ? $key : null);
        if ($attempt === null) {
            return;
        }
        $message = sprintf('Stripe reported the saved method\'s charge %s on invoice #%d as failed after it was processing (a bank debit that bounced): no money came. The charge #%d is marked failed and the invoice can be paid again.',
            $paymentIntent->id, $failed->invoice_id, $attempt);
        PaymentsLog::warning($message, ['payment_intent' => $paymentIntent->id, 'invoice_id' => $failed->invoice_id, 'charge_attempt_id' => $attempt]);
        NotificationHelper::sendSystemEmailNotification('Stripe saved-method charge failed', e($message));
    }

    public function charge(Invoice $invoice, $amount, BillingAgreement $billingAgreement)
    {
        $user = $invoice->client;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new DisplayException('Stripe customer not found');
        }
        try {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        } catch (Exception $e) {
            report($e);
            // Stripe down, slow or rate-limiting before the charge was sent: nothing was charged, and nothing declined. Not
            // a decline: the customer is not told it failed, the method's declines are not counted (sweep 3 L-FAILURES-2).
            if (self::noAnswer($e)) {
                throw new ChargeNotSent('Could not reach Stripe: nothing was charged');
            }
            if (self::keyRefused($e, otherMode: true)) {
                throw $this->chargeKeyRefused($e, $invoice);
            }

            throw new DisplayException('Could not process payment'); // nothing was charged yet
        }

        // Create payment intent. The charge attempt's key is the idempotency key (PR H): a request repeated by the network
        // or a retry of the same attempt never charges twice.
        try {
            $intent = $this->request('post', '/payment_intents', [
                'amount' => (int) round($amount * 100),
                'currency' => $invoice->currency_code,
                'customer' => $customer->id,
                'payment_method' => $billingAgreement->external_reference,
                'off_session' => 'true',
                'confirm' => 'true',
                // The charge attempt's key too: a later payment_intent.payment_failed (a SEPA debit that bounced) settles that
                // very attempt (sweep 3 L-NO-DOUBLE-CHARGE-1).
                'metadata' => array_filter(['invoice_id' => $invoice->id, 'billing_agreement_id' => $billingAgreement->id, 'payment_group' => $invoice->payingGroup,
                    'charge_attempt' => $invoice->chargeKey]),
            ], $invoice->chargeKey);
        } catch (Exception $e) {
            // No answer about the money (a timeout, a 5xx): not a decline. Thrown as it is, so the attempt keeps its key and
            // is sent again with it (PR H, adversarial ADV-3b).
            if ($e instanceof ConnectionException || ($e instanceof RequestException && $e->response->status() >= 500)) {
                throw $e;
            }
            // Rate limited: Stripe did not run the request (its docs), so nothing was charged and nothing declined.
            if ($e instanceof RequestException && $e->response->status() === 429) {
                throw new ChargeNotSent('Stripe rate-limited the charge: nothing was charged');
            }
            if (self::keyRefused($e)) {
                throw $this->chargeKeyRefused($e, $invoice);
            }
            if ($e instanceof RequestException && in_array($e->response->status(), [400, 402], true)) {
                $error = $e->response->object()->error ?? null;
                // If error is invalid_request_error and message contains "The provided currency" we can show the message
                if (($error->type ?? null) === 'invalid_request_error' && Str::contains((string) ($error->message ?? ''), 'The currency provided')) {
                    throw new DisplayException('Cannot charge the billing agreement because the card does not support the invoice currency (' . $invoice->currency_code . '). Please use another payment method.');
                }
                // The bank asks the customer to confirm (3-D Secure): not retried, the customer pays the invoice (PR H).
                if (($error->code ?? null) === 'authentication_required' || ($error->payment_intent->status ?? null) === 'requires_action') {
                    throw new ChargeNeedsAction(__('Your bank asks you to confirm this payment: please pay the invoice yourself.'));
                }
                if (isset($error->decline_code) || isset($error->code)) {
                    throw new DisplayException('Card declined: ' . ($error->decline_code ?? $error->code));
                }
            }
            throw new DisplayException('Could not process payment');
        }
        // Confirmed off session: succeeded, or processing (a bank debit). Anything else was not charged.
        $status = $intent->status ?? null;
        if ($status === 'requires_action') {
            throw new ChargeNeedsAction(__('Your bank asks you to confirm this payment: please pay the invoice yourself.'));
        }
        if (!in_array($status, ['succeeded', 'processing'], true)) {
            throw new DisplayException('Could not process payment (' . ($status ?? 'no status') . ')');
        }

        return true;
    }

    /**
     * Stripe refuses OUR key (401, 403): it ran nothing and asked no card. On the customer's read ($otherMode) also a key of
     * the other mode than the stored customers (its 404 «a similar object exists in live mode, but a test mode key was
     * used», and the reverse). Not on the charge itself: the key has just read the customer, so that 404 is the saved
     * method's, which fails as any charge (REV-791-4).
     */
    private static function keyRefused(Throwable $e, bool $otherMode = false): bool
    {
        return $e instanceof RequestException && (in_array($e->response->status(), [401, 403], true)
            || ($otherMode && $e->response->status() === 404 && str_contains((string) $e->response->json('error.message'), 'similar object exists in')));
    }

    /**
     * A saved method's charge Stripe refused for our key (keyRefused): ours to fix, not a decline of the card. Kept as not
     * sent, so the customer is not told the payment failed and the method's declines are not counted (S8-PAYMENTS-2); staff
     * are told, once an hour, as for a customer that cannot be read (createOrGetStripeCustomer). The notice never changes
     * the outcome: when it cannot be made it is reported, and the charge is not sent all the same (REV-791-3; the shared
     * SavedMethodCharge::tellCredentialsRefused, as PayPal's refused credentials).
     */
    private function chargeKeyRefused(Throwable $e, Invoice $invoice): ChargeNotSent
    {
        SavedMethodCharge::tellCredentialsRefused('stripe-charge-key-refused', 'Stripe: saved cards cannot be charged',
            'Stripe refused the secret key saved in the Stripe gateway on a saved method\'s charge (HTTP ' . ($e instanceof RequestException ? $e->response->status() : '?') . ', invoice #' . $invoice->id . '): check the key, its permissions and its mode (live/test). This charge was not sent now and no customer was told it failed; saved cards are not charged until the key works, and the cron tries again as set.', $invoice);

        return new ChargeNotSent('Stripe refused our key: the charge was not sent');
    }

    /** No answer from Stripe at all (a timeout, a 5xx) or a request it refused to run (429): says nothing of a card. */
    private static function noAnswer(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->status() >= 500 || $e->response->status() === 429));
    }

    /** How old a signed event may be: Stripe's own libraries' default, so a captured one is not replayed later (PAY-SEC-7). */
    public const SIGNATURE_TOLERANCE = 300;

    /** @return array{0: ?string, 1: list<string>} The header's timestamp and its v1 signatures (two while a secret rolls). */
    private function getHeaderValues($sig_header)
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $sig_header) as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $signatures[] = substr($part, 3);
            }
        }

        return [$timestamp, $signatures];
    }

    private function isValidSignature($payload, $sig_header, $secret)
    {
        [$timestamp, $signatures] = $this->getHeaderValues($sig_header);
        if (!ctype_digit((string) $timestamp) || $signatures === [] || empty($secret)
            || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /** @return string Stripe's id of the refund (its refund.* webhooks are then recognised) */
    public function refund(InvoiceTransaction $transaction, $amount): string
    {
        $paymentIntentId = $transaction->transaction_id;

        if (!$paymentIntentId) {
            throw new Exception('Transaction has no Stripe payment intent ID.');
        }

        $refundData = [
            'payment_intent' => $paymentIntentId,
            'amount' => (int) round($amount * 100),
        ];

        // The same refund (transaction, what was refunded before, amount) keeps the same key: a retry after a timeout gets
        // Stripe's first answer instead of a second refund (as PayPal's request id).
        // Counted from MyCS's own refunds only: one the webhook recorded meanwhile does not change it (F4 of 2c-2a).
        $key = 'refund-' . $transaction->id . '-' . GatewayRefund::refundedByMycsCents($transaction) . '-' . RecordPaymentService::toCents($amount);
        $refund = $this->request('post', '/refunds', $refundData, $key);

        return (string) $refund->id;
    }
}

<?php

namespace Paymenter\Extensions\Gateways\Stripe;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Extension;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\GatewayLog;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Str;

#[ExtensionMeta(
    name: 'Stripe Gateway',
    description: 'Accept payments via Stripe.',
    version: 'builtin',
    author: 'Paymenter',
    url: 'https://paymenter.org/docs/extensions/stripe',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cmVjdCB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgZmlsbD0iIzUzM0FGRCIvPjxwYXRoIGQ9Ik0xMjAgMzkyTDM5MiAzMzRWMTEyTDEyMCAxNz hWMzk yWiIgZmlsbD0iI2ZmZiIvPjwvc3ZnPg=='
)]
class Stripe extends Gateway
{
    private const API_VERSION = '2025-07-30.basil';

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
        View::addNamespace('gateways.stripe', __DIR__ . '/resources/views');

        Event::listen(Updated::class, function (Updated $event) {
            if (!$this->ownsSubscription($event->service)) {
                // If the service is not a stripe subscription, skip
                return;
            }
            if ($event->changed('price', 'expires_at')) {
                try {
                    $this->updateSubscription($event->service, $event->changes);
                } catch (Exception $e) {
                }
            }
            // Check if the service is canceled (by this save, and still is at commit)
            if ($event->endedBySave()) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    // Ignore exception
                }
            }
        });

        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if (!$this->ownsSubscription($service)) {
                // If the service is not a stripe subscription, skip
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                // Ignore exception
            }
        });
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

    public function enabled(Extension $gateway)
    {
        $this->updated($gateway);
    }

    public function updated(Extension $gateway)
    {
        if (!empty($gateway->settings()->where('key', 'stripe_webhook_secret')->first()->value)) {
            return;
        }

        // If the extension isn't enabled, try to boot it
        if (!Route::has('extensions.gateways.stripe.webhook')) {
            require __DIR__ . '/routes.php';
        }

        // Check if webhook already exists
        $webhooks = $this->request('get', '/webhook_endpoints');
        foreach ($webhooks->data as $webhook) {
            if ($webhook->url === route('extensions.gateways.stripe.webhook')) {
                // Delete webhook
                $this->request('delete', '/webhook_endpoints/' . $webhook->id);
                break;
            }
        }

        // Create webhook on stripe
        $webhook = $this->request('post', '/webhook_endpoints', [
            'url' => route('extensions.gateways.stripe.webhook'),
            'description' => 'Paymenter Stripe Webhook',
            'enabled_events' => [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'payment_intent.processing',
                'setup_intent.succeeded',
                'subscription_schedule.canceled',
                'invoice.created',
                'invoice.payment_succeeded',
                'charge.updated',
                'refund.created',
                'refund.updated',
                'refund.failed',
                'charge.dispute.created',
                'charge.dispute.funds_withdrawn',
                'charge.dispute.funds_reinstated',
            ],
            'api_version' => self::API_VERSION,
        ]);

        $gateway->settings()->updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $webhook->secret, 'encrypted' => true]);

        Notification::make()
            ->success()
            ->title('Webhook created')
            ->body('We\'ve created a webhook for you on Stripe (refresh the page to see the secret)')
            ->send();
    }

    private function request($method, $url, $data = [], ?string $idempotencyKey = null)
    {
        return Http::withHeaders(array_filter([
            'Authorization' => 'Bearer ' . $this->config('stripe_secret_key'),
            'Stripe-Version' => self::API_VERSION,
            'Idempotency-Key' => $idempotencyKey,
        ]))->asForm()->$method('https://api.stripe.com/v1' . $url, $data)->throw()->object();
    }

    public function pay($invoice, $total)
    {
        $intentData = [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => $total * 100,
            'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id],
        ];

        try {
            $includeDetails = (bool) $this->config('stripe_create_customers');
            $customer = $this->createOrGetStripeCustomer($invoice->client, $includeDetails);
            if (!empty($customer->id)) {
                $intentData['customer'] = $customer->id;
            }
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
                    break;
                }
                ExtensionHelper::addProcessingPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id, currency: $paymentIntent->currency ?? null);
                break;
                // Normal payment
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                ExtensionHelper::addPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id, currency: $paymentIntent->currency ?? null);
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                ExtensionHelper::addFailedPayment($paymentIntent->metadata->invoice_id, 'Stripe', $paymentIntent->amount / 100, null, $paymentIntent->id);
                break;
            case 'charge.updated':
                $charge = $event->data->object; // contains a StripeCharge
                $invoiceTransaction = InvoiceTransaction::where('transaction_id', $charge->payment_intent)->first();
                if (!$invoiceTransaction) {
                    break;
                }
                // Get fee from charge
                $fee = 0;
                if ($charge->balance_transaction) {
                    $balanceTransaction = $this->request('get', '/balance_transactions/' . $charge->balance_transaction);
                    $fee = $balanceTransaction->fee / 100;
                }
                ExtensionHelper::addPaymentFee($charge->payment_intent, $fee);

                break;
            case 'refund.created':
            case 'refund.updated':
            case 'refund.failed':
                // A refund made here or in Stripe's dashboard (PR 2c-2): recorded once, by its id.
                $refund = $event->data->object; // contains a StripeRefund
                if (!isset($refund->payment_intent, $refund->id)) {
                    break;
                }
                if (in_array($refund->status ?? null, ['succeeded', 'pending'], true)) {
                    ExtensionHelper::refundedAtGateway('Stripe', $refund->payment_intent, $refund->id, $refund->amount / 100, $refund->currency ?? null);
                } elseif (in_array($refund->status ?? null, ['failed', 'canceled'], true)) {
                    ExtensionHelper::refundFailedAtGateway('Stripe', $refund->id, $refund->payment_intent, $refund->amount / 100, $refund->currency ?? null);
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
                // If it's a billing agreement, call setupBillingAgreement
                if (!isset($setupIntent->metadata->is_billing_agreement) || $setupIntent->metadata->is_billing_agreement !== '1') {
                    $this->setupSubscription($setupIntent);
                } else {
                    $this->setupBillingAgreement($setupIntent);
                }
                break;
            case 'subscription_schedule.canceled':
                $subscriptionSchedule = $event->data->object; // contains a StripeSubscriptionSchedule
                $service = Service::where('subscription_id', $subscriptionSchedule->id)->first();
                if ($service) {
                    $service->update(['subscription_id' => null]);
                }
                break;
            case 'invoice.created':
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($this->config('stripe_use_subscriptions') !== true) {
                    break;
                }

                // Check if its draft and does exist in our database
                if ($invoice->status === 'draft' && $invoice->parent->type === 'subscription_details') {
                    $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();

                    if ($service) {
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

                $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();
                if ($service) {
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
        foreach ($invoice->items as $item) {
            if ($item->reference_type !== Service::class) {
                continue;
            }
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
            // Check if current invoice item price is bigger then service price (then we have a setup fee)
            if ($item->price != $service->price) {
                $phases[] = [
                    'items' => [
                        [
                            'price_data' => [
                                'currency' => $invoice->currency_code,
                                'product' => $stripeProduct->id,
                                'unit_amount' => $item->price * 100,
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
                            'unit_amount' => ($service->price * $service->quantity) * 100,
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
     * What the subscription bills per cycle, in cents: price is per unit (setupSubscription bills price * quantity).
     */
    private function recurringAmount(Service $service): int
    {
        return RecordPaymentService::toCents($service->price) * max(1, (int) $service->quantity);
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

        // Remove subscription id from service
        $service->update(['subscription_id' => null]);
        // Remove has_stripe_subscription property
        $service->properties()->where('key', 'has_stripe_subscription')->delete();

        return true;
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
            try {
                $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
                if (!isset($customer->deleted)) {
                    return $customer;
                }
            } catch (Exception $e) {
                // continue and create a new customer
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

            if (!empty($contact?->company_name)) {
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

            if (!empty($contact?->phone)) {
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

        if ($setupIntent->status !== 'succeeded') {
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

    public function charge(Invoice $invoice, $amount, BillingAgreement $billingAgreement)
    {
        $user = $invoice->client;
        $stripeCustomerId = $user->properties->where('key', 'stripe_id')->first();
        // Create customer if not exists
        if (!$stripeCustomerId) {
            throw new DisplayException('Stripe customer not found');
        } else {
            $customer = $this->request('get', '/customers/' . $stripeCustomerId->value);
        }

        // Create payment intent
        try {
            $intent = $this->request('post', '/payment_intents', [
                'amount' => $amount * 100,
                'currency' => $invoice->currency_code,
                'customer' => $customer->id,
                'payment_method' => $billingAgreement->external_reference,
                'off_session' => 'true',
                'confirm' => 'true',
                'metadata' => ['invoice_id' => $invoice->id, 'billing_agreement_id' => $billingAgreement->id],
            ]);
        } catch (Exception $e) {
            if ($e instanceof RequestException && $e->response->status() === 400) {
                $error = $e->response->object()->error;
                // If error is invalid_request_error and message contains "The provided currency" we can show the message
                if ($error->type === 'invalid_request_error' && Str::contains($error->message, 'The currency provided')) {
                    throw new DisplayException('Cannot charge the billing agreement because the card does not support the invoice currency (' . $invoice->currency_code . '). Please use another payment method.');
                }
            }
            throw new DisplayException('Could not process payment');
        }

        return true;
    }

    // Function to split and decode the Stripe-Signature header
    private function getHeaderValues($sig_header)
    {
        $parts = explode(',', $sig_header);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
            } elseif (strpos($part, 'v1=') === 0) {
                $signature = substr($part, 3);
            }
        }

        return [$timestamp, $signature];
    }

    // Validate the signature
    private function isValidSignature($payload, $sig_header, $secret)
    {
        [$timestamp, $signature] = $this->getHeaderValues($sig_header);

        if (empty($timestamp) || empty($signature) || empty($secret)) {
            return false;
        }

        // Create the signed payload string
        $signed_payload = $timestamp . '.' . $payload;

        // Compute the expected signature
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        // Compare the expected signature to the actual signature
        return hash_equals($expected_signature, $signature);
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

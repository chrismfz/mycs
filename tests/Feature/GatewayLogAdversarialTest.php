<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\Resources\PaymentGatewayEventResource\Pages\ListPaymentGatewayEvents;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Price;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\Mollie\Mollie;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Adversarial review of MT-2a (PR #64, docs/money-trail.md §4): the gateway log. Tests that FAIL on the branch expose
 * a finding; the rest stay as regression coverage of the invariants that held.
 */
class GatewayLogAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_adv';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private const FAILED_SUBJECT = 'Gateway request failed';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
    }

    // ---------------------------------------------------------------- helpers

    private function invoice(float $price = 100, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function gateway(string $extension, array $settings): Gateway
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $extension], ['name' => $extension, 'type' => 'gateway']);
        foreach ($settings as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $gateway->fresh();
    }

    private function stripe(): void
    {
        $this->gateway('Stripe', ['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]);
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
    }

    private function paypal(array $fakes = []): void
    {
        $gateway = $this->gateway('PayPal', ['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh']);
        $all = [self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token'])];
        foreach ($fakes as $path => $response) {
            $all[self::PAYPAL_API . $path] = $response;
        }
        Http::fake($all);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->settings);
        $paypal->boot();
    }

    private function mollie(array $fakes): void
    {
        $this->gateway('Mollie', ['api_key' => 'test_key']);
        Http::fake($fakes);
        (new Mollie(['api_key' => 'test_key']))->boot();
    }

    private function ipn(array $fakes): void
    {
        $this->gateway('PayPal_IPN', ['email' => 'biz@example.test', 'test_mode' => '1']);
        Http::fake($fakes);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();
    }

    /** @param  array<string, mixed>  $event */
    private function stripeWebhook(array $event, bool $signed = true)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = $signed ? hash_hmac('sha256', $time . '.' . $payload, self::SECRET) : 'forged';

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function succeeded(int|string $invoiceId, int $cents, string $id, string $currency = 'usd', string $type = 'payment_intent.succeeded'): array
    {
        return ['type' => $type, 'data' => ['object' => [
            'id' => $id, 'amount' => $cents, 'currency' => $currency, 'metadata' => ['invoice_id' => (string) $invoiceId],
        ]]];
    }

    /** A route of a made-up gateway, named like the real ones: what a gateway's handler does, under our control. */
    private function fakeGatewayRoute(string $endpoint, \Closure $action): string
    {
        Route::post('/_adv/' . $endpoint, $action)->name('extensions.gateways.adv.' . $endpoint);
        app('router')->getRoutes()->refreshNameLookups();

        return '/_adv/' . $endpoint;
    }

    private function signedIn(): static
    {
        return $this->actingAs($this->user)->withSession($this->loginUser($this->user));
    }

    /** @return list<string> mails to staff about payments (not the new user's verification mail) */
    private function staffMails(): array
    {
        return EmailLog::query()->whereIn('subject', [self::FAILED_SUBJECT, 'Payment needs attention'])->pluck('subject')->all();
    }

    private function alerts(): int
    {
        return EmailLog::query()->where('subject', self::FAILED_SUBJECT)->count();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addDays(3), 'currency_code' => 'USD', 'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => now()->addDays(3), 'period_end' => now()->addDays(3)->addMonth(),
        ]);

        return $invoice->fresh();
    }

    // ---------------------------------------------------------------- G1: one row per gateway request, always closed

    public function test_g1_a_handler_that_throws_closes_its_row_with_500(): void
    {
        $this->mollie(['https://api.mollie.com/v2/payments/tr_down' => Http::response(['detail' => 'Service unavailable'], 503)]);

        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_down'])->assertStatus(500);

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['mollie', 'webhook', 'error', 'http_500', 500], [$row->gateway, $row->endpoint, $row->outcome, $row->reason, $row->http_status]);
    }

    public function test_g1_rejections_before_the_controller_are_closed_rows(): void
    {
        $this->paypal();
        $this->stripe();
        $other = User::factory()->create();
        $theirs = Invoice::factory()->create(['user_id' => $other->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        // not signed in: the auth middleware redirects
        $this->post('/extensions/paypal/capture/' . $theirs->id . '/ORDER-1')->assertRedirect();
        // model binding: no such invoice
        $this->signedIn()->postJson('/extensions/paypal/capture/999999/ORDER-1')->assertNotFound();
        // authorisation: someone else's invoice (denied as not found)
        $this->signedIn()->postJson('/extensions/paypal/capture/' . $theirs->id . '/ORDER-1')->assertNotFound();
        // validation inside the controller
        $this->signedIn()->getJson('/extensions/stripe/setup-agreement')->assertStatus(422);

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([
            ['capture', 'ignored', 'nothing_recorded', 302],
            ['capture', 'rejected', 'http_404', 404],
            ['capture', 'rejected', 'http_404', 404],
            ['setup-agreement', 'rejected', 'http_422', 422],
        ], $rows->map(fn (PaymentGatewayEvent $row) => [$row->endpoint, $row->outcome, $row->reason, $row->http_status])->all());
        $this->assertSame(0, $this->alerts());
    }

    public function test_g1_an_abort_403_inside_the_handler_is_a_closed_row(): void
    {
        $this->stripe();
        Http::fake(['https://api.stripe.com/v1/setup_intents/*' => Http::response([
            'id' => 'seti_' . str_repeat('a', 24), 'status' => 'succeeded', 'metadata' => ['user_id' => 999999],
        ])]);

        $this->signedIn()->get('/extensions/stripe/setup-agreement?setup_intent=seti_' . str_repeat('a', 24))->assertForbidden();

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['GET', 'rejected', 'http_403', 403], [$row->method, $row->outcome, $row->reason, $row->http_status]);
        $this->assertNull($row->fields, 'a refused request keeps no names (MT-2a review)');
    }

    public function test_g1_a_handler_returning_null_is_ignored_and_one_row_per_request(): void
    {
        $this->stripe();
        $url = $this->fakeGatewayRoute('nothing', fn () => null);

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $this->assertSame([['ignored', 'nothing_recorded', 200], ['ignored', 'nothing_recorded', 200]],
            PaymentGatewayEvent::query()->orderBy('id')->get()->map(fn ($row) => [$row->outcome, $row->reason, $row->http_status])->all());
    }

    /**
     * FINDING (low): a request to a gateway's URL that fails route matching (wrong method → 405) never fires
     * RouteMatched, so it leaves no trace; a gateway misconfigured to call with GET (or a probe) is invisible.
     * Kept as is in MT-2a; fixed in MT-3 (GatewayLog::unmatched).
     */
    public function test_g1_a_wrong_method_on_a_webhook_url_leaves_a_row(): void
    {
        $this->stripe();

        $this->get('/extensions/stripe/webhook')->assertStatus(405);

        $this->assertSame(1, PaymentGatewayEvent::query()->count(), 'a request to the webhook URL with the wrong method left no row');
    }

    public function test_g1_no_row_for_jobs_staff_or_other_routes_and_no_leak_into_the_next_request(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $this->stripeWebhook(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]])->assertOk();

        // after the gateway request: a sync job and a direct (staff/cron-like) payment
        dispatch(function () use ($invoice) {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_job', currency: 'USD');
        });
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_cron', currency: 'USD');
        $this->getJson('/api/v1/admin/invoices')->assertUnauthorized();

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['ignored', 'nothing_recorded', null, null], [$row->outcome, $row->reason, $row->external_id, $row->invoice_id]);
        $this->assertSame(2, $invoice->transactions()->count());
    }

    // ---------------------------------------------------------------- G2: the outcome tells the truth about the money

    public function test_g2_an_outer_transaction_rolled_back_after_handle_is_not_recorded(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $url = $this->fakeGatewayRoute('rollback', function () use ($invoice) {
            try {
                DB::transaction(function () use ($invoice) {
                    ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_rolled', currency: 'USD');
                    throw new \RuntimeException('after the payment');
                });
            } catch (\RuntimeException) {
            }
            DB::beginTransaction();
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_rolled_2', currency: 'USD');
            DB::rollBack();

            return response()->json(['ok' => true]);
        });

        $this->post($url)->assertOk();

        $this->assertSame(0, $invoice->transactions()->count());
        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['ignored', 'nothing_recorded', null, null], [$row->outcome, $row->reason, $row->external_id, $row->invoice_transaction_id]);
    }

    public function test_g2_the_strongest_outcome_wins_whatever_the_order(): void
    {
        $this->stripe();
        $a = $this->invoice(100);
        $b = $this->invoice(50);
        $c = $this->invoice(30);
        ExtensionHelper::addPayment($a->id, 'Stripe', 100.00, null, 'pi_seen', currency: 'USD');

        $mixed = $this->fakeGatewayRoute('mixed', function () use ($a, $b, $c) {
            ExtensionHelper::addPayment($c->id, 'Stripe', 30.00, null, 'pi_new', currency: 'USD');   // recorded
            ExtensionHelper::addPayment($b->id, 'Stripe', 50.00, null, 'pi_eur', currency: 'EUR');   // rejected
            ExtensionHelper::addPayment($a->id, 'Stripe', 100.00, null, 'pi_seen', currency: 'USD'); // replay

            return response()->json(['ok' => true]);
        });
        $replayThenNew = $this->fakeGatewayRoute('replay-then-new', function () use ($a, $b) {
            ExtensionHelper::addPayment($a->id, 'Stripe', 100.00, null, 'pi_seen', currency: 'USD'); // replay
            ExtensionHelper::addPayment($b->id, 'Stripe', 50.00, null, 'pi_b', currency: 'USD');     // recorded

            return response()->json(['ok' => true]);
        });

        $this->post($mixed)->assertOk();
        $this->post($replayThenNew)->assertOk();

        [$first, $second] = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame(['rejected', 'currency_mismatch', 'EUR', 'pi_eur', $b->id], [$first->outcome, $first->reason, $first->currency_code, $first->external_id, $first->invoice_id]);
        $this->assertSame(['recorded', null, '50.00', 'pi_b', $b->id], [$second->outcome, $second->reason, $second->amount, $second->external_id, $second->invoice_id]);
        $this->assertSame($b->transactions()->sole()->id, $second->invoice_transaction_id);
    }

    public function test_g2_a_5xx_after_a_recorded_payment_is_an_error_that_keeps_the_money_details(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $url = $this->fakeGatewayRoute('recorded-then-boom', function () use ($invoice) {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_then_boom', currency: 'USD');
            throw new \RuntimeException('a renewal failed after the commit');
        });

        $this->post($url)->assertStatus(500);

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['error', 500, '100.00', 'pi_then_boom'], [$row->outcome, $row->http_status, $row->amount, $row->external_id]);
        $this->assertSame($invoice->transactions()->sole()->id, $row->invoice_transaction_id);
        $this->assertSame(1, $this->alerts());
    }

    /**
     * FINDING (low): the staff mail says «it is NOT recorded» even when the row itself shows the money WAS recorded
     * (e.g. a PayPal subscription sale whose allocation failed after the sale's commit, rethrown to get a retry).
     */
    public function test_g2_the_failure_mail_does_not_claim_recorded_money_was_not_recorded(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $url = $this->fakeGatewayRoute('recorded-then-fail', function () use ($invoice) {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_rec_fail', currency: 'USD');
            throw new \RuntimeException('allocation failed');
        });

        $this->post($url)->assertStatus(500);

        $this->assertNotNull(PaymentGatewayEvent::query()->sole()->invoice_transaction_id, 'the money was recorded');
        $mail = EmailLog::query()->where('subject', self::FAILED_SUBJECT)->sole();
        $this->assertStringNotContainsString('NOT recorded', $mail->body, 'the mail tells staff recorded money was not recorded');
    }

    public function test_g2_stripe_processing_failed_and_succeeded_and_subscription_invoice(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);

        $this->stripeWebhook($this->succeeded($invoice->id, 10000, 'pi_flow', type: 'payment_intent.processing'))->assertOk();
        $this->stripeWebhook($this->succeeded($invoice->id, 10000, 'pi_flow', type: 'payment_intent.payment_failed'))->assertOk();
        $this->stripeWebhook($this->succeeded($invoice->id, 10000, 'pi_flow'))->assertOk();

        $service = $this->service(['subscription_id' => 'sub_adv']);
        $renewal = $this->invoiceFor($service);
        Http::fake(['https://api.stripe.com/v1/invoice_payments*' => Http::response(['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_sub_adv']]]])]);
        $this->stripeWebhook(['type' => 'invoice.payment_succeeded', 'data' => ['object' => [
            'id' => 'in_adv', 'amount_paid' => 1000, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_adv']],
        ]]])->assertOk();

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([
            ['payment_intent.processing', 'recorded', 'processing', '100.00', 'pi_flow', $invoice->id],
            ['payment_intent.payment_failed', 'recorded', 'failed', '100.00', 'pi_flow', $invoice->id],
            ['payment_intent.succeeded', 'recorded', null, '100.00', 'pi_flow', $invoice->id],
            ['invoice.payment_succeeded', 'recorded', null, '10.00', 'pi_sub_adv', $renewal->id],
        ], $rows->map(fn ($row) => [$row->event_type, $row->outcome, $row->reason, $row->amount, $row->external_id, $row->invoice_id])->all());
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_g2_paypal_subscription_sale_capture_and_replay(): void
    {
        $a = $this->service(['subscription_id' => 'I-ADV']);
        $b = $this->service(['subscription_id' => 'I-ADV']);
        $invoiceA = $this->invoiceFor($a);
        $invoiceB = $this->invoiceFor($b);
        $capture = $this->invoice(100);
        $this->paypal([
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            '/v2/checkout/orders/ORDER-ADV' => Http::response(['status' => 'APPROVED', 'purchase_units' => [['invoice_id' => (string) $capture->id, 'amount' => ['currency_code' => 'USD', 'value' => '100.00']]]]),
            '/v2/checkout/orders/ORDER-ADV/capture' => Http::response(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAP-ADV', 'status' => 'COMPLETED', 'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            ]]]]]]),
        ]);
        $sale = ['event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => [
            'id' => 'SALE-ADV', 'billing_agreement_id' => 'I-ADV', 'amount' => ['total' => '20.00', 'currency' => 'USD'], 'transaction_fee' => ['value' => '1.00'],
        ]];

        $this->postJson('/extensions/paypal/webhook', $sale)->assertOk();
        $this->postJson('/extensions/paypal/webhook', $sale)->assertOk();
        // An invoice is bound by number OR id (Invoice::resolveRouteBinding): the paid invoices' numbers (from a counter
        // other tests moved) must not take the capture invoice's id.
        DB::table('invoices')->whereIn('id', [$invoiceA->id, $invoiceB->id])->update(['number' => null]);
        $this->signedIn()->postJson('/extensions/paypal/capture/' . $capture->id . '/ORDER-ADV')->assertOk();

        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('paid', $invoiceB->fresh()->status);
        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([
            ['webhook', 'PAYMENT.SALE.COMPLETED', true, 'recorded', null, '20.00', 'SALE-ADV', $invoiceA->id],
            ['webhook', 'PAYMENT.SALE.COMPLETED', true, 'ignored', 'replay', '20.00', 'SALE-ADV', $invoiceA->id],
            ['capture', null, null, 'recorded', null, '100.00', 'CAP-ADV', $capture->id],
        ], $rows->map(fn ($row) => [$row->endpoint, $row->event_type, $row->signature_verified, $row->outcome, $row->reason, $row->amount, $row->external_id, $row->invoice_id])->all());
    }

    public function test_g2_paypal_ipn_and_mollie_payments_are_recorded(): void
    {
        $invoice = $this->invoice(100);
        $mollieInvoice = $this->invoice(40);
        $this->ipn(['https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' => Http::response('VERIFIED')]);
        $this->mollie(['https://api.mollie.com/v2/payments/tr_adv' => Http::response([
            'id' => 'tr_adv', 'status' => 'paid', 'amount' => ['value' => '40.00', 'currency' => 'USD'], 'metadata' => ['invoice_id' => $mollieInvoice->id],
        ])]);

        $this->post('/extensions/paypal_ipn/notify', [
            'txn_type' => 'web_accept', 'receiver_email' => 'biz@example.test', 'payment_status' => 'Completed', 'item_number' => (string) $invoice->id,
            'mc_gross' => '100.00', 'mc_fee' => '3.20', 'mc_currency' => 'USD', 'txn_id' => 'IPN-ADV', 'payer_email' => 'buyer@example.test',
            'first_name' => 'Γιώργος', 'address_street' => 'Odos 1',
        ])->assertOk();
        $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_adv'])->assertOk();

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([
            ['paypal_ipn', 'notify', 'web_accept', 'recorded', '100.00', 'USD', 'IPN-ADV', $invoice->id],
            ['mollie', 'webhook', null, 'recorded', '40.00', 'USD', 'tr_adv', $mollieInvoice->id],
        ], $rows->map(fn ($row) => [$row->gateway, $row->endpoint, $row->event_type, $row->outcome, $row->amount, $row->currency_code, $row->external_id, $row->invoice_id])->all());
        $stored = json_encode($rows[0]->getAttributes());
        foreach (['buyer@example.test', 'biz@example.test', 'Odos', 'Completed', '3.20'] as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }
    }

    /**
     * FINDING (medium): a SIGNED Stripe payment for an invoice that is gone (deleted, wrong id in metadata) makes
     * RecordPaymentService::lockInvoice() throw ModelNotFoundException, rendered as 404. The row says
     * rejected/http_404 with no provider id, and staff are never told — exactly the «the gateway says it charged but the
     * money was not written» case §4 wants an alert for. Stripe keeps retrying a 404 for days, silently.
     */
    public function test_g2_g4_a_signed_payment_for_a_missing_invoice_is_traceable_and_alerts_staff(): void
    {
        $this->stripe();

        $this->stripeWebhook($this->succeeded(999999, 10000, 'pi_gone'));

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertTrue($row->signature_verified);
        $this->assertSame('pi_gone', $row->external_id, 'the charged payment\'s provider id is not in the log: nothing to reconcile with');
        $this->assertNotSame([], $this->staffMails(), 'a verified charge that could not be recorded mailed nobody');
    }

    // ---------------------------------------------------------------- G3: names only, never values

    public function test_g3_hostile_payloads_keep_only_bounded_names(): void
    {
        $this->stripe();
        $event = ['type' => str_repeat('a', 5000), 'event_type' => ['nested' => 'x'], 'txn_type' => 'ünïcode'];
        for ($i = 0; $i < 1000; $i++) {
            $event['k' . $i] = 'v' . $i;
        }
        $event['john.doe@example.com'] = 'x';
        $event['ονομα'] = 'Γιώργος';
        $event['<script>'] = 'x';
        $event['nested'] = ['card' => '4111111111111111', 'email' => 'nested@example.com'];
        $event[str_repeat('b', 65)] = 'x';

        $this->stripeWebhook($event, signed: false)->assertStatus(400);

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertNull($row->event_type);
        $this->assertLessThanOrEqual(50, count($row->fields ?? []));
        $stored = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['@', '4111111111111111', 'Γιώργος', 'ονομα', '<script>', 'v1', str_repeat('b', 65)] as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }
    }

    /**
     * FINDING (low): a field NAME that is a value passes the name filter ([A-Za-z0-9_.-]{1,64}): a card number, a
     * customer domain or an API-key-shaped token sent as a key is kept in `fields`.
     */
    public function test_g3_a_key_that_is_a_card_number_is_not_kept(): void
    {
        $this->stripe();
        $event = $this->succeeded(1, 100, 'pi_x') + ['4111111111111111' => '1', 'customer-shop.gr' => '1'];

        $this->stripeWebhook($event, signed: false)->assertStatus(400);

        $stored = json_encode(PaymentGatewayEvent::query()->sole()->getAttributes());
        $this->assertStringNotContainsString('4111111111111111', $stored, 'a card number sent as a field name is stored');
        $this->assertStringNotContainsString('customer-shop.gr', $stored, 'a domain sent as a field name is stored');
    }

    /**
     * FINDING (low): the event type is any short [A-Za-z0-9_.-] string from `type`/`event_type`/`txn_type`, so a
     * value carried there (a card number, a domain, a token) is stored as the «event type».
     */
    public function test_g3_a_type_field_carrying_a_value_is_not_kept(): void
    {
        $this->stripe();

        $this->stripeWebhook(['type' => '4111111111111111', 'data' => []], signed: false)->assertStatus(400);
        $this->stripeWebhook(['type' => 'customer-shop.gr', 'data' => []], signed: false)->assertStatus(400);

        $types = PaymentGatewayEvent::query()->orderBy('id')->pluck('event_type')->all();
        $this->assertNotContains('4111111111111111', $types, 'a card number in `type` is stored as the event type');
        $this->assertNotContains('customer-shop.gr', $types, 'a domain in `type` is stored as the event type');
    }

    // ---------------------------------------------------------------- G4: alerts and failures of the log itself

    public function test_g4_forged_stripe_and_paypal_spam_never_mails_staff(): void
    {
        $this->stripe();
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);

        for ($i = 0; $i < 10; $i++) {
            $this->stripeWebhook($this->succeeded(1, 100, 'pi_' . $i), signed: false)->assertStatus(400);
            $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.SALE.COMPLETED'])->assertStatus(400);
        }

        $this->assertSame(20, PaymentGatewayEvent::query()->where('outcome', 'rejected')->count());
        $this->assertSame([], $this->staffMails());
    }

    /**
     * FINDING (low-medium): anyone can POST a made-up id to the Mollie webhook; Mollie's API answers 404, the
     * extension throws, the request is a 500 and staff are mailed («Gateway request failed»). Forged webhook spam does
     * mail staff (once an hour, every hour, for as long as it goes on) and buries real failures under the cooldown.
     */
    public function test_g4_a_forged_mollie_webhook_does_not_mail_staff(): void
    {
        $this->mollie(['https://api.mollie.com/v2/payments/*' => Http::response(['detail' => 'No payment exists with token tr_forged.'], 404)]);

        $response = $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_forged']);

        $this->assertSame(0, $this->alerts(), 'a forged Mollie webhook (unknown id, HTTP ' . $response->getStatusCode() . ') mailed staff');
    }

    public function test_g4_cooldown_is_per_gateway_and_endpoint(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'SERVICE_UNAVAILABLE'], 503)]);
        $this->gateway('Mollie', ['api_key' => 'test_key']);
        Http::fake(['https://api.mollie.com/*' => Http::response(['detail' => 'down'], 503)]);
        (new Mollie(['api_key' => 'test_key']))->boot();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/extensions/paypal/webhook', ['event_type' => 'X'])->assertStatus(500);
            $this->post('/extensions/gateways/mollie/webhook', ['id' => 'tr_' . $i])->assertStatus(500);
        }

        $this->assertSame(6, PaymentGatewayEvent::query()->where('outcome', 'error')->count());
        $this->assertSame(2, $this->alerts());
    }

    public function test_g4_a_failing_mail_never_changes_the_response(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'SERVICE_UNAVAILABLE'], 503)]);
        EmailLog::creating(fn () => throw new \RuntimeException('smtp/db down'));

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'X'])->assertStatus(500);

        $this->assertSame('error', PaymentGatewayEvent::query()->sole()->outcome);
    }

    public function test_g4_the_log_failing_to_insert_never_breaks_a_payment(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        PaymentGatewayEvent::creating(fn () => throw new \RuntimeException('gateway log table down'));

        $this->stripeWebhook($this->succeeded($invoice->id, 10000, 'pi_no_log'))->assertOk();

        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_g4_the_log_failing_to_close_never_breaks_a_payment(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        PaymentGatewayEvent::updating(fn () => throw new \RuntimeException('gateway log table down'));

        $this->stripeWebhook($this->succeeded($invoice->id, 10000, 'pi_no_close'))->assertOk();

        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /**
     * FINDING (low): the alert's Cache::add() and Log::warning() are outside the try: a cache store that is down (Redis)
     * throws out of the RequestHandled listener, i.e. out of Kernel::handle(), after the response was built. The
     * gateway's response is replaced by the global handler's and terminate() never runs.
     */
    public function test_g4_a_cache_failure_in_the_alert_never_escapes_the_kernel(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'SERVICE_UNAVAILABLE'], 503)]);
        Cache::extend('adv_broken', fn () => new class(new ArrayStore) extends Repository
        {
            public function add($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('cache store down');
            }
        });
        config(['cache.stores.adv_broken' => ['driver' => 'adv_broken']]);
        Cache::setDefaultDriver('adv_broken');

        try {
            $response = $this->postJson('/extensions/paypal/webhook', ['event_type' => 'X']);
        } catch (\Throwable $e) {
            $this->fail('the alert\'s cache failure escaped the HTTP kernel: ' . $e->getMessage());
        }
        $response->assertStatus(500);
    }

    // ---------------------------------------------------------------- G5: the admin screen

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_g5_read_only_and_needs_its_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $row = PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => 'recorded']);
        $clerk = Staff::factory()->create(['role_id' => Role::create(['name' => 'Clerk' . uniqid(), 'permissions' => ['admin.invoices.viewAny', 'admin.invoices.viewAny']])->id]);
        $reader = Staff::factory()->create(['role_id' => Role::create(['name' => 'Reader' . uniqid(), 'permissions' => ['admin.gateway_events.viewAny']])->id]);
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->actingAs($clerk);
        $this->assertFalse(PaymentGatewayEventResource::canAccess());
        $this->assertFalse(PaymentGatewayEventResource::canViewAny());
        $this->actingAs($this->user);
        $this->assertFalse(PaymentGatewayEventResource::canAccess());

        $this->actingAs($reader);
        $this->assertTrue(PaymentGatewayEventResource::canAccess());

        $this->actingAs($owner);
        $this->assertSame(['index'], array_keys(PaymentGatewayEventResource::getPages()));
        $this->assertFalse(PaymentGatewayEventResource::canCreate());
        $this->assertFalse(PaymentGatewayEventResource::canEdit($row));
        $this->assertFalse(PaymentGatewayEventResource::canDelete($row));
        $this->assertFalse($owner->can('create', PaymentGatewayEvent::class));
        Livewire::actingAs($owner)->test(ListPaymentGatewayEvents::class)
            ->assertSuccessful()
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_g5_invoice_link_needs_invoice_view_and_stored_text_is_escaped(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $invoice = $this->invoice(100);
        PaymentGatewayEvent::query()->create([
            'gateway' => '<b>gw</b>', 'endpoint' => '<i>ep</i>', 'method' => 'POST', 'event_type' => '<script>alert(1)</script>',
            'outcome' => '<u>recorded</u>', 'reason' => '<img src=x onerror=alert(2)>', 'external_id' => '"><svg onload=alert(3)>',
            'invoice_id' => $invoice->id, 'amount' => 1, 'currency_code' => 'U<s', 'http_status' => 200, 'fields' => ['<marquee>'],
        ]);
        $reader = Staff::factory()->create(['role_id' => Role::create(['name' => 'Reader' . uniqid(), 'permissions' => ['admin.gateway_events.viewAny']])->id]);
        $boss = Staff::factory()->create(['role_id' => Role::create(['name' => 'Boss' . uniqid(), 'permissions' => ['admin.gateway_events.viewAny', 'admin.invoices.viewAny']])->id]);
        $url = InvoiceResource::getUrl('edit', ['record' => $invoice->id]);

        $readerView = Livewire::actingAs($reader)->test(ListPaymentGatewayEvents::class)->assertSuccessful()->assertDontSeeHtml($url);
        foreach (['<script>alert(1)', '<img src=x', '<svg onload', '<b>gw</b>', '<i>ep</i>', '<u>recorded', '<marquee>'] as $raw) {
            $readerView->assertDontSeeHtml($raw);
        }
        Livewire::actingAs($boss)->test(ListPaymentGatewayEvents::class)->assertSuccessful()->assertSeeHtml($url);
    }
}

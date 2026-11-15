<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Review round 3 of the anchor move, against Stripe's docs (production, 2026-11-15). Moving a subscription to the anchor
 * puts it in a trial (trial_end), and Stripe answers that with a $0 invoice at once (docs: billing-cycle, «Change the
 * billing period using a trial period»): its invoice.payment_succeeded had no payment to look up (500, Stripe retrying
 * for days, R3-F1) and its draft invoice.created was finalised and paid (/pay on a $0 invoice fails, R3-F2). A trial_end
 * Stripe refuses (past, more than two years ahead) or on a subscription that does not bill normally is not tried
 * (R3-F5..F7). Each test failed on 1986156.
 */
class StripeAnchorMoveReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private string $zone;

    /** GET /v1/subscriptions/*: its status and period end. */
    private array $stripe = ['status' => 'active', 'period_end' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->zone = date_default_timezone_get();
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false]);
        date_default_timezone_set('Europe/Athens'); // after the settings: reading them sets the app's timezone again
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Europe/Athens'));
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        $gateway->settings()->create(['key' => 'stripe_use_subscriptions', 'value' => '1', 'type' => 'boolean']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn () => Http::response(['id' => 'sub_1', 'status' => $this->stripe['status'], 'schedule' => null,
                'items' => ['data' => [['id' => 'si_1', 'price' => ['product' => 'prod_1'], 'current_period_end' => $this->stripe['period_end']]]]]),
            'https://api.stripe.com/v1/invoice_payments*' => Http::response(['data' => []]), // a $0 invoice: no payment
            'https://api.stripe.com/v1/invoices/*' => Http::response(['id' => 'in_1', 'status' => 'paid']),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    private function subscribed(string $expires = '2026-12-16'): Service
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $expires, 'subscription_id' => 'sub_1']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Stripe', 'value' => '1']);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => $expires]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $service->fresh();
    }

    /** @param  array<string, mixed>  $object */
    private function event(string $type, array $object): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => 'evt_' . $type, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    private const PARENT = ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']];

    /** @return list<int> the trial_end values sent */
    private function trialEnds(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/subscriptions/sub_1'))
            ->map(fn (array $pair) => (int) $pair[0]['trial_end'])->values()->all();
    }

    /** R3-F1: the trial's $0 invoice paid nothing: answered 200, nothing recorded, no «payment not made here» mail. */
    public function test_a_zero_invoice_payment_succeeded_is_answered_and_records_nothing(): void
    {
        $service = $this->subscribed();

        $this->event('invoice.payment_succeeded', ['id' => 'in_trial', 'object' => 'invoice', 'amount_paid' => 0, 'currency' => 'usd',
            'billing_reason' => 'subscription_update', 'parent' => self::PARENT])->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $service->invoices()->sole()->status);
        $this->assertSame(0, EmailLog::query()->where('subject', 'Gateway event for a payment not made here')->count());
    }

    /** R3-F2: the trial's $0 draft is not finalised or paid here (Stripe finalises it; /pay would fail the webhook). */
    public function test_a_zero_draft_invoice_is_not_finalised_or_paid(): void
    {
        $this->subscribed();

        $this->event('invoice.created', ['id' => 'in_trial', 'object' => 'invoice', 'status' => 'draft', 'amount_due' => 0,
            'billing_reason' => 'subscription_update', 'parent' => self::PARENT])->assertOk();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/invoices/in_trial/'));
    }

    public function test_holds_a_renewal_draft_with_an_amount_is_still_finalised_and_paid(): void
    {
        $this->subscribed();

        $this->event('invoice.created', ['id' => 'in_next', 'object' => 'invoice', 'status' => 'draft', 'amount_due' => 1000,
            'billing_reason' => 'subscription_cycle', 'parent' => self::PARENT])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices/in_next/finalize'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/invoices/in_next/pay'));
    }

    /** R3-F6: past_due (its invoice still retried) or paused collection: not moved into a trial. */
    public function test_a_subscription_that_does_not_bill_normally_is_not_moved(): void
    {
        $this->stripe['period_end'] = Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp;
        $service = $this->subscribed('2026-12-16'); // each change below is past Stripe's period end: a move, if it billed
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        foreach (['past_due', 'unpaid'] as $status) {
            $this->stripe['status'] = $status;
            DB::transaction(fn () => $service->fresh()->update(['expires_at' => $service->fresh()->expires_at->addDay()]));
        }

        $this->assertSame([], $this->trialEnds());
    }

    /** R3-F7: a due day whose noon has passed (staff set today, in the afternoon): Stripe refuses a trial_end in the past. */
    public function test_an_anchor_in_the_past_is_not_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 16:00:00', 'Europe/Athens'));
        $this->stripe['period_end'] = Carbon::parse('2026-11-10 00:00:00', 'Europe/Athens')->timestamp;
        $service = $this->subscribed('2026-11-10');
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        DB::transaction(fn () => $service->update(['expires_at' => '2026-11-15']));

        $this->assertSame([], $this->trialEnds());
    }

    /** R3-F5: a trial_end may be at most two years ahead: a due day further out is not sent (and the command says so). */
    public function test_an_anchor_more_than_two_years_ahead_is_not_sent_and_is_listed_for_staff(): void
    {
        $this->stripe['period_end'] = Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp;
        $service = $this->subscribed('2026-12-16');
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        DB::transaction(fn () => $service->update(['expires_at' => '2029-12-16']));
        $this->assertSame([], $this->trialEnds());

        $this->artisan('stripe:period-anchors --apply')
            ->expectsOutputToContain('more than two years ahead')
            ->assertSuccessful();
        $this->assertSame([], $this->trialEnds());
    }

    public function test_the_repair_leaves_a_past_due_subscription_for_staff(): void
    {
        $this->stripe = ['status' => 'past_due', 'period_end' => Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp];
        $this->subscribed('2026-12-16');

        $this->artisan('stripe:period-anchors --apply')
            ->expectsOutputToContain('it is past_due at Stripe, left for staff')
            ->assertSuccessful();

        $this->assertSame([], $this->trialEnds());
    }

    public function test_holds_an_active_subscription_is_still_moved_once(): void
    {
        $this->stripe['period_end'] = Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp;
        $service = $this->subscribed('2026-11-16');
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertSame([Carbon::parse('2026-12-16 12:00:00', 'Europe/Athens')->timestamp], $this->trialEnds());
    }
}

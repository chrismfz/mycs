<?php

namespace Tests\Feature;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SubscriptionEnded;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Stripe side of L3 (review of #602, follow-up of #605): a Stripe subscription cancelled in the dashboard or the
 * customer portal (or unpaid after the retries, or paused) kept its id on the service: `subscription_schedule.canceled`
 * looked the service up by the schedule's id, which no service carries, and `customer.subscription.deleted` was not
 * handled. So the cron, the reminders and Mass Pay left the invoices to a subscription that no longer billed.
 */
class StripeSubscriptionEndedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    /** What Stripe answers to GET /subscriptions/{id}: a status, or an HTTP error code. */
    private string|int $status = 'canceled';

    /** Whether Stripe's answer has its payment collection paused. */
    private bool $pauseCollection = false;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false, 'autocharge_days_before' => 0]);
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn (Request $request) => match (true) {
                $request->method() === 'DELETE' => Http::response(['id' => basename($request->url()), 'status' => 'canceled']),
                is_int($this->status) => Http::response(['error' => ['code' => $this->status === 404 ? 'resource_missing' : 'rate_limit']], $this->status),
                default => Http::response(['id' => basename($request->url()), 'status' => $this->status,
                    'pause_collection' => $this->pauseCollection ? ['behavior' => 'void'] : null]),
            },
            'https://api.stripe.com/v1/invoice_payments*' => Http::response(['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_late']]]]),
        ]);
        $this->user = User::factory()->create();
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribed(string $subscription = 'sub_1'): Service
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-11-10', 'subscription_id' => $subscription]);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Stripe', 'value' => '1']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $service->fresh();
    }

    private function invoiceOf(Service $service): Invoice
    {
        return $service->invoices()->orderBy('invoices.id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $object */
    private function event(string $type, array $object): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => 'evt_' . $type, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    private function asked(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '/v1/subscriptions/'))->count();
    }

    public function test_a_deleted_subscription_clears_the_id_so_the_invoice_is_charged_and_mass_payable_again(): void
    {
        $service = $this->subscribed();
        $this->assertFalse(AutoCharge::picks($this->invoiceOf($service)), 'precondition: left to the subscription');

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled'])->assertOk();

        $service->refresh();
        $this->assertNull($service->subscription_id);
        $this->assertFalse($service->properties()->where('key', 'has_stripe_subscription')->exists());
        $this->assertTrue($service->properties()->where('key', SubscriptionEnded::keptKey('sub_1'))->exists());
        $this->assertTrue(AutoCharge::picks($this->invoiceOf($service)));
        $this->assertNull(MassPay::refusal($this->invoiceOf($service)));
    }

    /** It looked the service up by the schedule's id (sub_sched_...): never one a service carries. */
    public function test_a_cancelled_schedule_clears_its_subscription(): void
    {
        $service = $this->subscribed();

        $this->event('subscription_schedule.canceled', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'subscription' => 'sub_1'])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
    }

    /** @return array<string, array{string, string}> */
    public static function endings(): array
    {
        return [
            'unpaid after the retries' => ['customer.subscription.updated', 'unpaid'],
            'paused' => ['customer.subscription.paused', 'paused'],
        ];
    }

    #[DataProvider('endings')]
    public function test_an_unpaid_or_paused_subscription_clears_it_too(string $type, string $status): void
    {
        $service = $this->subscribed();
        $this->status = $status;

        $this->event($type, ['id' => 'sub_1', 'object' => 'subscription', 'status' => $status])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
    }

    /** The subscription's own updates (its price, trial_end: updateSubscription) never ask Stripe, never clear it. */
    public function test_a_routine_update_changes_nothing_and_asks_nothing(): void
    {
        $service = $this->subscribed();

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'trialing'])->assertOk();
        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'past_due'])->assertOk();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
        $this->assertSame(0, $this->asked());
    }

    public function test_an_unknown_id_or_a_replay_changes_nothing(): void
    {
        $service = $this->subscribed('sub_mine');

        $this->event('customer.subscription.deleted', ['id' => 'sub_other', 'status' => 'canceled'])->assertOk();
        $this->event('customer.subscription.deleted', ['id' => 'sub_../../charges', 'status' => 'canceled'])->assertOk();
        $this->assertSame(0, $this->asked());
        $this->event('customer.subscription.deleted', ['id' => 'sub_mine', 'status' => 'canceled'])->assertOk();
        $this->event('customer.subscription.deleted', ['id' => 'sub_mine', 'status' => 'canceled'])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(1, $this->asked(), 'a replay finds nothing to clear');
    }

    public function test_an_ending_stripe_does_not_confirm_changes_nothing(): void
    {
        $service = $this->subscribed();
        $this->status = 'active';

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'])->assertOk();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
    }

    public function test_stripe_not_answering_keeps_the_id_and_the_event_is_retried(): void
    {
        $service = $this->subscribed();
        $this->status = 429;

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'])->assertStatus(500);

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
    }

    public function test_a_subscription_stripe_does_not_know_keeps_the_id(): void
    {
        $service = $this->subscribed();
        $this->status = 404;

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'])->assertOk();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
    }

    /** Webhooks come in any order: the cycle's payment, sent after the deletion, is still the service's money. */
    public function test_a_payment_that_comes_after_the_deletion_is_still_recorded(): void
    {
        $service = $this->subscribed();
        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'])->assertOk();

        $this->event('invoice.payment_succeeded', ['id' => 'in_1', 'object' => 'invoice', 'amount_paid' => 1000, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($service)->status);
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'pi_late')->count());
    }

    /** Staff cancel it (Stripe's own cancelSubscription clears the id): its payment already on its way still pays the invoice. */
    public function test_a_payment_after_staff_cancelled_it_is_still_recorded(): void
    {
        $service = $this->subscribed();
        $this->assertNull(CancelSubscriptionAction::cancel($service));
        $this->assertNull($service->fresh()->subscription_id);

        $this->event('invoice.payment_succeeded', ['id' => 'in_1', 'object' => 'invoice', 'amount_paid' => 1000, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($service)->status);
    }

    /** Ended (so cleared), then billing again (an unpaid one paid, a paused one resumed): staff decide. */
    public function test_a_subscription_billing_again_is_told_to_staff_and_not_set_back(): void
    {
        $service = $this->subscribed();
        $this->status = 'unpaid';
        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'])->assertOk();
        $this->status = 'active';

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'active'])->assertOk();
        $this->event('customer.subscription.resumed', ['id' => 'sub_1', 'status' => 'active'])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Stripe subscription active again')->count());
    }

    public function test_new_webhooks_are_made_with_the_subscription_events(): void
    {
        $gateway = Gateway::query()->where('extension', 'Stripe')->sole();
        $gateway->settings()->where('key', 'stripe_webhook_secret')->delete();
        Http::fake([
            'https://api.stripe.com/v1/webhook_endpoints' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => []]) : Http::response(['id' => 'we_1', 'secret' => 'whsec_new']),
        ]);

        (new Stripe(['stripe_secret_key' => 'sk_test']))->updated($gateway);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/webhook_endpoints')
            && in_array('customer.subscription.deleted', (array) ($request->data()['enabled_events'] ?? []), true)
            && in_array('customer.subscription.updated', (array) ($request->data()['enabled_events'] ?? []), true));
    }

    /** Code review of #607: «Pause payment collection» keeps the status active; nothing is collected, so it has ended here. */
    public function test_a_paused_payment_collection_clears_it(): void
    {
        $service = $this->subscribed();
        $this->status = 'active';
        $this->pauseCollection = true;

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'active', 'pause_collection' => ['behavior' => 'void']])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
    }

    /** Code review of #607: unpaid (or paused) can bill again: staff are told, so they may cancel it in Stripe. */
    public function test_an_unpaid_subscription_cleared_is_told_to_staff(): void
    {
        $this->subscribed();
        $this->status = 'unpaid';

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'])->assertOk();
        $this->status = 'canceled';
        $this->subscribed('sub_2');
        $this->event('customer.subscription.deleted', ['id' => 'sub_2', 'status' => 'canceled'])->assertOk();

        $this->assertSame(1, EmailLog::query()->where('subject', 'Stripe subscription stopped billing')->count(), 'not for one cancelled for good');
    }

    /** Code review of #607: a subscription payment no service of ours has is told to staff, as PayPal's. */
    public function test_a_payment_of_a_subscription_no_service_had_is_told_to_staff(): void
    {
        $this->subscribed('sub_mine');

        $this->event('invoice.payment_succeeded', ['id' => 'in_9', 'object' => 'invoice', 'amount_paid' => 1000, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_unknown']]])->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway event for a payment not made here')->count());
    }

    /** ADV-607-1, the delete path: a service deleted with an unpaid subscription cleared from it cancels it at Stripe. */
    public function test_an_unpaid_subscription_is_cancelled_when_its_service_is_deleted(): void
    {
        $service = $this->subscribed();
        $this->status = 'unpaid';
        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'])->assertOk();
        $this->invoiceOf($service)->update(['status' => Invoice::STATUS_CANCELLED]);

        rescue(fn () => app(DeleteService::class)->handle($service->fresh()), report: false);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/v1/subscriptions/sub_1'));
    }
}

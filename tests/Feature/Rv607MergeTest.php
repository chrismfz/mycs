<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Review of #607 merged with main (2026-11-06): what the merge left between #607's kept ids and main's code.
 */
class Rv607MergeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private string $status = 'unpaid';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => true]);
        $gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
        $gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response(['id' => basename($request->url()), 'status' => 'canceled'])
                : Http::response(['id' => basename($request->url()), 'status' => $this->status]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $object */
    private function event(string $type, array $object): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => 'evt_' . $type, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    /**
     * RV-607-M1 — A Stripe subscription cleared as `unpaid` (or `paused`) may bill again (ADV-607-1), and the PR's fix
     * cancels it when the service ends, through the id kept on the service. MoveToClient no longer refuses the service
     * (no subscription id on it) and forgets the kept ids (#605's rule; main extended it to the addons): the service, now
     * another customer's, ends later and the old customer's subscription is never cancelled. Revived, it charges the old
     * customer's card each cycle for a service that is not theirs; each payment only reaches staff as «foreign».
     * Either the move cancels (or refuses) it, or the end still finds it.
     */
    public function test_rv_607_m1_a_moved_service_still_cancels_the_old_customers_revivable_subscription(): void
    {
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $from = User::factory()->create();
        $to = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $from->client()->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-12-10', 'subscription_id' => 'sub_1']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Stripe', 'value' => '1']);
        $this->assertSame(0, Invoice::query()->count());

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'unpaid'])->assertOk();
        $this->assertNull($service->fresh()->subscription_id, 'precondition: cleared by #607');

        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $moved = rescue(fn () => app(MoveToClient::class)->service($service->fresh(), $to->client(), $staff), false, false);
        if ($moved !== false) {
            $service->fresh()->markTerminated();
        }

        $cancelled = Http::recorded(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1/subscriptions/sub_1'))->isNotEmpty();
        $this->assertTrue($moved === false || $cancelled,
            'the service moved to another customer and ended, but the old customer\'s unpaid Stripe subscription was never cancelled');
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * S7-PAYMENTS-2: the account's Stripe customer (its saved cards are attached to it) is replaced only when Stripe itself
 * says it does not exist (404 resource_missing, and not «exists in the other mode»: REV-774-5) or was deleted. No answer (a timeout, a 5xx, a 429) or any other refusal
 * keeps it, and the checkout or the new card is refused for now.
 */
class Sweep7StripeCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Gateway $stripe;

    private Invoice $invoice;

    /** @var list<string> */
    private array $posted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create()->client();
        $this->client->properties()->create(['key' => 'stripe_id', 'name' => 'Stripe customer', 'value' => 'cus_OLD']);
        $this->stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_key', 'encrypted' => true]);
        $this->stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_key']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()]);
        $this->invoice->items()->create(['description' => 'x', 'price' => '10.00', 'quantity' => 1]);
        View::addNamespace('gateways.stripe', base_path('extensions/Gateways/Stripe/resources/views'));
    }

    /** Stripe answers the read of cus_OLD with $read (a response, or a closure that throws); everything else works. */
    private function stripeAnswers(mixed $read): void
    {
        Http::fake(function (Request $request) use ($read) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/customers/cus_OLD')) {
                return $read instanceof \Closure ? $read() : $read;
            }
            $this->posted[] = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($request->url(), '/customers') => Http::response(['id' => 'cus_NEW']),
                str_ends_with($request->url(), '/payment_intents') => Http::response(['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'amount' => 1000, 'currency' => 'usd', 'customer' => $request['customer'] ?? null]),
                str_ends_with($request->url(), '/setup_intents') => Http::response(['id' => 'seti_1', 'client_secret' => 'seti_1_secret']),
                default => Http::response([], 404),
            };
        });
    }

    private function storedCustomer(): ?string
    {
        return $this->client->properties()->where('key', 'stripe_id')->value('value');
    }

    /** @return array<string, array{0: \Closure}> */
    public static function noAnswerAboutTheCustomer(): array
    {
        return [
            'a 503' => [fn () => Http::response(['error' => ['type' => 'api_error']], 503)],
            'a 500' => [fn () => Http::response('', 500)],
            'rate limited' => [fn () => Http::response(['error' => ['type' => 'rate_limit_error']], 429)],
            'a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
        ];
    }

    /** @return array<string, array{0: \Closure}> not an outage: trying again does not help, staff are told */
    public static function refusedAboutTheCustomer(): array
    {
        return [
            'the key refused' => [fn () => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_expired']], 401)],
            'a 404 that is not «no such customer»' => [fn () => Http::response('<html>not found</html>', 404)],
            'a key of the other mode' => [fn () => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing',
                'message' => "No such customer: 'cus_OLD'; a similar object exists in live mode, but a test mode key was used to make this request."]], 404)],
        ];
    }

    #[DataProvider('refusedAboutTheCustomer')]
    public function test_a_refusal_that_is_not_an_outage_keeps_the_customer_and_tells_staff_once(\Closure $read): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        Cache::flush();
        $this->stripeAnswers($read);

        foreach ([1, 2] as $try) {
            try {
                ExtensionHelper::pay($this->stripe->fresh(), $this->invoice->fresh());
                $this->fail('the checkout opened while Stripe refused to read the account\'s customer');
            } catch (DisplayException $e) {
                $this->assertSame(__('This payment method is not available right now. We have been told and are looking into it: please pay another way, or try again later.'), $e->getMessage());
            }
        }

        $this->assertSame('cus_OLD', $this->storedCustomer());
        $this->assertSame([], $this->posted);
        $this->assertSame(1, EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'Stripe: customers cannot be read')->count(), 'staff are told, once an hour');
    }

    #[DataProvider('noAnswerAboutTheCustomer')]
    public function test_a_checkout_keeps_the_stripe_customer_and_is_refused_when_stripe_does_not_answer_about_it(\Closure $read): void
    {
        $this->stripeAnswers($read);

        try {
            ExtensionHelper::pay($this->stripe->fresh(), $this->invoice->fresh());
            $this->fail('the checkout opened while Stripe did not answer about the account\'s customer');
        } catch (DisplayException $e) {
            $this->assertSame(__('The payment provider did not answer. Nothing was charged: please try again in a few minutes.'), $e->getMessage());
        }

        $this->assertSame('cus_OLD', $this->storedCustomer());
        $this->assertSame([], $this->posted, 'nothing was created at Stripe: no customer, no payment intent');
    }

    #[DataProvider('noAnswerAboutTheCustomer')]
    #[DataProvider('refusedAboutTheCustomer')]
    public function test_saving_a_card_keeps_the_stripe_customer_and_is_refused_when_stripe_does_not_answer_about_it(\Closure $read): void
    {
        $this->stripeAnswers($read);

        try {
            ExtensionHelper::createBillingAgreement($this->client->fresh(), $this->stripe->fresh());
            $this->fail('a card was being saved while Stripe did not answer about the account\'s customer');
        } catch (DisplayException) {
            // refused for now
        }

        $this->assertSame('cus_OLD', $this->storedCustomer());
        $this->assertSame([], $this->posted);
    }

    /** The one definite answer: Stripe does not know the customer (another account's key, deleted long ago). */
    public function test_a_customer_stripe_does_not_know_is_replaced(): void
    {
        $this->stripeAnswers(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer: cus_OLD']], 404));

        ExtensionHelper::pay($this->stripe->fresh(), $this->invoice->fresh());

        $this->assertSame('cus_NEW', $this->storedCustomer());
        $this->assertSame(['/v1/customers', '/v1/payment_intents'], $this->posted);
    }

    /** A customer deleted at Stripe is answered with 200 and `deleted`: replaced, as before. */
    public function test_a_customer_deleted_at_stripe_is_replaced(): void
    {
        $this->stripeAnswers(Http::response(['id' => 'cus_OLD', 'deleted' => true]));

        ExtensionHelper::pay($this->stripe->fresh(), $this->invoice->fresh());

        $this->assertSame('cus_NEW', $this->storedCustomer());
    }

    public function test_a_customer_stripe_knows_is_kept_and_used(): void
    {
        $this->stripeAnswers(Http::response(['id' => 'cus_OLD']));

        ExtensionHelper::pay($this->stripe->fresh(), $this->invoice->fresh());

        $this->assertSame('cus_OLD', $this->storedCustomer());
        $this->assertSame(['/v1/payment_intents'], $this->posted);
    }
}

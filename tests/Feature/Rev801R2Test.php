<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Ops\Charges;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #801, second pass (head e5580ca3). Fails on the PR's head for the reason its docblock gives;
 * nothing is fixed here.
 */
class Rev801R2Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Gateway $stripe;

    private BillingAgreement $card;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        MailFacade::fake();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->client = User::factory()->create()->client();
        $this->stripe = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_1']);
        Http::fake(fn ($request) => str_contains($request->url(), '/customers/') ? Http::response(['id' => 'cus_1'])
            : throw new ConnectionException('cURL error 28: Operation timed out'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * REV-801-R2-1 (money; unchanged from main, the first of the two releases the author left open). A charge of unknown
     * outcome is read as settled by ANY payment of at least its amount through the same gateway recorded after it was
     * sent, also one that is plainly another payment (another gateway transaction id). A checkout the customer had open
     * before the charge is such a payment: nothing stops it at the gateway once its form is on the screen.
     *
     * Scenario: an invoice of 10.00. 09:00 the customer opens Stripe's payment form. 09:05, in another tab, «pay with the
     * saved card»: a timeout, unknown (Stripe did take it). The invoice is held and a second checkout is refused, but the
     * form of 09:00 is still open: 09:10 the customer pays there, the webhook records pi_checkout and the invoice is paid.
     * From then on the unknown charge is in no list: not in unresolved(), not in «needs staff», no alert in the ops tool,
     * not in the «Automatic charges not settled» digest. When its own webhook does arrive, the second 10.00 becomes account
     * credit; when it does not (the reason «unknown» exists), the customer paid twice and nothing here says so.
     *
     * Should: a payment known to be another one (its own gateway id, a checkout's intent, not this attempt's) does not
     * settle the charge: it still asks staff to check it at the gateway.
     */
    public function test_rev_801_r2_1_a_checkout_paid_after_an_unknown_charge_settles_it_and_nobody_is_told(): void
    {
        $this->markTestIncomplete('known, unchanged from main (2026-11-11): a different payment of the same gateway after the send settles an unknown charge; matching needs the gateway\'s id on the attempt');
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-09']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1]);
        $invoice = $invoice->fresh();

        $this->assertTrue(SavedMethodCharge::openCheckout($invoice), 'the checkout opens at 09:00');
        Carbon::setTestNow('2026-11-11 09:05:00');
        $attempt = (new SavedMethodCharge)->charge($invoice->fresh(), $this->card, ChargeAttempt::SOURCE_CLIENT)->fresh();
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->status);
        $this->assertFalse(SavedMethodCharge::openCheckout($invoice->fresh()), 'precondition: a NEW checkout is refused while it is unknown');

        // The checkout of 09:00 is paid at Stripe; its webhook records it as any checkout's payment.
        Carbon::setTestNow('2026-11-11 09:10:00');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_checkout');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        Carbon::setTestNow('2026-11-11 11:00:00');
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->fresh()->status);
        $this->assertNotNull(Charges::attentionOf($attempt->fresh()), 'the charge of unknown outcome asks for nobody\'s attention: the checkout\'s payment (pi_checkout) is read as its payment');
        $this->assertTrue(ChargeAttempt::query()->needsStaff()->whereKey($attempt->id)->exists());
    }
}

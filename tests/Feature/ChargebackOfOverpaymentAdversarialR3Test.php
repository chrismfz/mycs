<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Round-3 adversarial review of #553 (head 85984ade): the won-early stop of automatic charging
 * (chargebackReversedAtGateway with no chargeback recorded) and its mail note. test_bug_* fail on the PR's head and
 * name a hole; test_holds_* are guarantees attacked that held.
 */
class ChargebackOfOverpaymentAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private const STAFF = 'Chargeback needs staff attention';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['auto_charge' => true]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    private function client(?User $user = null): Client
    {
        return ($user ?? $this->user)->client()->fresh();
    }

    private function overpaid(string $gateway = 'Stripe', string $reference = 'pi_over', ?User $user = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client($user)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, $gateway, 130.00, transactionId: $reference, currency: 'usd');

        return $invoice->fresh();
    }

    private function credit(?User $user = null): float
    {
        return (float) (Credit::query()->where('client_id', $this->client($user)->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function autoCharge(?User $user = null): bool
    {
        return (bool) $this->client($user)->auto_charge;
    }

    private function staffBodies(): string
    {
        return EmailLog::query()->where('subject', self::STAFF)->pluck('body')->implode("\n");
    }

    private function service(Client $client, string $status, ?string $subscription, array $markers = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => $subscription]);
        foreach ($markers as $marker) {
            $service->properties()->create(['key' => $marker, 'name' => $marker, 'value' => '1']);
        }

        return $service;
    }

    private function assertMoneyCheckClean(string $step = ''): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, $step . ' ' . json_encode($findings));
    }

    // ---- ADV-553-R3-1: a PayPal dispute closed in our favour with no money ever taken back (an inquiry withdrawn by the
    // buyer, or a claim won while PayPal only held the funds) is the ordinary PayPal path, not an out-of-order chargeback:
    // PAYMENT.CAPTURE.REVERSED is sent only when money is taken back. The row-null branch stops automatic charging on
    // it all the same, so a withdrawn dispute ends harsher than one still open (chargebackOpened stops nothing), and
    // harsher than docs/payments.md's rule («κάθε chargeback πληρωμής»).

    public function test_bug_paypal_dispute_withdrawn_without_any_chargeback_stops_auto_charge(): void
    {
        $invoice = $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackOpened('PayPal', 'PP-PAY-1', 'PP-D-1', 130.00, 'usd');
        $this->assertTrue($this->autoCharge(), 'a dispute still open does not stop it');

        // CUSTOMER.DISPUTE.RESOLVED, CANCELED_BY_BUYER: no reversal before or after.
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1');

        $this->assertSame([30.0, Invoice::STATUS_PAID], [$this->credit(), $invoice->fresh()->status]);
        $this->assertMoneyCheckClean();
        $this->assertTrue($this->autoCharge(),
            'no money was ever taken back: not a chargeback, and the buyer withdrawing must not end harsher than the dispute still open');
    }

    public function test_holds_paypal_won_early_then_real_reversal_still_ends_with_auto_charge_off(): void
    {
        // The fix for R3-1 must keep ADV-553-R2-1: when the reversal does come after the win, the account ends off.
        $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackOpened('PayPal', 'PP-PAY-1', 'PP-D-1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1');
        ExtensionHelper::chargebackAtGateway('PayPal', 'PP-PAY-1', 'PP-REV-1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge());
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    // ---- ADV-553-R3-2: the won-early row is kept outside the transaction that stops automatic charging. If the stop
    // throws (deadlock, lost connection), the webhook fails and the gateway retries, but the retry is a replay: the stop
    // and the staff mail never happen.

    public function test_bug_won_early_stop_that_fails_once_is_never_retried(): void
    {
        $this->overpaid();
        $fail = true;
        Client::updating(function () use (&$fail) {
            if ($fail) {
                $fail = false;

                // Not a «deadlock» message: inside the test's own transaction Laravel would not roll a nested deadlock back
                // (it leaves that to the outer retry), which a webhook's top-level transaction does.
                throw new RuntimeException('Lost the write to clients');
            }
        });

        try {
            ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
            $this->fail('the stop was expected to throw once');
        } catch (RuntimeException) {
            // the webhook answers 500: Stripe retries it
        }
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'); // the retry

        $this->assertFalse($this->autoCharge(), 'the retry is taken for a replay: automatic charging stays on');
        $this->assertStringContainsString('dp_1', $this->staffBodies(), 'and staff are never told of the dispute');
    }

    // ---- the stop: only the payment's own account, only payments of ours, once

    public function test_holds_stripe_win_told_first_stops_it_funds_reinstated_means_funds_were_withdrawn(): void
    {
        $this->overpaid();

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge());
        $this->assertStringContainsString('Automatic charging of the account is turned off', $this->staffBodies());
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_ipn_canceled_reversal_told_first_stops_it(): void
    {
        $this->overpaid('PayPal', 'PP-IPN-1');

        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-IPN-1', null, 130.00, 'usd', 'cr:IPN-R-1');

        $this->assertFalse($this->autoCharge(), 'a Canceled_Reversal implies its Reversed: money did move');
    }

    public function test_holds_win_of_an_unknown_or_foreign_payment_stops_nothing_and_writes_nothing(): void
    {
        $this->overpaid();

        $this->assertFalse(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_someone_else', 'dp_x', 130.00, 'usd'));
        $this->assertFalse(ExtensionHelper::chargebackReversedAtGateway('PayPal', 'pi_over', null, 130.00, 'usd', 'PP-D-X')); // other gateway's id
        $this->assertFalse(ExtensionHelper::chargebackReversedAtGateway('Stripe', '', 'dp_y', 130.00, 'usd'));

        $this->assertTrue($this->autoCharge());
        $this->assertSame(0, GatewayRefund::query()->count());
        $this->assertSame('', $this->staffBodies());
    }

    public function test_holds_win_stops_only_the_payments_own_account_and_names_only_its_subscriptions(): void
    {
        $other = User::factory()->create();
        $other->client()->update(['auto_charge' => true]);
        $mine = $this->service($this->client(), 'active', 'sub_mine');
        $theirs = $this->service($this->client($other), 'active', 'sub_theirs', ['has_paypal_subscription']);
        $this->overpaid();
        $this->overpaid('Stripe', 'pi_other', $other);

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertFalse($this->autoCharge());
        $this->assertTrue($this->autoCharge($other));
        $this->assertMatchesRegularExpression('/service #' . $mine->id . '\b/', $this->staffBodies());
        $this->assertDoesNotMatchRegularExpression('/#' . $theirs->id . '\b/', $this->staffBodies());
    }

    public function test_holds_replayed_win_after_staff_turned_it_on_again_does_not_stop_it_again(): void
    {
        $this->overpaid();
        $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'cr:IPN-R-1');
        $this->client()->update(['auto_charge' => true]); // staff settled it

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'cr:IPN-R-1');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'); // the late funds_withdrawn

        $this->assertTrue($this->autoCharge());
        $this->assertSame(2, EmailLog::query()->where('subject', self::STAFF)->count(), 'one mail per dispute');
        $this->assertSame(60.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_guest_invoice_win_stops_nothing_and_still_tells_staff(): void
    {
        $invoice = $this->overpaid();
        Invoice::query()->whereKey($invoice->id)->toBase()->update(['client_id' => null]); // a guest invoice (no account)

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertTrue($this->autoCharge());
        $this->assertStringContainsString('dp_1', $this->staffBodies());
        $this->assertStringNotContainsString('turned off', $this->staffBodies());
    }
}

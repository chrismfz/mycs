<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PR H2b, sweep Π7.9 (decision (a) + safety net): the legacy PayPal subscriptions move to the vault; an IPN that still
 * arrives at WHMCS's old address is checked with PayPal, kept in the gateway log and told to staff once, never recorded.
 */
class LegacyPayPalIpnTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/modules/gateways/callback/paypal.php';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.paypal_legacy_receivers' => 'billing@example.test, MERCHANT123']);
    }

    /** @param  array<string, string>  $fields */
    private function ipn(array $fields): TestResponse
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** @return array<string, string> */
    private function subscrPayment(): array
    {
        return ['txn_type' => 'subscr_payment', 'payment_status' => 'Completed', 'txn_id' => '8AB12345CD678901E', 'subscr_id' => 'I-ABCDEF123456',
            'mc_gross' => '12.40 ', 'mc_currency' => 'EUR', 'item_number' => '30001', 'receiver_email' => 'billing@example.test'];
    }

    private function staffMails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    public function test_a_verified_subscription_payment_is_told_to_staff_once_and_not_recorded(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn($this->subscrPayment())->assertOk();
        $this->ipn($this->subscrPayment())->assertOk(); // PayPal sends it again

        Http::assertSent(fn (Request $request) => str_starts_with($request->body(), 'cmd=_notify-validate&txn_type=subscr_payment')
            && str_contains($request->body(), 'mc_gross=12.40+'));
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, $this->staffMails('PayPal payment not recorded'));
        $event = PaymentGatewayEvent::query()->first();
        $this->assertSame(['paypal_legacy', 'ipn', 'legacy_subscr_payment', '8AB12345CD678901E', true],
            [$event->gateway, $event->endpoint, $event->reason, $event->external_id, (bool) $event->signature_verified]);
    }

    public function test_a_cancellation_is_a_notice_without_money(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['txn_type' => 'subscr_cancel', 'subscr_id' => 'I-ABCDEF123456', 'receiver_id' => 'merchant123'])->assertOk();

        $this->assertSame([0, 1], [$this->staffMails('PayPal payment not recorded'), $this->staffMails('PayPal legacy notice')]);
    }

    public function test_a_forged_ipn_is_refused_quietly(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('INVALID')]);

        $this->ipn($this->subscrPayment())->assertStatus(400);

        $this->assertSame(0, EmailLog::query()->count());
        $this->assertSame('not_verified', PaymentGatewayEvent::query()->sole()->reason);
    }

    public function test_paypal_down_is_answered_503_so_it_is_sent_again(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('', 500)]);

        $this->ipn($this->subscrPayment())->assertStatus(503);

        $this->assertSame(0, $this->staffMails('PayPal payment not recorded'));
    }

    public function test_a_sandbox_ipn_is_checked_with_the_sandbox(): void
    {
        Http::fake(['https://ipnpb.sandbox.paypal.com/*' => Http::response('VERIFIED'), 'https://ipnpb.paypal.com/*' => Http::response('INVALID')]);

        $this->ipn(['test_ipn' => '1'] + $this->subscrPayment())->assertOk();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://ipnpb.sandbox.paypal.com/'));
    }

    public function test_a_genuine_ipn_for_someone_elses_paypal_account_is_not_ours(): void
    {
        // Code review CR-1: anyone can point an IPN of their own PayPal account at this address.
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['receiver_email' => 'attacker@example.test'] + $this->subscrPayment())->assertOk();

        $this->assertSame(0, EmailLog::query()->count());
        $this->assertSame('foreign_receiver', PaymentGatewayEvent::query()->sole()->reason);
    }

    public function test_the_sandbox_is_refused_in_production(): void
    {
        // CR-1: PayPal's IPN simulator answers VERIFIED to anyone.
        Http::fake(['*' => Http::response('VERIFIED')]);
        $this->app['env'] = 'production';

        $this->ipn(['test_ipn' => '1'] + $this->subscrPayment())->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_no_account_set_tells_staff_once_to_set_it(): void
    {
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        config(['settings.paypal_legacy_receivers' => '']);

        $this->ipn($this->subscrPayment())->assertOk();
        $this->ipn($this->subscrPayment())->assertOk();

        $this->assertSame(1, $this->staffMails('PayPal legacy notice not checked'));
        $this->assertSame(0, $this->staffMails('PayPal payment not recorded'));
    }

    public function test_a_refund_or_chargeback_is_told_as_money_given_back(): void
    {
        // CR-5.
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        $this->ipn(['payment_status' => 'Reversed', 'txn_type' => '', 'mc_gross' => '-12.40'] + $this->subscrPayment())->assertOk();

        $this->assertSame(1, $this->staffMails('PayPal refund or reversal not recorded'));
    }

    public function test_a_body_too_large_is_not_sent_to_paypal(): void
    {
        Http::fake();

        $this->ipn(['padding' => str_repeat('x', 30000)] + $this->subscrPayment())->assertStatus(413);

        Http::assertNothingSent();
    }
}

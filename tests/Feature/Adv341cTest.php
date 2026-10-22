<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use Tests\TestCase;

/**
 * Adversarial re-review of PR #341 (a2b153ca). PayPal answers VERIFIED only to the message exactly as it sent it
 * (same fields, same values, same order). PayPal_IPN::notify sends back $request->all(), which the global TrimStrings
 * and ConvertEmptyStringsToNull middleware changed: an empty field is dropped (null is left out of the form) and a
 * padded value is trimmed, so PayPal answers INVALID to a genuine IPN. Since a2b153ca that genuine IPN is a 400 counted
 * toward the callbacks' limit, logged signature_verified=false (no staff alert), and the payment is never recorded.
 * LegacyPayPalIpnController already posts the raw body for this reason.
 */
class Adv341cTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
    }

    /** PayPal's validation: VERIFIED only when the message comes back as it was sent. */
    private function paypalChecksTheMessageAsSent(array $sent): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal_IPN'], ['name' => 'PayPal_IPN', 'type' => 'gateway']);
        foreach (['email' => 'biz@example.test', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([self::VERIFY => function (ClientRequest $request) use ($sent) {
            parse_str($request->body(), $back);

            return Http::response($back === ['cmd' => '_notify-validate'] + $sent ? 'VERIFIED' : 'INVALID');
        }]);
        (new PayPal_IPN(['email' => 'biz@example.test', 'test_mode' => '1']))->boot();
    }

    /** As PayPal sends it: a form body, its values as they are. */
    private function ipn(array $fields)
    {
        return $this->call('POST', '/extensions/paypal_ipn/notify', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    private function invoice(): Invoice
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);

        return $invoice->fresh();
    }

    /** @return array<string, string> */
    private function genuine(Invoice $invoice, array $extra): array
    {
        return [
            'txn_type' => 'web_accept', 'receiver_email' => 'biz@example.test', 'payment_status' => 'Completed',
            'item_number' => (string) $invoice->id, 'mc_gross' => '100.00', 'mc_fee' => '3.20', 'mc_currency' => 'USD',
            'txn_id' => 'IPN-341C', 'payer_email' => 'buyer@example.test',
        ] + $extra;
    }

    public function test_a_genuine_ipn_with_an_empty_field_is_recorded(): void
    {
        $invoice = $this->invoice();
        $fields = $this->genuine($invoice, ['memo' => '', 'option_name1' => '']);
        $this->paypalChecksTheMessageAsSent($fields);

        $this->ipn($fields)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue((bool) PaymentGatewayEvent::query()->sole()->signature_verified);
    }

    public function test_a_genuine_ipn_with_a_padded_value_is_recorded(): void
    {
        $invoice = $this->invoice();
        $fields = $this->genuine($invoice, ['address_street' => 'Odos 1 ', 'item_name' => 'Invoice #1 ']);
        $this->paypalChecksTheMessageAsSent($fields);

        $this->ipn($fields)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** Control: the same IPN without empty or padded values is recorded (the fake is fair). */
    public function test_control_a_plain_genuine_ipn_is_recorded(): void
    {
        $invoice = $this->invoice();
        $fields = $this->genuine($invoice, ['address_street' => 'Odos 1']);
        $this->paypalChecksTheMessageAsSent($fields);

        $this->ipn($fields)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}

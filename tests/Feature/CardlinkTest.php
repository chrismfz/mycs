<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use MyCS\Extensions\Gateways\Cardlink\CardlinkOrder;
use Nixpal\CardlinkEurobank\Digest;
use Nixpal\CardlinkEurobank\PaymentRequest;
use Nixpal\CardlinkEurobank\Tests\Fixtures;
use Tests\TestCase;

/**
 * PR H2a: the Cardlink/Eurobank vPOS (cards, Apple/Google Pay, IRIS). pay() keeps an order and posts the signed form; the
 * vPOS's signed return (twice: browser and server) records the payment once, on the order's invoice or Mass Pay group.
 */
class CardlinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->firstOrCreate(['extension' => 'Cardlink'], ['name' => 'Cardlink', 'type' => 'gateway']);
        foreach ($this->config() as $key => $value) {
            $this->gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->cardlink()->boot();
        app('router')->getRoutes()->refreshNameLookups(); // as after the extensions boot
    }

    /** @return array<string, string> */
    private function config(): array
    {
        return ['merchant_id' => Fixtures::MID, 'shared_secret' => Fixtures::SECRET, 'test_mode' => '1'];
    }

    private function cardlink(): Cardlink
    {
        return new Cardlink($this->config());
    }

    private function invoice(float $price = 25, string $currency = 'EUR'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /** @return array<string, string> the form pay() posts to the vPOS */
    private function form(Invoice $invoice, ?float $total = null): array
    {
        $view = $this->cardlink()->pay($invoice, $total ?? $invoice->remaining);
        $this->assertInstanceOf(View::class, $view);
        $this->assertSame(PaymentRequest::ENDPOINT_TEST, $view->getData()['endpoint']);

        return $view->getData()['fields'];
    }

    /** @param  array<string, string|null>  $override */
    private function capture(string $orderId, string $amount, array $override = [], string $leg = 'browser'): array
    {
        return Fixtures::capture(['orderid' => $orderId, 'orderAmount' => $amount, 'paymentTotal' => $amount] + $override, $leg);
    }

    private function back(array $posted, ?string $orderId = null): TestResponse
    {
        return $this->post('/extensions/cardlink/return' . ($orderId === null ? '' : '?o=' . $orderId), $posted);
    }

    private function mails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    public function test_pay_keeps_an_order_and_signs_the_form_for_the_vpos(): void
    {
        $invoice = $this->invoice(25);
        $fields = $this->form($invoice);

        $order = CardlinkOrder::query()->sole();
        $this->assertSame([$invoice->id, $this->user->client()->id, '25.00', 'EUR', null], [(int) $order->invoice_id, (int) $order->client_id, (string) $order->amount, $order->currency_code, $order->status]);
        $this->assertMatchesRegularExpression('/^' . $invoice->id . 'Inv\d{14}$/', $order->order_id);
        $this->assertSame([Fixtures::MID, $order->order_id, '25.00', 'EUR'], [$fields['mid'], $fields['orderid'], $fields['orderAmount'], $fields['currency']]);
        $url = route('extensions.gateways.cardlink.return', ['o' => $order->order_id]);
        $this->assertSame([$url, $url], [$fields['confirmUrl'], $fields['cancelUrl']]);
        $this->assertSame('digest', array_key_last($fields));
        $this->assertSame(Digest::request($fields, Fixtures::SECRET), $fields['digest']);

        $this->form($invoice);
        $this->assertSame(2, CardlinkOrder::query()->distinct()->count('order_id'), 'each pay is a new order id');
    }

    public function test_only_euros(): void
    {
        $this->assertTrue($this->cardlink()->canUseGateway(10, 'eur', 'invoice'));
        $this->assertFalse($this->cardlink()->canUseGateway(10, 'USD', 'invoice'));
    }

    public function test_both_legs_of_a_capture_record_the_payment_once(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '25.00'), $orderId)->assertOk()->assertSee(route('invoices.show', $invoice) . '?checkPayment', false);
        $this->back($this->capture($orderId, '25.00', [], 'modirum'), $orderId)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $payment = InvoiceTransaction::query()->sole();
        $this->assertSame([$orderId, '25.00', (int) $this->gateway->id], [$payment->transaction_id, (string) $payment->amount, (int) $payment->gateway_id]);
        $order = CardlinkOrder::query()->sole();
        $this->assertSame(['CAPTURED', '900000001', 'visa'], [$order->status, $order->tx_id, $order->pay_method]);
        $this->assertSame([PaymentGatewayEvent::OUTCOME_RECORDED, PaymentGatewayEvent::OUTCOME_IGNORED],
            PaymentGatewayEvent::query()->orderBy('id')->pluck('outcome')->all(), 'the second leg is a replay');
        $this->assertTrue((bool) PaymentGatewayEvent::query()->first()->signature_verified);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_an_iris_payment_is_recorded_as_a_card_one(): void
    {
        $invoice = $this->invoice(12.5);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '12.50', ['payMethod' => 'IRIS']))->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('IRIS', CardlinkOrder::query()->sole()->pay_method);
    }

    public function test_a_forged_or_foreign_return_records_nothing(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $forged = $this->capture($orderId, '25.00');
        $forged['digest'] = base64_encode(str_repeat('x', 32));
        $this->back($forged)->assertStatus(400);
        // Signed with the secret, but for another terminal.
        $this->back($this->capture($orderId, '25.00', ['mid' => 'OTHER']))->assertStatus(400);
        // A field the vPOS does not send: refused, not hashed.
        $this->back(['extra' => '1'] + $this->capture($orderId, '25.00'))->assertStatus(400);
        // Signed with another secret.
        $this->back(Fixtures::capture(['orderid' => $orderId, 'orderAmount' => '25.00'], 'browser', 'other-secret'))->assertStatus(400);

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertNull(CardlinkOrder::query()->sole()->status);
        $this->assertSame(4, PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_REJECTED)->where('signature_verified', false)->count());
    }

    public function test_a_capture_for_an_order_that_is_not_ours_tells_staff(): void
    {
        $this->invoice(25);

        $this->back($this->capture('999Inv26101500000001', '25.00'))->assertOk();
        $this->back($this->capture('999Inv26101500000001', '25.00', [], 'modirum'))->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, $this->mails('Cardlink payment not recorded'));
        $this->assertSame('unknown_order', PaymentGatewayEvent::query()->first()->reason);
    }

    public function test_a_refused_payment_records_nothing_and_a_capture_after_it_pays(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '25.00', ['status' => 'REFUSED', 'payMethod' => null, 'message' => 'Refused']))
            ->assertOk()->assertSee(__('invoices.cardlink_not_paid'));
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('REFUSED', CardlinkOrder::query()->sole()->status);
        $this->assertSame('status_refused', PaymentGatewayEvent::query()->sole()->reason);

        $this->back($this->capture($orderId, '25.00'))->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // A late refused leg does not undo the capture.
        $this->back($this->capture($orderId, '25.00', ['status' => 'REFUSED', 'payMethod' => null]))->assertOk();
        $this->assertSame('CAPTURED', CardlinkOrder::query()->sole()->status);
        $this->assertSame(1, InvoiceTransaction::query()->count());
    }

    public function test_a_cancel_without_a_result_goes_back_to_the_invoice(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->get('/extensions/cardlink/return?o=' . $orderId)->assertOk()->assertSee(route('invoices.show', $invoice), false);
        $this->get('/extensions/cardlink/return')->assertOk()->assertSee(route('invoices'), false);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_an_authorisation_is_not_money_and_staff_are_told(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '25.00', ['status' => 'AUTHORIZED']))->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, $this->mails('Cardlink payment authorised, not captured'));
    }

    public function test_another_amount_captured_is_recorded_and_staff_are_told(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '20.00'))->assertOk();

        $this->assertSame('20.00', (string) InvoiceTransaction::query()->sole()->amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(1, $this->mails('Cardlink payment of another amount'));
    }

    public function test_another_currency_is_not_recorded(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '25.00', ['currency' => 'USD']))->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('currency_mismatch', PaymentGatewayEvent::query()->sole()->reason);
    }

    public function test_the_numeric_euro_code_is_euros(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->form($invoice)['orderid'];

        $this->back($this->capture($orderId, '25.00', ['currency' => '978']))->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_a_mass_pay_group_is_paid_by_one_return(): void
    {
        $a = $this->invoice(10);
        $b = $this->invoice(15);
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        $view = ExtensionHelper::payGroup($this->gateway, $group);
        $fields = $view->getData()['fields'];
        $order = CardlinkOrder::query()->sole();
        $this->assertSame([$group->ulid, '25.00', (int) $group->invoice_id], [$order->payment_group, (string) $order->amount, (int) $order->invoice_id]);
        $this->assertStringStartsWith('Invoices ', $fields['orderDesc']);

        $this->back($this->capture($order->order_id, '25.00'))->assertOk();

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_pay_without_a_secret_is_not_offered_to_the_vpos(): void
    {
        $invoice = $this->invoice(25);

        $this->expectException(\App\Exceptions\DisplayException::class);
        (new Cardlink(['merchant_id' => Fixtures::MID, 'shared_secret' => '']))->pay($invoice, 25);
    }

    public function test_the_customer_is_sent_to_the_vpos_from_the_invoice(): void
    {
        $invoice = $this->invoice(25);

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertSee('id="cardlink-form"', false)
            ->assertSee('action="' . PaymentRequest::ENDPOINT_TEST . '"', false)
            ->assertSee(CardlinkOrder::query()->sole()->order_id);
    }
}

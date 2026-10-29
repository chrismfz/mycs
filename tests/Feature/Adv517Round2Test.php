<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use Tests\TestCase;

/**
 * Adversarial review of #517, round 2. The guard «Mollie lists more than MyCS recorded» compares Mollie's refunds with the
 * transaction's refunded_amount, which also counts money given back outside Mollie (a refund recorded by hand, a bank
 * transfer) and chargebacks. Either one hides a lost Mollie refund: the retry gets a new key and Mollie pays again.
 */
class Adv517Round2Test extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.mollie.com/v2/payments/tr_1';

    private User $user;

    private ?int $invoiceId = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        $this->user = User::factory()->create();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Mollie'], ['name' => 'Mollie', 'type' => 'gateway']);
        $gateway->settings()->updateOrCreate(['key' => 'api_key'], ['value' => 'test_key']);
        (new Mollie(['api_key' => 'test_key']))->boot();
    }

    private function payment(): InvoiceTransaction
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->id, 'Mollie', 100, transactionId: 'tr_1', currency: 'USD');
        $this->invoiceId = $invoice->id;

        return $invoice->fresh()->transactions()->sole();
    }

    /** Mollie: the first refund POST times out after Mollie made it; every refund POST made is listed afterwards. */
    private function fakeMollie(array &$posts): void
    {
        $refunds = [];
        Http::fake([
            self::API . '/refunds' => function (Request $request) use (&$posts, &$refunds) {
                if ($request->method() === 'GET') {
                    return Http::response(['_embedded' => ['refunds' => $refunds]]);
                }
                $posts[] = $request->header('Idempotency-Key')[0] ?? null;
                $id = 're_' . count($posts);
                $refunds[] = ['id' => $id, 'status' => 'pending', 'amount' => $request->data()['amount']];
                if (count($posts) === 1) {
                    throw new ConnectionException('timed out'); // made at Mollie, the answer lost
                }

                return Http::response(['id' => $id, 'status' => 'pending', 'amount' => $request->data()['amount']]);
            },
        ]);
    }

    private function lostThenRetried(InvoiceTransaction $payment, array &$posts): void
    {
        try {
            ExtensionHelper::refund($payment, 30.00);
            $this->fail('the first refund call times out');
        } catch (ConnectionException) {
        }
        // Mollie lists re_1 (30.00), its webhook has not come. Staff retry the same refund.
        try {
            ExtensionHelper::refund(InvoiceTransaction::find($payment->id), 30.00);
        } catch (\Throwable) {
        }
    }

    /** ADV-517-R2-1: 30.00 given back by bank transfer and recorded by hand hides the lost 30.00 Mollie refund. */
    public function test_a_refund_recorded_by_hand_does_not_hide_a_lost_mollie_refund(): void
    {
        $posts = [];
        $this->fakeMollie($posts);
        $payment = $this->payment();
        ExtensionHelper::refundLocally($payment, 30.00, notify: false); // a bank transfer, outside Mollie

        $this->lostThenRetried(InvoiceTransaction::find($payment->id), $posts);

        $this->assertCount(1, $posts, 'the retry sent a second refund to Mollie with a new key ('
            . implode(', ', $posts) . '): the customer was paid 30.00 twice at Mollie, plus the bank transfer');
    }

    /** ADV-517-R2-2: a chargeback recorded on the payment counts as refunded too, and hides the lost refund the same way. */
    public function test_a_chargeback_does_not_hide_a_lost_mollie_refund(): void
    {
        $posts = [];
        $this->fakeMollie($posts);
        $payment = $this->payment();
        ExtensionHelper::chargebackAtGateway('Mollie', 'tr_1', 'chb_1', 30.00, 'USD');
        $this->assertSame(3000, (int) round(InvoiceTransaction::find($payment->id)->refunded_amount * 100));

        $this->lostThenRetried(InvoiceTransaction::find($payment->id), $posts);

        $this->assertCount(1, $posts, 'the retry sent a second refund to Mollie with a new key (' . implode(', ', $posts) . ')');
    }

    /** Control: with nothing else on the payment the retry is refused (the PR's own guard). */
    public function test_control_without_other_refunds_the_retry_is_refused(): void
    {
        $posts = [];
        $this->fakeMollie($posts);
        $this->lostThenRetried($this->payment(), $posts);

        $this->assertCount(1, $posts);
    }
}

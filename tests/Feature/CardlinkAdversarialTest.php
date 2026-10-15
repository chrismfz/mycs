<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use MyCS\Extensions\Gateways\Cardlink\CardlinkOrder;
use Nixpal\CardlinkEurobank\Tests\Fixtures;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of PR H2a (Cardlink/Eurobank). Each test documents one finding (ADV-n) or guards behaviour that
 * held up under attack (GUARD).
 */
class CardlinkAdversarialTest extends TestCase
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
        app('router')->getRoutes()->refreshNameLookups();
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

    private function invoice(float $price = 25): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function order(Invoice $invoice): string
    {
        return $this->cardlink()->pay($invoice, $invoice->remaining)->getData()['fields']['orderid'];
    }

    /** @param  array<string, string|null>  $override */
    private function capture(string $orderId, string $amount, array $override = [], string $leg = 'browser'): array
    {
        return Fixtures::capture(['orderid' => $orderId, 'orderAmount' => $amount, 'paymentTotal' => $amount] + $override, $leg);
    }

    private function back(array $posted): TestResponse
    {
        return $this->post('/extensions/cardlink/return', $posted);
    }

    private function mails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    private function creditCents(): int
    {
        return RecordPaymentService::toCents(Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'));
    }

    /**
     * ADV-1 (High): the browser leg of a capture passes through the customer's browser, so the customer holds a signed
     * CAPTURED body forever. record() runs on every CAPTURED return, even for an order that is already CAPTURED; the only
     * replay guard is the existing InvoiceTransaction. Once staff delete that payment (recorded by mistake, a chargeback,
     * a refund made in the portal and undone by deleting), replaying the old body records the money again.
     */
    public function test_adv1_a_saved_capture_replayed_after_staff_delete_the_payment_records_nothing(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);
        $saved = $this->capture($orderId, '25.00');
        $this->back($saved)->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Staff delete the payment (e.g. the bank reversed it).
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        // Days later the customer re-posts the body their browser carried.
        $this->back($saved);

        $this->assertSame(0, InvoiceTransaction::query()->count(), 'an order already captured and recorded is not money again');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * ADV-2 (Medium): cardlink_orders cascades on invoice (and client) deletion. An invoice deleted while the customer
     * is on the bank page loses the order: the capture is reported to staff as «not one of ours», without the
     * invoice or the customer, and nothing records whose money it was.
     */
    public function test_adv2_an_invoice_deleted_during_payment_keeps_its_order(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);

        $invoice->items()->delete();
        $invoice->delete();
        $this->back($this->capture($orderId, '25.00'));

        $this->assertNotNull(CardlinkOrder::query()->where('order_id', $orderId)->first(), 'the order (whose money) survives the invoice');
        $message = (string) EmailLog::query()->where('subject', 'Cardlink payment not recorded')->value('body');
        $this->assertStringNotContainsString('not one of ours', $message, 'staff are not told the order is foreign');
    }

    /**
     * ADV-3 (Medium): the order is marked CAPTURED before record() runs, outside its transaction. If addPayment throws
     * (lock wait timeout or deadlock between the two concurrent legs, a DB error), the request ends in a 500, the
     * order says CAPTURED, no payment exists and staff are not told. If the other leg fails too (or came first and was
     * refused), the money is lost silently.
     */
    public function test_adv3_a_capture_that_cannot_be_recorded_tells_staff(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);
        InvoiceTransaction::creating(function () {
            throw new RuntimeException('Deadlock found when trying to get lock');
        });

        $this->back($this->capture($orderId, '25.00'));
        $this->back($this->capture($orderId, '25.00', [], 'modirum'));

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, $this->mails('Cardlink payment not recorded'), 'captured money that was not recorded reaches staff');
    }

    /** GUARD: an invoice paid another way while the customer is on the bank page: the capture becomes credit. */
    public function test_guard_invoice_paid_meanwhile_becomes_credit(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);
        (new RecordPaymentService)->recordByStaff($invoice, null, 25, 'BANK-1');

        $this->back($this->capture($orderId, '25.00'))->assertOk();
        $this->back($this->capture($orderId, '25.00', [], 'modirum'))->assertOk();

        $this->assertSame(2, InvoiceTransaction::query()->count());
        $this->assertSame(2500, $this->creditCents());
    }

    /** GUARD: an invoice cancelled while the customer is on the bank page: the money is kept as credit, once. */
    public function test_guard_invoice_cancelled_meanwhile_becomes_credit_once(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);
        $invoice->forceFill(['status' => Invoice::STATUS_CANCELLED])->save();

        $this->back($this->capture($orderId, '25.00'))->assertOk();
        $this->back($this->capture($orderId, '25.00', [], 'modirum'))->assertOk();

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame(2500, $this->creditCents());
    }

    /** GUARD: a Mass Pay group whose lead invoice was paid meanwhile still pays the other invoice; the rest is credit. */
    public function test_guard_mass_pay_with_lead_paid_meanwhile(): void
    {
        $a = $this->invoice(10);
        $b = $this->invoice(15);
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);
        ExtensionHelper::payGroup($this->gateway, $group);
        $order = CardlinkOrder::query()->sole();
        $lead = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->recordByStaff($lead, null, $lead->remaining, 'BANK-2');

        $this->back($this->capture($order->order_id, '25.00'))->assertOk();
        $this->back($this->capture($order->order_id, '25.00', [], 'modirum'))->assertOk();

        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame(RecordPaymentService::toCents($lead->total), $this->creditCents());
    }

    /** GUARD: a return re-aimed at another order of ours (a REFUSED body of order B posted with order A's id) is refused. */
    public function test_guard_a_signed_return_cannot_be_moved_to_another_order(): void
    {
        $a = $this->invoice(25);
        $b = $this->invoice(25);
        $orderA = $this->order($a);
        $orderB = $this->order($b);
        $captured = $this->capture($orderB, '25.00');
        $captured['orderid'] = $orderA;

        $this->back($captured)->assertStatus(400);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    /** GUARD: odd amount formats are never guessed: a comma decimal records nothing and staff are told. */
    public function test_guard_odd_amounts_are_not_guessed(): void
    {
        $invoice = $this->invoice(25);
        $orderId = $this->order($invoice);

        $this->back($this->capture($orderId, '25,00'))->assertOk();
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, $this->mails('Cardlink payment not recorded'));

        $this->back($this->capture($orderId, '25'))->assertOk();
        $this->assertSame('25.00', (string) InvoiceTransaction::query()->sole()->amount);
    }
}

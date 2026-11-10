<?php

namespace Tests\Feature;

use App\Admin\Actions\EditInvoiceTransaction;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Independent and adversarial review of PR #782 (Admin API: payments, refunds, adjustment notes, discount lines,
 * draft). Each test states what the API must do «as the panel, no more» and fails on the PR's head. Invented rows only:
 * #480 paid in WHMCS, #502 unpaid (service 31), brought by the importer's own steps.
 */
class StaffFreedomApiReviewTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private const LINES = ['admin.invoice_items.view', 'admin.invoice_items.create', 'admin.invoice_items.update', 'admin.invoice_items.delete'];

    private Invoice $invoice;

    private Staff $staff;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->client = User::factory()->create()->client();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->client->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->importWhmcs();
        $this->invoice = $this->imported(502);
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function token(array $permissions): string
    {
        $token = 'REV' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'review', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->client->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** @return array{0: string, 1: int, 2: int} status, what is left and the money it keeps, in cents */
    private function state(int $invoice = 502): array
    {
        $fresh = Invoice::query()->findOrFail($invoice);

        return [$fresh->status, RecordPaymentService::toCents($fresh->remaining), $fresh->moneyKeptCents()];
    }

    private function pay(string $amount = '10.00'): InvoiceTransaction
    {
        return (new RecordPaymentService)->recordByStaff($this->invoice, null, $amount, 'tr_502');
    }

    // ---------------------------------------------------------------- REV-782-1

    /**
     * PUT /payments/{id} with `invoice_id` of a REFUNDED invoice of the same customer: the payment is deleted from its
     * invoice (open again) and «recorded again» on the refunded one, where all of it becomes account credit. The panel's
     * «Edit» offers only the customer's unpaid, draft and paid invoices (EditInvoiceTransaction::invoices; the select
     * refuses any other id), and a refunded invoice is final (InvoiceObserver). EditPayment checks the target's customer,
     * currency, cut-over and Ekdosi, never its status.
     */
    public function test_rev_782_1_a_payment_is_not_moved_onto_a_refunded_invoice(): void
    {
        $refunded = Invoice::query()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => now()->addWeek(), 'currency_code' => 'EUR']);
        $refunded->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1, 'taxed' => false]);
        ExtensionHelper::refundLocally((new RecordPaymentService)->recordByStaff($refunded, null, '10.00', 'tr_other'), 10.00, false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $refunded->fresh()->status);
        $payment = $this->pay();
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state());

        // What the panel's form offers for this payment: never the refunded invoice.
        $offered = (new ReflectionMethod(EditInvoiceTransaction::class, 'invoices'))->invoke(null, $payment);
        $this->assertArrayNotHasKey($refunded->id, $offered, 'the panel does not offer a refunded invoice');

        $response = $this->withToken($this->token(['admin.invoice_transactions.delete', 'admin.invoice_transactions.create']))
            ->putJson(route('api.v1.admin.invoices.payments.update', [502, $payment->id]), ['invoice_id' => $refunded->id]);

        $this->assertSame('0.00', $this->credit(), 'no account credit is made out of a payment by pointing it at a refunded invoice');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(), 'the payment stays on its invoice, which stays paid');
        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', $refunded->id)->where('transaction_id', 'tr_502')->count());
        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------- REV-782-2

    /**
     * «A raise on a paid invoice that makes it owe is staff's word and needs the permission to record payments»
     * (REV-780-2-2, Invoice::owesByStaffsWord). Asked when a line is added or repriced and when a note is written, edited,
     * voided or deleted; never when a line is DELETED (InvoiceItemObserver::deleting). With the discount line the API now
     * takes, a key with the lines' permissions alone ends «paid, owing 10.00» with no word: discount −10, a free line +10
     * (the invoice still balances: no question asked), then the discount is deleted.
     */
    public function test_rev_782_2_deleting_a_discount_line_of_a_paid_invoice_needs_the_permission_to_record_payments(): void
    {
        $this->pay();
        $token = $this->token(self::LINES);
        $line = fn (string $description, string $price) => $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'),
            ['invoice_id' => 502, 'description' => $description, 'price' => $price, 'quantity' => 1, 'taxed' => false])->assertSuccessful();
        $line('Discount', '-10.00');
        $line('Extra', '10.00');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(), 'it balances: nothing asked so far');
        $discount = InvoiceItem::query()->where('invoice_id', 502)->where('price', '<', 0)->sole();

        $response = $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $discount));

        $this->assertNotNull($discount->fresh(), 'the discount stays: removing it makes a paid invoice owe');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state());
        $response->assertStatus(422)->assertJsonPath('message', __('This invoice is paid: a change that makes it owe is «paid by hand» for that part, and needs the permission to record payments.'));
    }

    /** The same deletion by a key that may record payments: allowed, and the invoice says it is paid by staff's word. */
    public function test_rev_782_2_deleting_a_discount_line_of_a_paid_invoice_is_staffs_word_for_what_it_then_owes(): void
    {
        $this->pay();
        $token = $this->token([...self::LINES, 'admin.invoice_transactions.create']);
        foreach (['-10.00', '10.00'] as $price) {
            $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), ['invoice_id' => 502, 'description' => 'Line', 'price' => $price, 'quantity' => 1, 'taxed' => false])->assertSuccessful();
        }
        $discount = InvoiceItem::query()->where('invoice_id', 502)->where('price', '<', 0)->sole();

        $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $discount))->assertNoContent();

        $this->assertSame([Invoice::STATUS_PAID, 1000, 1000], $this->state(), 'paid, owing the line the discount covered');
        $this->assertNotNull(Invoice::query()->findOrFail(502)->paid_by_staff_at, 'paid by staff\'s word for that part: the one flag the money check, the income report and the Ekdosi feed read');
    }

    // ---------------------------------------------------------------- REV-782-3

    /**
     * A client that gets no answer (a timeout) sends its POST again. A payment with a transaction id is recorded once
     * (the service's replay rule); a refund has nothing of the kind: the same partial refund to account credit, sent
     * twice, gives the customer the credit twice (and «by hand» is recorded twice). The request carries a key of the
     * client's own (a real uuid, as `charge_attempts.key`): the retry must be the first refund, not a second one.
     */
    public function test_rev_782_3_a_refund_to_credit_sent_again_after_a_timeout_is_made_once(): void
    {
        $payment = $this->pay();
        $token = $this->token(['admin.invoice_transactions.update']);
        $url = route('api.v1.admin.invoices.payments.refunds.store', [502, $payment->id]);
        $key = (string) Str::uuid();
        $body = ['amount' => '4.00', 'method' => 'credit', 'notify' => false];

        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($url, $body)->assertSuccessful();
        $this->assertSame('4.00', $this->credit());
        $this->withToken($token)->withHeader('Idempotency-Key', $key)->postJson($url, $body);

        $this->assertSame('4.00', $this->credit(), 'the retry gave the customer the credit a second time');
        $this->assertSame('4.00', number_format((float) $payment->fresh()->credited_amount, 2, '.', ''));
    }

    // ---------------------------------------------------------------- REV-782-4

    /** `{refund}` is `[0-9]+` into an `int` parameter: 20 digits is a TypeError (500), not «no such refund». */
    public function test_rev_782_4_a_refund_id_out_of_range_is_not_found(): void
    {
        $payment = $this->pay();

        $this->withToken($this->token(['admin.invoice_transactions.update']))
            ->deleteJson('/api/v1/admin/invoices/502/payments/' . $payment->id . '/refunds/99999999999999999999')
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- REV-782-5

    /** `price` is `numeric` with no bound: «-1e400» is numeric (−INF) and reaches the model: 500 instead of 422. */
    public function test_rev_782_5_a_price_beyond_any_amount_is_a_validation_error(): void
    {
        $this->withToken($this->token(self::LINES))->postJson(route('api.v1.admin.invoice-items.store'),
            ['invoice_id' => 502, 'description' => 'Discount', 'price' => '-1e400', 'quantity' => 1, 'taxed' => false])
            ->assertStatus(422)->assertJsonValidationErrors('price');
        $this->assertSame(1, InvoiceItem::query()->where('invoice_id', 502)->count());
    }

    /** Pre-existing twin of the above, for the report: the positive spelling on the same rule. */
    public function test_rev_782_5_a_positive_price_beyond_any_amount_is_a_validation_error(): void
    {
        $this->withToken($this->token(self::LINES))->postJson(route('api.v1.admin.invoice-items.store'),
            ['invoice_id' => 502, 'description' => 'Hosting', 'price' => '1e400', 'quantity' => 1, 'taxed' => false])
            ->assertStatus(422)->assertJsonValidationErrors('price');
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * The Admin API does for an invoice's payments what staff do in the panel, no more (owner 2026-11-10, sweep 7
 * TIGHT-INVOICES-PAYMENTS-32, docs/staff-freedom.md): record, edit, delete, refund, undo a refund. Each endpoint is the
 * panel's service, so each test compares with what that service gives when called as the panel calls it. On invoices
 * the WHMCS importer brought with its own steps: #480 paid there, #501 and #502 unpaid (services 31 and 32).
 */
class StaffFreedomApiPaymentsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private const ALL = ['admin.invoice_transactions.view', 'admin.invoice_transactions.create', 'admin.invoice_transactions.update',
        'admin.invoice_transactions.delete', 'admin.invoices.update'];

    private Staff $staff;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-09-10');
        $this->whmcsService(32, '2026-09-25');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-08-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(502, 'Unpaid', [32 => 10.0], due: '2026-09-25');
        $this->importWhmcs();
        // After the import: its last step reloads the settings.
        $this->storeSettings(['credits_enabled' => true]);
        $this->imported(501);
        $this->imported(502);
        $this->staff = Staff::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function token(array $permissions = self::ALL): string
    {
        $token = 'PAYA' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => $this->staff->id, 'name' => 'payments', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    /** @param  array<string, mixed>  $body */
    private function record(int $invoice, array $body, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->token())->postJson(route('api.v1.admin.invoices.payments.store', $invoice), $body);
    }

    /** @return array{0: string, 1: int, 2: int} status, what is left and the money it keeps, in cents */
    private function state(int $invoice = 501): array
    {
        $fresh = Invoice::query()->findOrFail($invoice);

        return [$fresh->status, RecordPaymentService::toCents($fresh->remaining), $fresh->moneyKeptCents()];
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->value('amount') ?? 0), 2, '.', '');
    }

    /** @param  list<string>  $known  rules a scenario leaves on purpose */
    private function assertMoneyClean(array $known = []): void
    {
        $rules = array_values(array_diff(MoneyCheck::RULES, ['paid_not_applied', 'gateway_received']));
        $found = array_values(array_unique(array_column((new MoneyCheck)->run($rules), 'rule')));
        sort($found);
        sort($known);
        $this->assertSame($known, $found, 'the money check after the correction: ' . json_encode((new MoneyCheck)->run($rules)));
    }

    private function paid(int $invoice = 501, string $amount = '10.00', ?string $id = null): InvoiceTransaction
    {
        return (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail($invoice), null, $amount, $id ?? 'tr_' . $invoice);
    }

    private function stripeCharge(int $invoice = 502): InvoiceTransaction
    {
        Gateway::query()->firstOrCreate(['name' => 'Stripe'], ['extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);

        return ExtensionHelper::addPayment($invoice, 'Stripe', 10.00, null, 'ch_' . $invoice);
    }

    // ---------------------------------------------------------------- record

    public function test_a_payment_recorded_through_the_api_gives_what_the_panels_add_payment_gives(): void
    {
        $response = $this->record(501, ['amount' => '10.00', 'transaction_id' => 'tr_api', 'paid_at' => '2026-09-18', 'fee' => '0.30'])->assertCreated();

        $response->assertJsonPath('data.attributes.amount', '10.00')->assertJsonPath('data.attributes.transaction_id', 'tr_api')
            ->assertJsonPath('data.attributes.fee', '0.30')->assertJsonPath('data.attributes.status', 'succeeded');
        $payment = InvoiceTransaction::query()->where('invoice_id', 501)->sole();
        $this->assertSame('2026-09-18', $payment->created_at->toDateString(), 'the day the money arrived');
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'its service is renewed, once');
        $this->assertFalse(EditPayment::recordedByGateway($payment), 'staff typed it: theirs to correct');

        // The panel's own call on the twin invoice: the same result.
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(502), null, '10.00', 'tr_panel');
        $this->assertSame($this->state(502), $this->state(501));
        $this->assertSame('0.00', $this->credit());
        $this->assertMoneyClean();

        $this->withToken($this->token(['admin.invoice_transactions.view']))->getJson(route('api.v1.admin.invoices.payments.index', 501))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $payment->id);
        $this->withToken($this->token(['admin.invoice_transactions.view']))->getJson(route('api.v1.admin.invoices.payments.show', [501, $payment]))
            ->assertOk()->assertJsonPath('data.attributes.invoice_id', 501);
    }

    public function test_the_same_transaction_id_twice_records_once(): void
    {
        $token = $this->token();
        $this->record(501, ['amount' => '4.00', 'transaction_id' => 'tr_once'], $token)->assertCreated();

        $this->record(501, ['amount' => '4.00', 'transaction_id' => 'tr_once'], $token)->assertStatus(422)
            ->assertJsonPath('message', __('This gateway transaction is already recorded (invoice #:invoice): nothing was added.', ['invoice' => 501]));
        // On another invoice of the customer too: the id is one payment's.
        $this->record(502, ['amount' => '4.00', 'transaction_id' => 'tr_once'], $token)->assertStatus(422);

        $this->assertSame(1, InvoiceTransaction::query()->count() - InvoiceTransaction::query()->where('invoice_id', 480)->count());
        $this->assertSame([Invoice::STATUS_UNPAID, 600, 400], $this->state(501));
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(502));
        $this->assertSame(1, MoneyEvent::query()->where('invoice_id', 501)->where('kind', MoneyEventKind::Payment->value)->count());
        $this->assertSame('0.00', $this->credit());
        $this->assertMoneyClean();
    }

    public function test_more_than_what_is_left_becomes_credit_as_add_payment_or_is_refused_as_mark_paid(): void
    {
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_DRAFT]); // nothing open for the credit to pay

        $this->record(501, ['amount' => '12.00', 'up_to_remaining' => true])->assertStatus(422)
            ->assertJsonPath('message', __('More than what is left to pay (:remaining): record an overpayment with Add Payment.', ['remaining' => '10.00']));
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(501), 'refused whole');

        $this->record(501, ['amount' => '12.00'])->assertCreated()->assertJsonPath('data.attributes.credited_amount', '2.00');

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame('2.00', $this->credit());
        $this->assertMoneyClean();
    }

    public function test_an_amount_is_a_plain_decimal_string_and_only_the_listed_fields_are_read(): void
    {
        $token = $this->token();
        foreach ([10, 10.5, '1e3', '10.123', '-5.00', '0x1A', '1 0', '+10', '10,00', '', null, ['10.00'], '99999999999.00'] as $amount) {
            $this->record(501, ['amount' => $amount], $token)->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->record(501, ['amount' => '0.00'], $token)->assertStatus(422)->assertJsonPath('message', __('The amount must be positive.'));
        $this->record(501, ['amount' => '5.00', 'fee' => '1e1'], $token)->assertStatus(422)->assertJsonValidationErrors('fee');
        $this->record(501, ['amount' => '5.00', 'paid_at' => '2026-09-21'], $token)->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->record(501, ['amount' => '5.00', 'gateway_id' => 999999], $token)->assertStatus(422)->assertJsonValidationErrors('gateway_id');
        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', 501)->count());

        // Nothing but the listed fields reaches the payment.
        $this->record(501, ['amount' => '5.00', 'status' => 'failed', 'is_credit_transaction' => true, 'credited_amount' => '5.00', 'refunded_amount' => '5.00',
            'invoice_id' => 502, 'source_transaction_id' => 1], $token)->assertCreated();
        $payment = InvoiceTransaction::query()->where('invoice_id', 501)->sole();
        $this->assertSame([InvoiceTransactionStatus::Succeeded, false, '0.00', '0.00', null],
            [$payment->status, (bool) $payment->is_credit_transaction, (string) $payment->credited_amount, (string) $payment->refunded_amount, $payment->source_transaction_id]);
        $this->assertSame([Invoice::STATUS_UNPAID, 500, 500], $this->state(501));
    }

    public function test_a_cancelled_invoice_takes_a_payment_only_with_reopen_which_is_mark_paids(): void
    {
        Invoice::query()->findOrFail(502)->update(['status' => Invoice::STATUS_CANCELLED]);
        $noInvoices = $this->token(['admin.invoice_transactions.create']);

        $this->record(502, ['amount' => '10.00'], $noInvoices)->assertStatus(422)
            ->assertJsonPath('message', __('This invoice is cancelled: reopen it to record the payment on it.'));
        $this->record(502, ['amount' => '10.00', 'reopen' => true], $noInvoices)->assertForbidden();
        $this->assertSame(Invoice::STATUS_CANCELLED, $this->state(502)[0]);
        // Mark Paid never records more than what is left.
        $this->record(502, ['amount' => '11.00', 'reopen' => true])->assertStatus(422);
        $this->assertSame(Invoice::STATUS_CANCELLED, $this->state(502)[0], 'refused whole: not reopened');

        $this->record(502, ['amount' => '10.00', 'reopen' => true])->assertCreated();

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(502));
        $this->assertSame('0.00', $this->credit());
        $this->assertMoneyClean();
    }

    // ---------------------------------------------------------------- delete

    public function test_a_payment_is_deleted_as_in_the_panel_and_spent_credit_asks_for_the_flag(): void
    {
        $payment = $this->paid(501, '15.00'); // 5.00 over: credit, which pays part of #502 at once (auto-apply)
        $this->assertSame(['0.00', [Invoice::STATUS_UNPAID, 500, 500]], [$this->credit(), $this->state(502)]);
        $token = $this->token(['admin.invoice_transactions.delete']);
        $url = route('api.v1.admin.invoices.payments.destroy', [501, $payment]);

        $this->withToken($token)->deleteJson($url)->assertStatus(422)->assertJsonPath('message',
            __('Part of this payment became account credit and the customer has already used it: delete it on its own and confirm that their credit goes negative (they owe it).'));
        $this->assertNotNull($payment->fresh());
        $this->withToken($token)->deleteJson($url, ['allow_negative_credit' => 'yes'])->assertStatus(422)->assertJsonValidationErrors('allow_negative_credit');

        $this->withToken($token)->deleteJson($url, ['allow_negative_credit' => true])->assertNoContent();

        $this->assertNull($payment->fresh());
        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(501), 'open again, as after the panel\'s delete');
        $this->assertSame('-5.00', $this->credit(), 'with the flag: they owe it');
        $this->assertMoneyClean(['credit_negative']);
    }

    public function test_kept_a_payment_with_a_refund_and_one_the_gateway_refunded_are_not_deleted(): void
    {
        $payment = $this->paid();
        ExtensionHelper::refundLocally($payment, 3.00, notify: false);

        $this->withToken($this->token())->deleteJson(route('api.v1.admin.invoices.payments.destroy', [501, $payment]))->assertStatus(422)
            ->assertJsonPath('message', __('This payment has refunds: it cannot be deleted (its refund history and gateway transaction id must stay).'));

        $this->assertNotNull($payment->fresh());
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state(501));
    }

    // ---------------------------------------------------------------- edit

    public function test_what_moves_no_money_is_edited_in_place_with_the_permission_to_delete(): void
    {
        $payment = $this->paid();
        $gateway = Gateway::query()->create(['name' => 'Bank', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $token = $this->token(['admin.invoice_transactions.delete']);

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [501, $payment]),
            ['paid_at' => '2026-09-18', 'gateway_id' => $gateway->id, 'transaction_id' => 'bank-77', 'fee' => '0.35', 'amount' => '10.00'])
            ->assertOk()->assertJsonPath('data.id', (string) $payment->id)->assertJsonPath('data.attributes.fee', '0.35');

        $same = $payment->fresh();
        $this->assertSame(['10.00', 'bank-77', '0.35', '2026-09-18', $gateway->id], [(string) $same->amount, $same->transaction_id, (string) $same->fee, $same->created_at->toDateString(), $same->gateway_id]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame('2026-09-18', Invoice::query()->findOrFail(501)->paid_at->toDateString(), 'the paid date follows its payment');
        $this->assertMoneyClean();

        // Another amount or invoice records money: the key needs the permission to record payments too.
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [501, $payment]), ['amount' => '6.00'])->assertForbidden();
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [501, $payment]), ['invoice_id' => 502])->assertForbidden();
        $this->withToken($this->token(['admin.invoice_transactions.update', 'admin.invoice_transactions.create']))
            ->putJson(route('api.v1.admin.invoices.payments.update', [501, $payment]), ['fee' => '0.10'])->assertForbidden();
        $this->assertSame(['10.00', '0.35', 501], [(string) $payment->fresh()->amount, (string) $payment->fresh()->fee, $payment->fresh()->invoice_id]);
    }

    public function test_another_amount_or_invoice_is_delete_and_record_again_as_the_panels_edit(): void
    {
        $payment = $this->paid();
        $token = $this->token();

        $lower = $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [501, $payment]), ['amount' => '6.00'])->assertOk();

        $this->assertNull($payment->fresh(), 'deleted and recorded again: a new row');
        $again = InvoiceTransaction::query()->where('invoice_id', 501)->sole();
        $lower->assertJsonPath('data.id', (string) $again->id)->assertJsonPath('data.attributes.amount', '6.00');
        $this->assertSame(['tr_501', '2026-09-20'], [$again->transaction_id, $again->created_at->toDateString()]);
        $this->assertSame([Invoice::STATUS_UNPAID, 400, 600], $this->state(501), 'no longer covered: open again');
        $this->assertSame(1, MoneyEvent::query()->where('invoice_id', 501)->where('kind', MoneyEventKind::Reversal->value)->count());
        $this->assertMoneyClean();

        $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [501, $again]), ['invoice_id' => 502])->assertOk()
            ->assertJsonPath('data.attributes.invoice_id', 502);

        $this->assertSame([Invoice::STATUS_UNPAID, 1000, 0], $this->state(501));
        $this->assertSame([Invoice::STATUS_UNPAID, 400, 600], $this->state(502));
        $this->assertSame('0.00', $this->credit());
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the period given stays given');
        $this->assertMoneyClean();
    }

    public function test_kept_the_edit_refuses_what_the_panel_refuses(): void
    {
        $token = $this->token();
        $edit = fn (int $invoice, InvoiceTransaction $payment, array $body) => $this->withToken($token)
            ->putJson(route('api.v1.admin.invoices.payments.update', [$invoice, $payment]), $body)->assertStatus(422);

        // What its gateway recorded: amount, id, gateway and invoice stay; date and fee are corrected.
        $charge = $this->stripeCharge(502);
        $edit(502, $charge, ['amount' => '9.00'])->assertJsonPath('message', __('invoices.payment_edit.gateway_amount'));
        $edit(502, $charge, ['transaction_id' => 'ch_other'])->assertJsonPath('message', __('invoices.payment_edit.gateway_identity'));
        $edit(502, $charge, ['invoice_id' => 501])->assertJsonPath('message', __('invoices.payment_edit.gateway_move'));
        $this->withToken($token)->putJson(route('api.v1.admin.invoices.payments.update', [502, $charge]), ['fee' => '0.45', 'paid_at' => '2026-09-19'])->assertOk();
        $same = $charge->fresh();
        $this->assertSame(['10.00', 'ch_502', '0.45', '2026-09-19', 502], [(string) $same->amount, $same->transaction_id, (string) $same->fee, $same->created_at->toDateString(), $same->invoice_id]);

        // Paid in WHMCS (frozen): its imported payment keeps amount, invoice and date.
        $imported = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        $edit(480, $imported, ['amount' => '9.00'])->assertJsonPath('message', __('invoices.payment_edit.before_cut_over'));
        $edit(480, $imported, ['paid_at' => '2026-09-01'])->assertJsonPath('message', __('invoices.payment_edit.date_imported'));
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(480));

        // A fiscal document from Ekdosi: issued is final.
        $payment = $this->paid(501);
        EkdosiDocument::query()->create(['invoice_id' => 501, 'mark' => '400001234560501', 'code' => 'ΤΙΜ501']);
        $edit(501, $payment, ['amount' => '9.00'])->assertJsonPath('message', __('invoices.payment_edit.ekdosi', ['invoice' => 501]));
        // A payment of another customer's invoice, or one that is not there.
        $other = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        EkdosiDocument::query()->delete();
        $edit(501, $payment, ['invoice_id' => $other->id])->assertJsonPath('message', __('invoices.payment_edit.other_customer'));
        $edit(501, $payment, ['invoice_id' => 999999])->assertJsonValidationErrors('invoice_id');
        $edit(501, $payment, ['amount' => 9])->assertJsonValidationErrors('amount');
        $edit(501, $payment, ['paid_at' => '2026-09-21'])->assertJsonValidationErrors('paid_at');

        $this->assertSame(['10.00', 501], [(string) $payment->fresh()->amount, $payment->fresh()->invoice_id]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertMoneyClean();
    }

    // ---------------------------------------------------------------- refund

    public function test_a_refund_by_hand_and_one_to_credit_give_what_the_panels_refund_gives(): void
    {
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_DRAFT]); // nothing open for the credit to pay
        $payment = $this->paid();
        $token = $this->token(['admin.invoice_transactions.update', 'admin.invoice_transactions.view']);
        $url = route('api.v1.admin.invoices.payments.refunds.store', [501, $payment]);

        $this->withToken($token)->postJson($url, ['amount' => '3.00', 'method' => 'manual', 'notify' => false])->assertOk()
            ->assertJsonPath('data.attributes.refunded_amount', '3.00');
        $this->assertSame([Invoice::STATUS_PAID, 0, 700], $this->state(501));

        $this->withToken($token)->postJson($url, ['amount' => '4.00', 'method' => 'credit', 'notify' => false])->assertOk()
            ->assertJsonPath('data.attributes.credited_amount', '4.00');
        $this->assertSame(['4.00', [Invoice::STATUS_PAID, 0, 300]], [$this->credit(), $this->state(501)]);
        $this->assertMoneyClean();

        // The same two refunds by the panel's own calls, on a twin: the same result.
        Invoice::query()->whereKey(502)->update(['status' => Invoice::STATUS_UNPAID]);
        $twin = $this->paid(502);
        ExtensionHelper::refundLocally($twin, 3.00, notify: false);
        ExtensionHelper::refundToCredit($twin, 4.00, notify: false);
        $this->assertSame($this->state(502), $this->state(501));
        $this->assertSame([(string) $twin->fresh()->refunded_amount, (string) $twin->fresh()->credited_amount],
            [(string) $payment->fresh()->refunded_amount, (string) $payment->fresh()->credited_amount]);

        $kinds = $this->withToken($token)->getJson(route('api.v1.admin.invoices.payments.refunds.index', [501, $payment]))->assertOk()->json('data.*.attributes.kind');
        $this->assertEqualsCanonicalizing([UndoRefund::BY_HAND, UndoRefund::TO_CREDIT], $kinds);
    }

    public function test_kept_a_refund_is_refused_as_the_panels_form_refuses_it(): void
    {
        $payment = $this->paid();
        $token = $this->token();
        $refund = fn (int $invoice, InvoiceTransaction $on, array $body) => $this->withToken($token)
            ->postJson(route('api.v1.admin.invoices.payments.refunds.store', [$invoice, $on]), $body)->assertStatus(422);

        $refund(501, $payment, ['amount' => '10.01', 'method' => 'manual'])->assertJsonValidationErrors('amount');
        $refund(501, $payment, ['amount' => '0.00', 'method' => 'manual'])->assertJsonValidationErrors('amount');
        $refund(501, $payment, ['amount' => 3, 'method' => 'manual'])->assertJsonValidationErrors('amount');
        $refund(501, $payment, ['amount' => '3.00'])->assertJsonValidationErrors('method');
        $refund(501, $payment, ['amount' => '3.00', 'method' => 'chargeback'])->assertJsonValidationErrors('method');
        // No gateway took it: it does not go back through one.
        $this->assertArrayNotHasKey('gateway', TransactionsRelationManager::refundMethods($payment));
        $refund(501, $payment, ['amount' => '3.00', 'method' => 'gateway'])->assertJsonValidationErrors('method');
        // A payment made with account credit goes back to credit only.
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['is_credit_transaction' => true]);
        $refund(501, $payment, ['amount' => '3.00', 'method' => 'manual'])->assertJsonValidationErrors('method');
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['is_credit_transaction' => false]);
        // A credit purchase is not refunded to credit: the service's own words.
        DB::table('invoice_items')->insert(['invoice_id' => 501, 'description' => 'Credit', 'quantity' => 1, 'price' => 0, 'reference_type' => Credit::class, 'reference_id' => 1]);
        $refund(501, $payment, ['amount' => '3.00', 'method' => 'credit', 'notify' => false])
            ->assertJsonPath('message', 'A credit purchase is not refunded to credit: refund it back, which takes the credit it bought.');
        DB::table('invoice_items')->where('invoice_id', 501)->where('reference_type', Credit::class)->delete();
        // One that did not arrive.
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['status' => InvoiceTransactionStatus::Processing->value]);
        $refund(501, $payment, ['amount' => '3.00', 'method' => 'manual', 'notify' => false])->assertJsonPath('message', 'Only a succeeded payment can be refunded.');
        DB::table('invoice_transactions')->where('id', $payment->id)->update(['status' => InvoiceTransactionStatus::Succeeded->value]);

        $this->assertSame(['0.00', '0.00'], [(string) $payment->fresh()->refunded_amount, (string) $payment->fresh()->credited_amount]);
        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame('0.00', $this->credit());
    }

    // ---------------------------------------------------------------- undo a refund

    public function test_a_refund_is_undone_as_the_panels_undo_refund_does_it(): void
    {
        $payment = $this->paid();
        ExtensionHelper::refundLocally($payment, 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $this->state(501)[0]);
        $refund = UndoRefund::refunds($payment)->sole();
        $token = $this->token(['admin.invoice_transactions.update']);

        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [501, $payment, $refund->id]))->assertOk()
            ->assertJsonPath('data.attributes.refunded_amount', '0.00');

        $this->assertSame([Invoice::STATUS_PAID, 0, 1000], $this->state(501));
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'paid again gives no second period');
        $this->assertTrue(UndoRefund::refunds($payment)->isEmpty());
        $this->assertMoneyClean();
        // Undone once: its id is no refund of the payment any more.
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [501, $payment, $refund->id]))->assertNotFound();
    }

    public function test_a_gateways_refund_and_a_spent_credit_are_undone_only_with_their_flags(): void
    {
        $token = $this->token();
        // To credit, and the customer used it on another invoice.
        $payment = $this->paid();
        ExtensionHelper::refundToCredit($payment, 4.00, notify: false);
        $this->assertSame(['0.00', [Invoice::STATUS_UNPAID, 600, 400]], [$this->credit(), $this->state(502)], 'precondition: the credit paid part of #502');
        $toCredit = UndoRefund::refunds($payment)->sole();
        $url = route('api.v1.admin.invoices.payments.refunds.destroy', [501, $payment, $toCredit->id]);

        $this->withToken($token)->deleteJson($url)->assertStatus(422)->assertJsonPath('message', __('invoices.refund_undo.credit_spent'));
        $this->assertSame([Invoice::STATUS_PAID, 0, 600], $this->state(501), 'refused whole');
        $this->withToken($token)->deleteJson($url, ['allow_negative_credit' => true])->assertOk();
        $this->assertSame(['-4.00', [Invoice::STATUS_PAID, 0, 1000]], [$this->credit(), $this->state(501)]);

        // One the gateway made: only as a correction of the books. No gateway class is loaded here: a call would throw.
        $third = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $third->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => 10, 'taxed' => false]);
        $charge = $this->stripeCharge($third->id);
        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'ch_' . $third->id, 're_1', 4.00, 'EUR'));
        $byGateway = UndoRefund::refunds($charge)->sole();
        $url = route('api.v1.admin.invoices.payments.refunds.destroy', [$third->id, $charge, $byGateway->id]);

        $this->withToken($token)->deleteJson($url)->assertStatus(422)->assertJsonPath('message', __('invoices.refund_undo.gateway_unconfirmed'));
        $this->assertSame('4.00', (string) $charge->fresh()->refunded_amount);
        $this->withToken($token)->deleteJson($url, ['money_left_at_gateway' => true])->assertOk();

        $this->assertSame('0.00', (string) $charge->fresh()->refunded_amount);
        $this->assertSame(GatewayRefund::ORIGIN_FAILED, GatewayRefund::query()->where('reference', 're_1')->sole()->origin);
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'ch_' . $third->id, 're_1', 4.00, 'EUR'), 'its late event records nothing');
        $this->assertMoneyClean(['credit_negative']);
    }

    public function test_kept_no_undo_under_a_fiscal_document_and_never_of_a_chargeback(): void
    {
        $token = $this->token();
        $payment = $this->paid();
        ExtensionHelper::refundLocally($payment, 3.00, notify: false);
        $refund = UndoRefund::refunds($payment)->sole();
        EkdosiDocument::query()->create(['invoice_id' => 501, 'mark' => '400001234560501', 'code' => 'ΤΙΜ501']);

        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [501, $payment, $refund->id]))
            ->assertStatus(422)->assertJsonPath('message', __('invoices.refund_undo.ekdosi'));
        $this->assertSame('3.00', (string) $payment->fresh()->refunded_amount);

        // A chargeback is not a refund: it is not listed, and its event's id undoes nothing.
        $charge = $this->stripeCharge(502);
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'ch_502', 'dp_1', 10.00, 'EUR'));
        $event = MoneyEvent::query()->where('invoice_transaction_id', $charge->id)->where('kind', MoneyEventKind::Refund->value)->sole();
        $before = [$this->state(502), (string) $charge->fresh()->refunded_amount];

        $this->withToken($token)->getJson(route('api.v1.admin.invoices.payments.refunds.index', [502, $charge]))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->deleteJson(route('api.v1.admin.invoices.payments.refunds.destroy', [502, $charge, $event->id]), ['money_left_at_gateway' => true])->assertNotFound();
        $this->assertSame($before, [$this->state(502), (string) $charge->fresh()->refunded_amount]);
    }
}

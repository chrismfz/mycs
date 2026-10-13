<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\MoneyEventKind;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * PR 2c-1 (docs/payments.md), as WHMCS: a payment goes back through the gateway, by hand, or to the customer's account
 * credit; a paid invoice whose payments all went back is refunded (final); its services stay as they are; an invoice
 * holding money is refunded, never cancelled (INV-16).
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, ?string $reference = null, ?int $referenceId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter(['description' => 'Hosting', 'quantity' => 1, 'price' => $price,
            'reference_type' => $reference, 'reference_id' => $referenceId], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function paid(float $price = 100): Invoice
    {
        $invoice = $this->invoice($price);
        (new RecordPaymentService)->handle($invoice, null, $price, transactionId: 'wire-' . $invoice->id);

        return $invoice->fresh();
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    private function assertBooksClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    // ------------------------------------------------------------------ refunded

    public function test_a_partial_refund_keeps_it_paid_and_the_rest_makes_it_refunded(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refundLocally($payment, 40.0);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'a part back: still paid');

        ExtensionHelper::refundLocally($payment->fresh(), 60.0);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status, 'all back: refunded, as WHMCS');
        $this->assertSame(0, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertBooksClean();
    }

    public function test_the_service_stays_as_it_is_after_a_full_refund(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addDays(3)]);
        $invoice = $this->invoice(10, Service::class, $service->id);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-s');
        $renewed = Carbon::parse($service->fresh()->expires_at)->toDateString();
        $this->assertNotSame(now()->addDays(3)->toDateString(), $renewed, 'the payment renewed it');

        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.0);

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame([Service::STATUS_ACTIVE, $renewed], [$service->fresh()->status, Carbon::parse($service->fresh()->expires_at)->toDateString()],
            'staff decide: the service is not cancelled nor moved back');
    }

    public function test_a_refunded_invoice_is_final_and_set_only_by_refunding(): void
    {
        $paid = $this->paid(50);
        try {
            $paid->update(['status' => Invoice::STATUS_REFUNDED]);
            $this->fail('refunded by its status, with its money still on it');
        } catch (DisplayException) {
        }
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);

        ExtensionHelper::refundLocally($paid->transactions()->sole(), 50.0);
        try {
            $paid->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
            $this->fail('a refunded invoice was reopened');
        } catch (DisplayException) {
        }

        // A payment that lands on it afterwards becomes credit.
        (new RecordPaymentService)->handle($paid->fresh(), null, 20.0, transactionId: 'late');
        $this->assertSame([Invoice::STATUS_REFUNDED, '20.00'], [$paid->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    // ------------------------------------------------------------------ to account credit

    public function test_a_refund_to_credit_moves_the_money_to_the_customers_credit(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refundToCredit($payment, 30.0);
        $this->assertSame([Invoice::STATUS_PAID, '30.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertSame('30.00', number_format((float) $payment->fresh()->credited_amount, 2, '.', ''));
        $event = MoneyEvent::query()->where('kind', MoneyEventKind::CreditIn->value)->sole();
        $this->assertSame([MoneyTrail::CAUSE_REFUND_TO_CREDIT, MoneyTrail::SOURCE_MANUAL], [$event->cause, $event->source]);
        $this->assertSame('70.00', number_format((float) $payment->fresh()->refundable_amount, 2, '.', ''), 'the credited part is not refundable again');

        ExtensionHelper::refundToCredit($payment->fresh(), 70.0);
        $this->assertSame([Invoice::STATUS_REFUNDED, '100.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    public function test_the_credit_pays_the_customers_other_open_invoices(): void
    {
        config(['settings.credits_auto_use' => true]);
        User::whereKey($this->user->id)->update(['credit_auto_apply' => true]);
        $paid = $this->paid(100);
        $open = $this->invoice(25);

        ExtensionHelper::refundToCredit($paid->transactions()->sole(), 100.0);

        $this->assertSame([Invoice::STATUS_REFUNDED, Invoice::STATUS_PAID, '75.00'], [$paid->fresh()->status, $open->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    public function test_a_credit_payment_goes_back_to_credit_only(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(40);
        (new RecordPaymentService)->payWithCredit($invoice);
        $payment = $invoice->transactions()->sole();
        $this->assertSame('0.00', $this->balance());

        try {
            ExtensionHelper::refundLocally($payment, 40.0);
            $this->fail('credit was refunded as money');
        } catch (InvalidArgumentException) {
        }

        ExtensionHelper::refundToCredit($payment->fresh(), 40.0);
        $this->assertSame([Invoice::STATUS_REFUNDED, '40.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    public function test_a_credit_purchase_is_not_refunded_to_credit(): void
    {
        $invoice = $this->invoice(50, Credit::class);
        (new RecordPaymentService)->handle($invoice, null, 50.0, transactionId: 'wire-c');
        $this->assertSame('50.00', $this->balance());

        try {
            ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 50.0);
            $this->fail('credit bought was refunded to credit');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([Invoice::STATUS_PAID, '50.00'], [$invoice->fresh()->status, $this->balance()]);
    }

    // ------------------------------------------------------------------ cancelling (INV-16)

    public function test_an_invoice_holding_money_is_refunded_not_cancelled(): void
    {
        $paid = $this->paid(100);
        try {
            $paid->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
            $this->fail('a paid invoice was cancelled');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('refund', $e->getMessage());
        }

        $part = $this->invoice(100);
        (new RecordPaymentService)->handle($part, null, 40.0, transactionId: 'wire-part');
        try {
            $part->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
            $this->fail('an invoice holding 40.00 was cancelled');
        } catch (DisplayException) {
        }

        // Its money back to credit first: then it is cancelled, and nothing is stuck on it.
        ExtensionHelper::refundToCredit($part->transactions()->sole(), 40.0);
        $part->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED, '40.00'], [$paid->fresh()->status, $part->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    public function test_an_open_invoice_without_money_is_still_cancelled(): void
    {
        $invoice = $this->invoice(30);

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Not needed']);

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    // ------------------------------------------------------------------ the admin and the words

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_refund_a_payment_without_a_provider_id_to_credit_from_the_admin(): void
    {
        $invoice = $this->invoice(80);
        (new RecordPaymentService)->recordByStaff($invoice, null, 80.0); // a transfer recorded by hand, no id
        $payment = $invoice->transactions()->sole();
        $this->assertNull($payment->transaction_id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        Livewire::actingAs($staff)->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->assertTableActionVisible('refund', $payment)
            ->callTableAction('refund', $payment, data: ['amount' => '80.00', 'method' => 'credit'])
            ->assertHasNoTableActionErrors();

        $this->assertSame([Invoice::STATUS_REFUNDED, '80.00'], [$invoice->fresh()->status, $this->balance()]);
    }

    public function test_statuses_read_as_in_whmcs(): void
    {
        $this->assertSame(['draft' => 'Draft', 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'],
            Invoice::statusOptions());
        $this->assertSame('info', Invoice::statusColor(Invoice::STATUS_REFUNDED));
    }
}

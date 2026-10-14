<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Enums\AdjustmentNoteType;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * PR 2b (docs/payments.md): an open invoice that is covered closes as paid. A zero invoice the app makes (a free
 * service, a price now free) closes on its own, as in WHMCS; one staff brought down below its payments is closed by
 * staff («Close as paid»), and the part over its total becomes account credit, once.
 */
class InvoiceSettleTest extends TestCase
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

    private function invoice(float $price = 100, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function service(float $price = 10, string $status = Service::STATUS_PENDING): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1]);
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    private function owner(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    // ------------------------------------------------------------------ staff brought it down below its payments

    public function test_an_invoice_brought_down_below_its_payment_stays_open_until_staff_close_it_and_the_part_over_becomes_credit(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 60.0, transactionId: 'wire-1');
        (new RecordPaymentService)->handle($invoice->fresh(), null, 30.0, transactionId: 'wire-2');
        $invoice->items()->sole()->update(['price' => 75]);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'an edit does not close it on its own');
        $this->assertSame(['open_settled'], array_values(array_unique(array_column((new MoneyCheck)->run(['open_settled']), 'rule'))));

        $this->assertTrue((new RecordPaymentService)->settle($invoice));

        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('15.00', $this->balance());
        $this->assertSame(0, RecordPaymentService::toCents($invoice->remaining));
        // Newest payment first: the 15.00 over comes off wire-2.
        $this->assertSame(['wire-1' => '0.00', 'wire-2' => '15.00'], $invoice->transactions()->orderBy('id')->get()
            ->mapWithKeys(fn ($payment) => [$payment->transaction_id => number_format((float) $payment->credited_amount, 2, '.', '')])->all());
        $event = MoneyEvent::query()->where('kind', MoneyEventKind::CreditIn->value)->sole();
        $this->assertSame([MoneyTrail::CAUSE_INVOICE_REDUCED, MoneyTrail::SOURCE_MANUAL, '15.00'], [$event->cause, $event->source, number_format((float) $event->amount, 2, '.', '')]);
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));

        $this->assertFalse((new RecordPaymentService)->settle($invoice), 'closed once');
        $this->assertSame('15.00', $this->balance(), 'credited once');
    }

    public function test_the_part_over_spreads_over_the_payments_newest_first_and_a_credit_payment_gives_its_credit_back(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 60.0, transactionId: 'wire-1');
        (new RecordPaymentService)->payWithCredit($invoice->fresh()); // 40.00 from credit
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]); // staff reopened it to fix a line
        $invoice->items()->sole()->update(['price' => 50]);

        $this->assertTrue((new RecordPaymentService)->settle($invoice));

        $this->assertSame('50.00', $this->balance(), 'the 40.00 credit back, then 10.00 of the transfer');
        $sources = MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_INVOICE_REDUCED)->orderBy('id')->get()
            ->map(fn ($event) => [$event->source, number_format((float) $event->amount, 2, '.', '')])->all();
        $this->assertSame([[MoneyTrail::SOURCE_CREDIT, '40.00'], [MoneyTrail::SOURCE_MANUAL, '10.00']], $sources);
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_what_is_not_closed(): void
    {
        $service = new RecordPaymentService;

        $empty = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->assertFalse($service->settle($empty), 'no lines: staff are still writing it');

        $owed = $this->invoice(10);
        $this->assertFalse($service->settle($owed), 'something left to pay');

        $draft = $this->invoice(0, Invoice::STATUS_DRAFT);
        $this->assertFalse($service->settle($draft), 'a draft closes when it is finalized');

        // A discount bigger than the line and the payments: nothing came in to give back.
        $noted = $this->invoice(10);
        $noted->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Goodwill']);
        $this->assertFalse($service->settle($noted), 'staff fix the note');

        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID],
            [$empty->fresh()->status, $owed->fresh()->status, $draft->fresh()->status, $noted->fresh()->status]);
        $this->assertSame('0.00', $this->balance());
    }

    // ------------------------------------------------------------------ zero invoices close on their own (as WHMCS)

    public function test_an_admin_order_for_a_free_service_is_paid_and_the_service_goes_ahead(): void
    {
        $order = Order::create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
        $service = $this->service(0);
        $service->update(['order_id' => $order->id]);

        $page = new CreateOrder;
        $property = new \ReflectionProperty($page, 'record');
        $property->setValue($page, $order->fresh());
        (new \ReflectionMethod($page, 'afterCreate'))->invoke($page);

        $invoice = Invoice::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0, $invoice->transactions()->count(), 'no payment: nothing was paid');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'the free service goes ahead');
        $this->assertNotNull($invoice->items()->sole()->applied_at);
    }

    public function test_an_admin_order_with_a_price_stays_unpaid(): void
    {
        $order = Order::create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
        $this->service(10)->update(['order_id' => $order->id]);

        $page = new CreateOrder;
        (new \ReflectionProperty($page, 'record'))->setValue($page, $order->fresh());
        (new \ReflectionMethod($page, 'afterCreate'))->invoke($page);

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->where('user_id', $this->user->id)->sole()->status);
    }

    public function test_a_line_the_app_reprices_to_nothing_closes_its_invoice(): void
    {
        $service = $this->service(10, Service::STATUS_ACTIVE);
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        $this->assertTrue(ChangeOpenLine::handle($line, fn ($locked) => $locked->price = 0));

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'a zero invoice is not left open to suspend the service');
        $this->assertSame([], (new MoneyCheck)->run(['open_settled']));
    }

    public function test_a_zero_draft_closes_when_it_is_finalized(): void
    {
        $draft = $this->invoice(0, Invoice::STATUS_DRAFT);

        $draft->update(['status' => Invoice::STATUS_UNPAID]);

        $this->assertSame(Invoice::STATUS_PAID, $draft->fresh()->status);
    }

    public function test_the_money_check_reports_a_zero_invoice_left_open_but_not_one_being_written(): void
    {
        $zero = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->assertSame([], (new MoneyCheck)->run(['open_settled']), 'no lines yet');
        DB::table('invoice_items')->insert(['invoice_id' => $zero->id, 'description' => 'Free transfer', 'quantity' => 1, 'price' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->invoice(0, Invoice::STATUS_DRAFT);

        $findings = (new MoneyCheck)->run(['open_settled']);
        $this->assertSame(["invoice #{$zero->id}"], array_column($findings, 'subject'));
        $this->assertStringContainsString('Close as paid', $findings[0]['detail']);
    }

    // ------------------------------------------------------------------ the staff action

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_staff_close_a_covered_invoice_from_the_admin(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 100.0, transactionId: 'wire-9');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->sole()->update(['price' => 80]);
        $staff = $this->owner();

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('closeAsPaid')
            ->callAction('closeAsPaid')
            ->assertHasNoActionErrors();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('20.00', $this->balance());

        $open = $this->invoice(10);
        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $open->getKey()])
            ->assertActionHidden('closeAsPaid');
    }

    public function test_a_processing_payment_that_succeeds_after_the_close_becomes_credit(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 100.0, transactionId: 'wire-1');
        $gateway = Gateway::query()->where('extension', 'Stripe')->sole();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
        (new RecordPaymentService)->handle($invoice->fresh(), $gateway, 20.0, transactionId: 'pi_late', status: InvoiceTransactionStatus::Processing, currency: 'USD');
        $invoice->items()->sole()->update(['price' => 90]);

        $this->assertTrue((new RecordPaymentService)->settle($invoice));
        (new RecordPaymentService)->handle($invoice->fresh(), $gateway, 20.0, transactionId: 'pi_late', currency: 'USD');

        $this->assertSame('30.00', $this->balance(), '10.00 over the lowered total, then the late 20.00 the paid invoice cannot take');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }
}

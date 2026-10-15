<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PayInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR F1 (decision 7, sweep Π2.9/Π3.1/Π3.2): staff record one payment over several invoices of a customer (oldest due
 * first, each in full while it lasts, the rest as credit), and apply a customer's credit to an invoice by hand, as myip
 * does with «Auto-use credit» off.
 */
class PayInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->user->client()->update(['separate_invoices' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = [], ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($user ?? $this->user)->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    /** @return Collection<int, Invoice> three renewal invoices: 10.00 due the 25th, 15.00 the 26th, 20.00 the 24th */
    private function three(): Collection
    {
        $this->service();
        $this->service(['expires_at' => '2026-10-26', 'price' => 15.00]);
        $this->service(['expires_at' => '2026-10-24', 'price' => 20.00]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->get();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function credit(): string
    {
        return $this->money((new CreditLedger)->balanceCents($this->user->client()->id, 'USD') / 100);
    }

    public function test_one_payment_pays_every_invoice_oldest_due_first(): void
    {
        $invoices = $this->three();

        [$payment, $others] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-1');

        $this->assertSame(['paid', 'paid', 'paid'], $invoices->map(fn (Invoice $i) => $i->fresh()->status)->all());
        $this->assertSame('20.00', $this->money($payment->amount - $payment->credited_amount), 'recorded on the one due first, the 24th');
        $this->assertSame($invoices->firstWhere('due_at', Carbon::parse('2026-10-24'))->id, (int) $payment->invoice_id);
        $this->assertCount(2, $others);
        $this->assertTrue(collect($others)->every(fn (InvoiceTransaction $t) => (int) $t->source_transaction_id === $payment->id));
        $this->assertSame('0.00', $this->credit());
        $this->assertSame(['2026-11-25', '2026-11-26', '2026-11-24'], Service::query()->orderBy('id')->get()->map(fn (Service $s) => $s->expires_at->toDateString())->all());
    }

    public function test_less_than_all_pays_the_oldest_and_part_of_the_next(): void
    {
        $invoices = $this->three();

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '25.00', 'wire-2');

        $byDue = $invoices->sortBy('due_at')->values()->map(fn (Invoice $i) => $i->fresh());
        $this->assertSame(['paid', 'unpaid', 'unpaid'], $byDue->pluck('status')->all());
        $this->assertSame(['0.00', '5.00', '15.00'], $byDue->map(fn (Invoice $i) => $this->money($i->remaining))->all());
        $this->assertSame('0.00', $this->credit());
    }

    public function test_more_than_all_leaves_the_rest_as_credit(): void
    {
        $invoices = $this->three();

        (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '50.00', 'wire-3');

        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame('5.00', $this->credit());
    }

    public function test_two_invoices_of_one_service_are_both_paid(): void
    {
        $service = $this->service();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $first = Invoice::query()->sole();
        $second = Invoice::query()->create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'due_at' => '2026-11-25', 'status' => Invoice::STATUS_UNPAID]);
        $second->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 10, 'quantity' => 1,
            'description' => 'next period', 'period_start' => '2026-11-25', 'period_end' => '2026-12-25']);

        (new PayInvoices)->recordByStaff([$first->id, $second->id], null, '20.00', 'wire-4');

        $this->assertSame(['paid', 'paid'], [$first->fresh()->status, $second->fresh()->status]);
        $this->assertSame('2026-12-25', $service->fresh()->expires_at->toDateString());
    }

    public function test_another_customer_a_currency_or_a_paid_invoice_is_refused_and_nothing_is_recorded(): void
    {
        $invoices = $this->three();
        $other = User::factory()->create();
        $other->client()->update(['separate_invoices' => true]);
        $this->service([], $other);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $theirs = Invoice::query()->where('client_id', $other->client()->id)->sole();
        (new RecordPaymentService)->handle($invoices[0], null, (float) $invoices[0]->total, transactionId: 'paid-before');

        foreach ([[$invoices[1]->id, $theirs->id], [$invoices[0]->id, $invoices[1]->id]] as $ids) {
            try {
                (new PayInvoices)->recordByStaff($ids, null, '50.00', 'wire-x');
                $this->fail('paid ' . implode(',', $ids));
            } catch (DisplayException) {
            }
        }
        $this->assertSame(0, InvoiceTransaction::query()->where('transaction_id', 'wire-x')->count());
    }

    public function test_the_payment_that_paid_the_others_is_not_deleted(): void
    {
        $invoices = $this->three();
        [$payment] = (new PayInvoices)->recordByStaff($invoices->pluck('id')->all(), null, '45.00', 'wire-5');

        $this->expectException(DisplayException::class);
        (new RecordPaymentService)->deleteTransaction($payment);
    }

    public function test_staff_apply_credit_with_auto_use_off(): void
    {
        $invoice = $this->three()->first();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 600, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->id])
            ->callAction('applyCredit', data: ['amount' => '6.00'])
            ->assertHasNoActionErrors();

        $this->assertSame(['4.00', '0.00'], [$this->money($invoice->fresh()->remaining), $this->credit()]);
        $this->assertTrue((bool) $invoice->transactions()->sole()->is_credit_transaction);
    }

    public function test_staff_apply_credit_takes_no_more_than_the_invoice_or_the_credit(): void
    {
        $invoice = $this->three()->first();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->id])
            ->callAction('applyCredit', data: ['amount' => '99.00']);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($this->money(50 - (float) $invoice->total), $this->credit());
    }

    public function test_staff_pay_invoices_from_the_list(): void
    {
        $invoices = $this->three();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();

        $this->livewireAs($staff)->test(ListInvoices::class)
            ->selectTableRecords($invoices->pluck('id')->all())
            ->callAction(TestAction::make('payInvoices')->table()->bulk(), data: ['amount' => '45.00', 'paid_at' => '2026-10-20', 'transaction_id' => 'wire-6'])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'wire-6')->count());
    }
}

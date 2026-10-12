<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR 2a (docs/payments.md). Each test asserts the CORRECT behaviour. Ledger rule: every
 * cent a gateway reports is either applied to an invoice or account credit, exactly once.
 */
class PaymentGuardsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.credits_enabled' => true,
            'settings.credits_auto_use' => true,
            'settings.cronjob_invoice' => 7,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
        ]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $start, string $end, string $status = 'pending', float $price = 10.00, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => $currency]);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end,
        ]);

        return $invoice->fresh();
    }

    private function creditInvoice(float $amount): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Credit deposit', 'quantity' => 1, 'price' => $amount, 'reference_type' => Credit::class]);

        return $invoice->fresh();
    }

    private function credit(string $currency = 'USD'): string
    {
        return number_format((float) Credit::query()->where('user_id', $this->user->id)->where('currency_code', $currency)->sum('amount'), 2, '.', '');
    }

    private function setCredit(float $amount): void
    {
        Credit::query()->updateOrCreate(['user_id' => $this->user->id, 'currency_code' => 'USD'], ['amount' => $amount]);
    }

    private function expires(Service $service): string
    {
        return $service->fresh()->expires_at->toDateString();
    }

    /**
     * Pay with credit through the real Livewire component. $race runs once, inside the credit payment, before
     * it takes its locks (RecordPaymentService's first read of the customer): the window in which a writer that
     * does not wait for those locks (staff in the admin, the cron's mass cancel) can change the invoice.
     */
    private function payWithCreditsInBrowser(Invoice $invoice, ?callable $race = null): void
    {
        $this->actingAs($this->user);
        View::addLocation(base_path('themes/default/views'));
        $component = Livewire::test(Show::class, ['invoice' => $invoice])->set('selectedMethod', 'credit');
        if ($race !== null) {
            $fired = false;
            User::retrieved(function () use (&$fired, $race) {
                if ($fired) {
                    return;
                }
                $inRecord = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
                    ->contains(fn ($frame) => ($frame['class'] ?? null) === RecordPaymentService::class);
                if ($inRecord) {
                    $fired = true;
                    $race();
                }
            });
        }
        config(['settings.credits_enabled' => true]); // the settings provider may reload it on the first request
        $component->call('processPayment');
    }

    // ------------------------------------------------------------------ credits (Livewire / cron)

    /** Credit 5, invoice 10; staff record 7.00 meanwhile: 3 is taken from the credit, 2.00 stays. */
    public function test_partial_credit_payment_racing_a_staff_transaction_keeps_the_excess_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(5.00);

        $this->payWithCreditsInBrowser($invoice, fn () => Invoice::query()->find($invoice->id)->transactions()->create(['amount' => 7.00, 'status' => InvoiceTransactionStatus::Succeeded]));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2.00', $this->credit());
    }

    /** Same, but staff cancel the invoice meanwhile: no credit is spent. */
    public function test_partial_credit_payment_racing_a_cancellation_keeps_the_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(5.00);

        $this->payWithCreditsInBrowser($invoice, fn () => Invoice::query()->find($invoice->id)->update(['status' => 'cancelled']));

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('5.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_full_credit_payment_racing_a_cancellation_returns_the_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(25.00);

        $this->payWithCreditsInBrowser($invoice, fn () => Invoice::query()->find($invoice->id)->update(['status' => 'cancelled']));

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('25.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_full_credit_payment_racing_a_staff_transaction_returns_the_excess(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(25.00);

        $this->payWithCreditsInBrowser($invoice, fn () => Invoice::query()->find($invoice->id)->transactions()->create(['amount' => 4.00, 'status' => InvoiceTransactionStatus::Succeeded]));

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('19.00', $this->credit()); // 25 - 6 still owed
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_full_credit_payment_in_the_browser_pays_and_renews_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(25.00);

        $this->payWithCreditsInBrowser($invoice);
        $this->payWithCreditsInBrowser($invoice->fresh()); // a second click: invoice already paid

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('15.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_partial_credit_then_card_pays_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->setCredit(4.00);

        $this->payWithCreditsInBrowser($invoice);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 6.00, null, 'pi_rest', currency: 'usd');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_cron_credit_auto_use_pays_and_renews_once_over_two_runs(): void
    {
        $service = $this->service();
        $this->setCredit(25.00);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoices = Invoice::query()->where('user_id', $this->user->id)->get();
        $this->assertCount(1, $invoices);
        $this->assertSame('paid', $invoices->first()->status);
        $this->assertSame('15.00', $this->credit());
        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_cron_credit_in_another_currency_is_not_spent(): void
    {
        $service = $this->service();
        Credit::query()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'amount' => 25.00]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame('25.00', $this->credit('EUR'));
        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    // ------------------------------------------------------------------ never both / never lost

    /** The excess of an overpayment is credit only: it does not also show on the invoice as overpaid. */
    public function test_an_overpayment_is_not_counted_on_the_invoice_and_in_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 15.00, null, 'pi_1', currency: 'usd');

        $this->assertSame('5.00', $this->credit());
        $this->assertEquals(0.0, $invoice->fresh()->remaining);
        $this->assertEquals(10.00, (float) InvoiceTransaction::query()->sole()->refundable_amount);
    }

    /** A late payment turned credit does not also pay the invoice when staff reopen it. */
    public function test_a_late_payment_turned_credit_does_not_also_pay_the_reopened_invoice(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'cancelled']); // as CronJob / CancellationCreatedListener do

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_late', currency: 'usd');
        $this->assertSame('10.00', $this->credit());

        $invoice->fresh()->update(['status' => 'pending']); // staff reopen
        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10.00, null, 'pi_declined', currency: 'usd');

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('10.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_a_payment_on_a_mass_cancelled_invoice_becomes_credit_only(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        Invoice::query()->whereKey($invoice->id)->update(['status' => 'cancelled']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_late', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_late', currency: 'usd'); // replay

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('10.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_partial_payment_then_cancel_then_rest_becomes_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_1', currency: 'usd');
        $invoice->fresh()->update(['status' => 'cancelled']);

        ExtensionHelper::addPayment($invoice->id, 'PayPal', 6.00, null, 'cap_2', currency: 'USD');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('6.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    // ------------------------------------------------------------------ replays / status machine

    public function test_processing_then_succeeded_routed_to_another_invoice_lands_once_on_the_first(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $november = $this->invoiceFor($service, '2026-11-15', '2026-12-15');

        ExtensionHelper::addProcessingPayment($october->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($november->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($november->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('paid', $october->fresh()->status);
        $this->assertSame('pending', $november->fresh()->status);
        $this->assertSame('0.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    /** The currency is checked against the invoice the money lands on (where the transaction was started). */
    public function test_a_success_in_another_currency_than_the_invoice_it_lands_on_writes_nothing(): void
    {
        $service = $this->service();
        $eur = $this->invoiceFor($service, '2026-10-15', '2026-11-15', currency: 'EUR');
        $usd = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        ExtensionHelper::addProcessingPayment($eur->id, 'Stripe', 10.00, null, 'pi_x', currency: 'eur');

        ExtensionHelper::addPayment($usd->id, 'Stripe', 10.00, null, 'pi_x', currency: 'usd');

        $this->assertSame(InvoiceTransactionStatus::Processing, InvoiceTransaction::query()->sole()->status);
        $this->assertSame('pending', $eur->fresh()->status);
    }

    public function test_failed_then_succeeded_is_one_payment_and_late_failed_is_ignored(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, InvoiceTransaction::query()->sole()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->credit());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_processing_on_an_invoice_cancelled_before_success_becomes_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        $invoice->fresh()->update(['status' => 'cancelled']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('10.00', $this->credit());
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    public function test_processing_amount_lower_than_the_success_credits_the_difference(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 12.00, null, 'pi_1', currency: 'usd');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2.00', $this->credit());
    }

    public function test_a_replay_to_a_cancelled_invoice_of_the_customer_changes_nothing(): void
    {
        $service = $this->service();
        $october = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $old = $this->invoiceFor($service, '2026-09-15', '2026-10-15');
        $old->update(['status' => 'cancelled']);
        ExtensionHelper::addPayment($october->id, 'PayPal', 10.00, 0.50, 'SALE-1', currency: 'USD');

        ExtensionHelper::addPayment($old->id, 'PayPal', 10.00, 0.50, 'SALE-1', currency: 'USD');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
        $this->assertSame('cancelled', $old->fresh()->status);
    }

    public function test_a_replay_with_an_unknown_gateway_name_is_still_recorded_once(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        ExtensionHelper::addPayment($invoice->id, 'NotInstalled', 10.00, null, 'tx_1');
        ExtensionHelper::addPayment($invoice->id, 'NotInstalled', 10.00, null, 'tx_1');

        $this->assertSame(1, InvoiceTransaction::query()->count());
        $this->assertSame('0.00', $this->credit());
    }

    // ------------------------------------------------------------------ amount / currency validation

    public function test_odd_amounts_write_nothing(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');

        foreach (['', null, '10,00', 'NaN', 'INF', '1e400', '0.004', '-0.01', '0x0A', false] as $i => $amount) {
            $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', $amount, null, 'pi_odd_' . $i), var_export($amount, true));
        }

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->credit());
    }

    public function test_a_wrong_currency_on_a_processing_transaction_leaves_it_processing(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'usd');

        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: 'eur'));
        $this->assertNull(ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_1', currency: ''));

        $this->assertSame(InvoiceTransactionStatus::Processing, InvoiceTransaction::query()->sole()->status);
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->credit());
    }

    // ------------------------------------------------------------------ staff paths

    public function test_staff_transaction_edits_never_pay_a_cancelled_invoice(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);

        $transaction = $invoice->fresh()->transactions()->create(['amount' => 0, 'transaction_id' => 'manual-1', 'status' => InvoiceTransactionStatus::Processing]);
        $transaction->update(['status' => InvoiceTransactionStatus::Succeeded, 'amount' => 10.00]);
        $transaction->update(['fee' => 0.30]);
        ExtensionHelper::addPaymentFee($transaction->transaction_id, 0.30);

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
    }

    /** Staff record money wired for a cancelled invoice through the admin «New transaction»: it becomes credit. */
    public function test_a_staff_added_transaction_on_a_cancelled_invoice_becomes_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $invoice->update(['status' => 'cancelled']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        Livewire::actingAs($admin)
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('create', data: ['amount' => '10.00', 'transaction_id' => 'wire-1'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->transactions()->count());
        $this->assertSame('10.00', $this->credit());
    }

    // ------------------------------------------------------------------ credit-deposit invoices

    public function test_a_credit_deposit_overpaid_and_replayed_credits_exactly_what_arrived(): void
    {
        $invoice = $this->creditInvoice(50.00);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 60.00, null, 'pi_dep', currency: 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 60.00, null, 'pi_dep', currency: 'usd');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('60.00', $this->credit());
    }

    public function test_a_credit_deposit_paid_after_cancellation_credits_once(): void
    {
        $invoice = $this->creditInvoice(50.00);
        $invoice->update(['status' => 'cancelled']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, null, 'pi_dep', currency: 'usd');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->credit());
    }
}

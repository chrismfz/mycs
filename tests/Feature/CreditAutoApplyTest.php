<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * MT-1e (docs/money-trail.md, decision 5): account credit pays open invoices on its own, also in part, oldest due
 * first: when a renewal invoice is made, when credit comes in from an overpayment, and once a day before suspensions.
 * Never a credit purchase, an invoice with a payment in progress, a service with a gateway subscription, or a customer
 * who opted out.
 */
class CreditAutoApplyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, string $due = '+7 days', ?string $reference = null, ?int $referenceId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD', 'due_at' => now()->modify($due)]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price,
            'reference_type' => $reference, 'reference_id' => $referenceId], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function giveCredit(int $cents): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', $cents, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    public function test_credit_pays_part_of_an_invoice_and_the_customer_is_told(): void
    {
        $invoice = $this->invoice(100);
        $this->giveCredit(3000);

        $transaction = (new CreditAutoApply)->toInvoice($invoice);

        $this->assertSame('30.00', $transaction->amount);
        $this->assertSame(70.0, $invoice->fresh()->remaining);
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
        $this->assertSame('0.00', $this->balance());
        $note = Notification::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame('Account credit applied', $note->title);
        $this->assertStringContainsString('#' . $invoice->id, $note->body);
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_credit_that_covers_the_invoice_pays_it(): void
    {
        $invoice = $this->invoice(40);
        $this->giveCredit(10000);

        (new CreditAutoApply)->toInvoice($invoice);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('60.00', $this->balance());
        $this->assertSame(0, Notification::query()->where('title', 'Account credit applied')->count(), '«Invoice paid» tells the customer');
    }

    public function test_the_customer_pays_oldest_due_first_while_the_credit_lasts(): void
    {
        $newer = $this->invoice(50, '+10 days');
        $older = $this->invoice(50, '+2 days');
        $this->giveCredit(7000);

        $this->assertSame(2, (new CreditAutoApply)->forCustomer($this->user->id, 'USD'));

        $this->assertSame(Invoice::STATUS_PAID, $older->fresh()->status);
        $this->assertSame(30.0, $newer->fresh()->remaining);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_nothing_is_paid_on_a_credit_purchase_a_processing_payment_a_subscription_or_an_opt_out(): void
    {
        $this->giveCredit(100000);
        $purchase = $this->invoice(50, reference: Credit::class);
        $processing = $this->invoice(50);
        (new RecordPaymentService)->handle($processing, Gateway::query()->sole(), '50.00', transactionId: 'pi_sepa', status: InvoiceTransactionStatus::Processing);
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 50, 'subscription_id' => 'I-SUB1']);
        $subscription = $this->invoice(50, reference: Service::class, referenceId: $service->id);

        $this->assertSame(0, (new CreditAutoApply)->forCustomer($this->user->id, 'USD'));

        $this->user->forceFill(['credit_auto_apply' => false])->save();
        $plain = $this->invoice(50);
        $this->assertNull((new CreditAutoApply)->toInvoice($plain));

        foreach ([$purchase, $processing, $subscription, $plain] as $invoice) {
            $this->assertSame(0, $invoice->transactions()->where('is_credit_transaction', true)->count());
        }
        $this->assertSame('1000.00', $this->balance());
    }

    public function test_nothing_is_paid_when_the_setting_is_off(): void
    {
        config(['settings.credits_auto_use' => false]);
        $invoice = $this->invoice(100);
        $this->giveCredit(10000);

        $this->assertNull((new CreditAutoApply)->toInvoice($invoice));
        $this->assertSame(0, (new CreditAutoApply)->sweep());
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->fresh()->status);
    }

    public function test_an_overpayment_pays_the_customers_other_open_invoices(): void
    {
        $paid = $this->invoice(100);
        $other = $this->invoice(30, '+1 day');

        ExtensionHelper::addPayment($paid->id, 'Stripe', 150.00, null, 'pi_over', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status);
        $this->assertSame('20.00', $this->balance());
        $this->assertSame(0, Artisan::call('mycs:credits-check'));
    }

    public function test_a_subscription_sales_overpayment_is_left_for_the_subscription(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 50, 'subscription_id' => 'I-SUB2']);
        $sale = $this->invoice(50, reference: Service::class, referenceId: $service->id);
        $other = $this->invoice(30, '+1 day');

        ExtensionHelper::addPayment($sale->id, 'Stripe', 80.00, null, 'pi_sub', currency: 'USD');

        $this->assertSame(Invoice::STATUS_PENDING, $other->fresh()->status, 'the sale\'s credit was spent elsewhere');
        $this->assertSame('30.00', $this->balance());
    }

    public function test_the_daily_pass_pays_before_anything_is_suspended(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->subDays(5)]);
        $overdue = $this->invoice(10, '-5 days', Service::class, $service->id);
        $overdue->items()->sole()->update(['period_start' => now()->subDays(5)->toDateString(), 'period_end' => now()->addDays(25)->toDateString()]);
        $this->giveCredit(1000);
        config(['settings.cronjob_order_suspend' => 2]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Invoice::STATUS_PAID, $overdue->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_the_renewal_the_cron_makes_takes_what_credit_covers(): void
    {
        $product = $this->createProduct();
        Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addDays(2)]);
        $this->giveCredit(400);
        config(['settings.cronjob_invoice' => 7]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $invoice = Invoice::query()->where('user_id', $this->user->id)->sole();
        $this->assertSame(6.0, $invoice->remaining);
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(1, InvoiceTransaction::query()->where('is_credit_transaction', true)->count());
    }

    public function test_the_customer_switches_it_off_on_the_credits_page(): void
    {
        $this->actingAs($this->user);

        \Livewire\Livewire::test(\App\Livewire\Client\Credits::class)->set('autoApply', false);

        $this->assertFalse($this->user->fresh()->credit_auto_apply);
    }
}

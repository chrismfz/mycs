<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use Tests\TestCase;

/** Code review of PR 2a (feat/payment-guards, docs/payments.md): the defects it found, now fixed, and a money-conservation check. */
class PaymentGuardsCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
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
            'client_id' => $this->user->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, string $start, string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    /**
     * Show::payWithCredit, partial branch: addPayment() runs BEFORE the in-memory $credit is zeroed and saved.
     * When the invoice cannot take the credit (RecordPaymentService::excessCents: not pending/draft at lock time),
     * the service writes the amount back to the credit row, and the stale `$credit->amount = 0; save()` then
     * overwrites it: the customer's credit vanishes. Here staff mark the invoice paid (remaining still > 0)
     * between the request's hydration (status check) and the credit row read under the customer lock.
     */
    public function test_partial_credit_payment_refused_by_the_invoice_keeps_the_credit(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->user->client()->credits()->create(['currency_code' => 'USD', 'amount' => 5.00]);

        // The Livewire request reloads settings from the database: persist the switch, not just config().
        Setting::updateOrCreate(['key' => 'credits_enabled'], ['value' => true]);
        Cache::flush();

        $component = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit');

        // The concurrent staff action lands before the payment takes its locks (after, it would wait for them).
        $fired = false;
        Client::retrieved(function () use (&$fired, $invoice) {
            $inRecord = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
                ->contains(fn ($frame) => ($frame['class'] ?? null) === RecordPaymentService::class);
            if ($fired || !$inRecord) {
                return;
            }
            $fired = true;
            Invoice::query()->whereKey($invoice->id)->update(['status' => 'paid']); // concurrent staff action
        });

        $component->call('processPayment');

        $this->assertTrue($fired);
        // The invoice can no longer take credit: nothing is spent.
        $this->assertSame(0, InvoiceTransaction::query()->where('is_credit_transaction', true)->count());
        $this->assertSame('5.00', $this->credit(), 'credit lost');
    }

    /**
     * PayPal: one subscription covers every service of the order (subscription price = sum of the services,
     * PayPal::updateSubscription). PAYMENT.SALE.COMPLETED loops over the services and calls addPayment with the
     * SAME sale id on each service's latest invoice. The customer-wide idempotency now makes every call after
     * the first a no-op: the first invoice takes all the money (rest -> credit), the other services' invoices
     * stay pending and get suspended although the customer paid.
     */
    public function test_a_paypal_subscription_sale_for_two_services_pays_both_invoices(): void
    {
        $a = $this->service(['subscription_id' => 'I-SUB']);
        $b = $this->service(['subscription_id' => 'I-SUB']);
        $invoiceA = $this->invoiceFor($a, '2026-10-15', '2026-11-15');
        $invoiceB = $this->invoiceFor($b, '2026-10-15', '2026-11-15');

        // The gateway's own PAYMENT.SALE.COMPLETED handling, then a replay of the same webhook.
        $resource = ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB', 'amount' => ['total' => '20.00', 'currency' => 'USD'], 'transaction_fee' => ['value' => '1.00']];
        $paypal = new PayPal([]);
        $paypal->recordSubscriptionSale($resource);
        $paypal->recordSubscriptionSale($resource);

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-1')->count());
        $this->assertSame('0.00', $this->credit(), 'the sale is spent on the subscription\'s invoices, not left as credit');
        $this->assertSame('paid', $invoiceA->fresh()->status);
        $this->assertSame('paid', $invoiceB->fresh()->status, 'service B paid by the subscription but its invoice stays pending');
        $this->assertSame('2026-11-15', $a->fresh()->expires_at->toDateString());
        $this->assertSame('2026-11-15', $b->fresh()->expires_at->toDateString());
    }

    /** Holds: money conservation for gateway payments (sanity; partial + over + cancelled + replay). */
    public function test_money_is_conserved_across_partial_over_and_cancelled_payments(): void
    {
        $service = $this->service();
        $paid = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $cancelled = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $cancelled->update(['status' => 'cancelled']);

        ExtensionHelper::addProcessingPayment($paid->id, 'Stripe', 6.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($paid->id, 'Stripe', 6.00, null, 'pi_1', currency: 'usd');
        ExtensionHelper::addPayment($paid->id, 'PayPal', 7.00, null, 'cap_2'); // 4 needed, 3 excess
        ExtensionHelper::addPayment($paid->id, 'PayPal', 7.00, null, 'cap_2'); // replay
        ExtensionHelper::addPayment($cancelled->id, 'Stripe', 10.00, null, 'pi_3'); // all excess

        $in = (float) InvoiceTransaction::query()->sum('amount');
        $this->assertEqualsWithDelta(23.00, $in, 0.001);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('13.00', $this->credit()); // 23 in = 10 taken by the invoice + 3 + 10 credit
        $this->assertEquals(0.0, $paid->fresh()->remaining); // the 3.00 excess counts as credit only
        $this->assertEquals(0.0, $cancelled->fresh()->remaining);
    }
}

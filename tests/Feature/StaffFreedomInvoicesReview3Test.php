<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Third check of #780 (head f1d826f2): the cut rule and «a raise is staff's word». Invented fixtures only. */
class StaffFreedomInvoicesReview3Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-03-01 10:00:00');
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        foreach (['credits_enabled', 'credits_on_downgrade'] as $key) {
            Setting::query()->whereNull('settingable_type')->updateOrCreate(['key' => $key], ['value' => '1', 'type' => 'boolean']);
        }
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $price): Product
    {
        $product = Product::factory()->create(['name' => 'P' . $price, 'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => '0.00', 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** @return array{0: Service, 1: Invoice} */
    private function serviceWithOpenRenewal(Product $product): array
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'status' => 'active', 'price' => '10.00', 'quantity' => 1, 'currency_code' => 'USD', 'expires_at' => '2026-03-01']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-03-01']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'taxed' => false, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-03-01', 'period_end' => '2026-04-01']);

        return [$service->fresh(), $invoice->fresh()];
    }

    private function token(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'rev', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    private function credit(): string
    {
        return number_format((float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'), 2, '.', '');
    }

    /**
     * REV-780-3-1. «A raise that makes a paid invoice owe is staff's word» sets paid_by_staff_at on an invoice that was
     * paid with real money (10.00, raised to 12.00: the word is for 2.00). deleteTransaction keeps any invoice with that
     * flag paid (REV-780-6), so when its 10.00 payment is deleted (a bounced transfer) it stays PAID with nothing on it,
     * owing 12.00, never due again. On main, and before the raise, the invoice follows its payment and is open again.
     */
    public function test_rev_780_3_1_a_paid_invoice_raised_by_2_is_open_again_when_its_payment_is_deleted(): void
    {
        [, $invoice] = $this->serviceWithOpenRenewal($this->product('10.00'));
        $payment = (new RecordPaymentService)->handle($invoice, null, '10.00', transactionId: 'wire-31', enteredByStaff: true);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->withToken($this->token(['admin.invoice_items.update', 'admin.invoice_transactions.create']))
            ->putJson(route('api.v1.admin.invoice-items.update', $invoice->items()->sole()), ['price' => '12.00'])->assertSuccessful();
        $this->assertSame([Invoice::STATUS_PAID, 200], [$invoice->fresh()->status, RecordPaymentService::toCents($invoice->fresh()->remaining)]);

        (new RecordPaymentService)->deleteTransaction($payment->fresh());

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'its only payment is gone: staff\'s word was for the 2.00 of the raise, not for the 10.00 that was paid');
    }
}

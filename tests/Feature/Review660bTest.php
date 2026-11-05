<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Second independent review of #660 (WG-14, cronjob_invoice_monthly). Fake data only.
 */
class Review660bTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function annualService(string $expires): Service
    {
        $product = $this->createProduct();
        $annual = Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => $product->product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $annual->id, 'price' => 100.00, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $annual->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 100.00, 'quantity' => 1]);
    }

    /**
     * R660b-1. The monthly value larger than Invoice Generation (30 vs 14). An annual renewal 20 days ahead, invoiced early by
     * staff («Invoice selected items»), cancelled «As usual» (nobody chose): the cron would bill it only 14 days before, so
     * it is a period to come, kept billable and billed at its time (ADV-4, D7a; as on main). The single cancel horizon is
     * now the longest of the days (30): the period is given up, never billed again, and the service lapses at expiry.
     */
    public function test_r660b_1_an_annual_period_invoiced_early_and_cancelled_is_billed_at_its_time_when_the_monthly_days_are_longer(): void
    {
        $this->storeSettings(['cronjob_invoice' => '14', 'cronjob_invoice_monthly' => '30']);
        $service = $this->annualService('2026-12-21');

        $result = (new InvoiceSelectedItems)->handle([$service], []);
        $this->assertCount(1, $result['invoices'], 'precondition: staff invoiced it early');
        $invoice = Invoice::query()->findOrFail($result['invoices'][0]);
        $this->assertTrue($invoice->billsOnlyPeriodsToCome(), 'an annual period 20 days ahead: the cron (14 days) has not reached it');

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'invoiced too early']);
        $line = InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', Service::class)->firstOrFail();
        $this->assertNull($line->forgone_at, 'a period to come stays billable');

        Carbon::setTestNow('2026-12-08 10:00:00'); // 13 days before: within Invoice Generation's 14
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(2, InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->count(),
            'the cron bills the annual period at its time');
    }
}

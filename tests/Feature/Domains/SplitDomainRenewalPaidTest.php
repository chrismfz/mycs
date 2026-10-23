<?php

namespace Tests\Feature\Domains;

use App\Jobs\Domains\RenewDomainJob;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Owner 2026-10-24: one invoice with a hosting and a domain; the customer pays only the domain, now, because it expires.
 * Staff split the domain's line off; its invoice paid, the domain is renewed (once) and the hosting waits on the first.
 */
class SplitDomainRenewalPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_domain_split_off_and_paid_is_renewed_and_the_hosting_waits(): void
    {
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $client = User::factory()->create()->clients()->firstOrFail();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 30, 'expires_at' => now()->addDays(10)]);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'expires_at' => now()->addDays(3), 'currency_code' => 'USD']);
        $invoice = Invoice::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'due_at' => now()->addDays(3), 'status' => Invoice::STATUS_UNPAID]);
        $hosting = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 30, 'quantity' => 1,
            'description' => 'Hosting', 'period_start' => $service->expires_at, 'period_end' => $service->expires_at->copy()->addMonth()]);
        $renewal = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => 12, 'quantity' => 1,
            'description' => 'Domain renewal', 'period_start' => $domain->expires_at, 'period_end' => $domain->expires_at->copy()->addYear()]);

        $new = (new SplitInvoice)->handle($invoice->id, [$renewal->id]);
        $this->assertSame($new->id, (int) $renewal->refresh()->invoice_id, 'the domain line moved, the same line');
        $this->assertSame($invoice->id, (int) $hosting->refresh()->invoice_id);

        (new RecordPaymentService)->handle($new->refresh(), null, (string) $new->total, transactionId: 'tx-domain-only');

        $this->assertSame(Invoice::STATUS_PAID, $new->refresh()->status);
        Queue::assertPushed(RenewDomainJob::class, fn (RenewDomainJob $job) => (int) $job->lineId === (int) $renewal->id);
        Queue::assertPushed(RenewDomainJob::class, 1);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->refresh()->status, 'the hosting still to pay');
        $this->assertNull($hosting->refresh()->applied_at, 'the hosting not renewed by the domain\'s payment');
    }
}

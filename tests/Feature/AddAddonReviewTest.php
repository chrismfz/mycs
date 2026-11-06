<?php

namespace Tests\Feature\Rv;

use App\Models\Client;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\AddAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AddAddonReviewTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires, string $unit = 'month'): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => $unit]);

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    private function addonProduct(string $unit = 'month'): array
    {
        $made = $this->createProduct();
        $made->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'allow_quantity' => 'combined']);
        $made->plan->update(['billing_unit' => $unit]);
        Price::query()->where('plan_id', $made->plan->id)->update(['price' => 3.00]);

        return [$made->product->fresh(), $made->plan->fresh()];
    }

    public function test_aligned_end_of_a_31st_yearly_parent(): void
    {
        $parent = $this->parent('2027-03-31', 'year');
        $parent->forceFill(['billing_day' => 31])->save();
        [, $plan] = $this->addonProduct();
        $this->assertSame('2026-11-30', AddAddon::alignedEnd($parent, $plan)->toDateString());
    }

    public function test_billing_day_kept_after_payment(): void
    {
        $parent = $this->parent('2026-11-30');
        $parent->forceFill(['billing_day' => 31])->save();
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::PRORATED);
        $this->assertSame(31, (int) $made['service']->fresh()->billing_day);
        (new RecordPaymentService)->handle($made['invoice']->fresh(), null, $made['invoice']->fresh()->remaining, transactionId: 'x1');
        $addon = $made['service']->fresh();
        $this->assertSame('2026-11-30', $addon->expires_at->toDateString());
        $billing = new RenewalBilling;
        $billing->billRenewal($parent->id, Carbon::parse('2026-12-31'));
        $billing->billRenewal($addon->id, Carbon::parse('2026-12-31'));
        $billing->finishRenewalInvoices();
        $p = $parent->invoiceItems()->orderByDesc('id')->first();
        $a = $addon->invoiceItems()->orderByDesc('id')->first();
        $this->assertSame([$p->period_start->toDateString(), $p->period_end->toDateString()],
            [$a->period_start->toDateString(), $a->period_end->toDateString()], 'billing_day after payment: ' . $addon->fresh()->billing_day);
    }

    public function test_three_decimals_price(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, '3.333', false, AddAddon::PRORATED);
        $this->assertNotNull($made['invoice']);
    }

    public function test_paid_late_after_parents_date(): void
    {
        config(['settings.cronjob_order_suspend' => 8]);
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, $plan, 1, null, false, AddAddon::PRORATED);
        Carbon::setTestNow('2026-12-15 10:00:00');
        (new RecordPaymentService)->handle($made['invoice']->fresh(), null, $made['invoice']->fresh()->remaining, transactionId: 'x2');
        $addon = $made['service']->fresh();
        fwrite(STDERR, "\nlate: status={$addon->status} expires={$addon->expires_at?->toDateString()} hold={$addon->suspend_hold_until}\n");
        // parent paid on time
        $parent->forceFill(['expires_at' => '2027-01-01'])->saveQuietly();
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $addon->refresh();
        fwrite(STDERR, "after cron: status={$addon->status} reason={$addon->suspend_reason} invoices=" . $addon->invoices()->count() . "\n");
        $this->assertNotSame(Service::STATUS_SUSPENDED, $addon->status, 'suspended the night it was paid and started');
    }
}

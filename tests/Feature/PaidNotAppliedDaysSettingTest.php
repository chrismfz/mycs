<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The days a service on a paid invoice not applied yet (BIL-9) is protected: a setting now (Settings → Cron, «Paid but
 * not applied: protected days»), 30 by default, 1 to 365.
 */
class PaidNotAppliedDaysSettingTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->subDay()]);
        $invoice = Invoice::factory()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $this->service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        // Paid ten days ago, its line never applied.
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_PAID, 'apply_pending_since' => now()->subDays(10)]);
    }

    private function protectedNow(): bool
    {
        return Service::query()->whereKey($this->service->id)->whereExists(fn ($line) => RenewalBilling::paidLineToApply($line))->exists();
    }

    public function test_the_protection_lasts_as_many_days_as_the_setting(): void
    {
        $this->assertSame(30, RenewalBilling::paidNotAppliedDays());
        $this->assertTrue($this->protectedNow(), 'ten days in, under the default 30');

        config(['settings.cronjob_paid_not_applied_days' => 7]);
        $this->assertFalse($this->protectedNow(), 'past 7');

        config(['settings.cronjob_paid_not_applied_days' => 0]);
        $this->assertSame(1, RenewalBilling::paidNotAppliedDays(), 'never less than a day');
        config(['settings.cronjob_paid_not_applied_days' => 9999]);
        $this->assertSame(365, RenewalBilling::paidNotAppliedDays());
    }
}

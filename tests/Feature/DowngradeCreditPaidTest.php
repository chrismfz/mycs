<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A downgrade gives back what is left of what was paid, never the renewal price that was not (adversarial P-1). */
class DowngradeCreditPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true]);
        $this->user = User::factory()->create();
    }

    /** A service at 10 a month whose current period was paid `$paid`, downgraded to 2: the credit it gets. */
    private function downgradeCredit(float $paid): float
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        Price::query()->where('plan_id', $to->plan->id)->update(['price' => 2.00]);
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'First month', 'price' => $paid, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => now()->subDays(10)->toDateString(), 'period_end' => now()->addDays(20)->toDateString()]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service])
            ->set('upgrade', $to->product->id)
            ->call('doUpgrade');

        return (float) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount');
    }

    public function test_a_first_month_free_gives_no_credit_on_a_downgrade(): void
    {
        $this->assertSame(0.0, $this->downgradeCredit(0.00));
    }

    public function test_a_first_month_at_half_gives_back_at_most_what_is_left_of_it(): void
    {
        $credit = $this->downgradeCredit(5.00);

        $this->assertGreaterThan(0.0, $credit);
        $this->assertLessThanOrEqual(round(5.00 * 20 / 30, 2) + 0.01, $credit, 'more than what is left of the 5.00 paid');
    }

    public function test_a_period_paid_in_full_still_gives_its_difference(): void
    {
        $credit = $this->downgradeCredit(10.00);

        $this->assertGreaterThan(round(5.00 * 20 / 30, 2), $credit);
    }
}

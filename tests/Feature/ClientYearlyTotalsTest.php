<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\ClientTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A customer's totals by year on its Summary (the owner, 2026-10-21): invoiced, paid, open, net, and all time.
 */
class ClientYearlyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $created, float $net, string $status, ?string $paidAt = null): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => $net, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $paidAt, 'created_at' => $created]);
    }

    public function test_by_year_and_all_time_net(): void
    {
        $this->invoice('2026-02-01 10:00:00', 100.00, Invoice::STATUS_PAID, '2026-02-03 10:00:00');
        $this->invoice('2026-09-01 10:00:00', 40.00, Invoice::STATUS_UNPAID);
        $this->invoice('2025-12-20 10:00:00', 60.00, Invoice::STATUS_PAID, '2026-01-05 10:00:00'); // invoiced 2025, paid 2026
        $this->invoice('2025-03-01 10:00:00', 999.00, Invoice::STATUS_CANCELLED);

        $totals = ClientTotals::for($this->user->client())['EUR'];

        $this->assertSame([2026, '140.00', '160.00', '40.00'], array_values($totals['years'][0]));
        $this->assertSame([2025, '60.00', '0.00', '0.00'], array_values($totals['years'][1]));
        $this->assertSame(['invoiced' => '200.00', 'paid' => '160.00', 'open' => '40.00'], $totals['total']);
    }

    public function test_on_the_summary_for_staff_who_may_see_invoices_only(): void
    {
        $this->invoice('2026-02-01 10:00:00', 100.00, Invoice::STATUS_PAID, '2026-02-03 10:00:00');
        $url = ClientResource::getUrl('view', ['record' => $this->user->client()]);

        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.invoices.viewAny'])->create())
            ->get($url)->assertOk()->assertSee(__('Totals by year'));
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())
            ->get($url)->assertOk()->assertDontSee(__('Totals by year'));
    }
}

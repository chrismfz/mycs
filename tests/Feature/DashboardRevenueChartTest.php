<?php

namespace Tests\Feature;

use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Revenue;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Order;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** B2's chart (owner 2026-11-04): the new orders as bars, revenue and net revenue as lines, the totals under it. */
class DashboardRevenueChartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.default_currency' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $this->user = User::factory()->create();
        $this->travelTo('2026-11-04 12:00:00');
    }

    private function payment(string $currency, string $amount, string $fee = '0', InvoiceTransactionStatus $status = InvoiceTransactionStatus::Succeeded, bool $credit = false): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'currency_code' => $currency]);
        InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'fee' => $fee,
            'transaction_id' => 'tr-' . $invoice->id, 'status' => $status, 'is_credit_transaction' => $credit]);
    }

    /** @return array<string, mixed> */
    private function data(Revenue $widget): array
    {
        return (fn () => $this->getData())->call($widget);
    }

    public function test_orders_are_bars_on_the_left_and_the_amounts_lines_on_the_right(): void
    {
        $data = $this->data(new Revenue);
        [$orders, $revenue, $net] = $data['datasets'];

        $this->assertSame(['bar', 'orders'], [$orders['type'], $orders['yAxisID']]);
        $this->assertArrayNotHasKey('type', $revenue);
        $this->assertArrayNotHasKey('yAxisID', $net);
        $this->assertSame(['New orders', 'Revenue', 'Net revenue'], array_column($data['datasets'], 'label'));
        // The bars drawn behind the lines.
        $this->assertGreaterThan($revenue['order'], $orders['order']);

        $scales = (fn () => $this->getOptions())->call(new Revenue)['scales'];
        $this->assertSame(['left', 0], [$scales['orders']['position'], $scales['orders']['ticks']['precision']]);
        $this->assertSame('right', $scales['y']['position']);
        $this->assertSame('line', (fn () => $this->getType())->call(new Revenue));
    }

    public function test_the_totals_under_the_chart_count_the_period_in_the_default_currency_only(): void
    {
        foreach (range(1, 3) as $i) {
            Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        }
        $this->payment('EUR', '100.00', '3.00');
        $this->payment('EUR', '20.50');
        $this->payment('USD', '500.00');                                          // another currency: not added to euros
        $this->payment('EUR', '40.00', status: InvoiceTransactionStatus::Failed); // not money received
        $this->payment('EUR', '60.00', credit: true);                             // paid from credit, already counted
        $this->travelBack();
        $this->travelTo('2026-09-01 12:00:00');
        $this->payment('EUR', '900.00');                                          // before the 30 days
        Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'EUR']);
        $this->travelBack();
        $this->travelTo('2026-11-04 12:00:00');

        $data = $this->data(new Revenue);
        $this->assertSame(3, (int) array_sum($data['datasets'][0]['data']));
        $this->assertEqualsWithDelta(120.50, array_sum($data['datasets'][1]['data']), 0.001);
        $this->assertEqualsWithDelta(117.50, array_sum($data['datasets'][2]['data']), 0.001);

        Livewire::test(Revenue::class)->assertSee('New orders 3 · Revenue €120,50 · Net revenue €117,50');
    }

    public function test_the_revenue_card_adds_up_the_same_payments_as_the_chart(): void
    {
        $this->payment('EUR', '100.00');
        $this->payment('USD', '500.00');

        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());
        $revenue = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Revenue');

        $this->assertSame('€100,00', (string) $revenue->getValue());
    }

    public function test_the_totals_follow_the_period(): void
    {
        $this->payment('EUR', '10.00');
        $this->travelTo('2026-10-20 12:00:00');
        $this->payment('EUR', '5.00');
        $this->travelTo('2026-11-04 12:00:00');

        Livewire::test(Revenue::class)->assertSee('Revenue €15,00')
            ->set('filter', 'week')->assertSee('Revenue €10,00')->assertDontSee('Revenue €15,00');
    }

    public function test_the_chart_is_in_greek_for_greek_staff(): void
    {
        app()->setLocale('el');

        Livewire::test(Revenue::class)->assertSee('Έσοδα')->assertSee('Νέες παραγγελίες 0 · Έσοδα €0,00 · Καθαρά έσοδα €0,00');
        $this->assertSame(['Νέες παραγγελίες', 'Έσοδα', 'Καθαρά έσοδα'], array_column($this->data(new Revenue)['datasets'], 'label'));
    }
}

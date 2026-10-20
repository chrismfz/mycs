<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource;
use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\RecordsToCheck;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Admin\Widgets\Renewals\ServicesToCheck;
use App\Admin\Widgets\Revenue;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin's dates and dashboard, polished (owner 2026-10-21). */
class AdminDashboardPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    public function test_a_list_writes_its_dates_as_in_greece(): void
    {
        $this->travelTo('2026-10-18 21:01:40');
        Order::query()->create(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'EUR']);
        $staff = auth('staff')->user();

        // A real request: the formats are set when the panel boots, as for every page and Livewire update of it.
        $this->withSession($this->loginUser($staff))->get(OrderResource::getUrl())->assertOk()
            ->assertSee('18/10/2026 21:01')->assertDontSee('2026-10-18 21:01:40')->assertDontSee('Oct 18, 2026');
    }

    public function test_no_change_is_said_so_in_gray_and_revenue_has_its_currency(): void
    {
        Livewire::test(Overview::class)->assertSee(__('No change'))->assertDontSee('Increased by 0.00%')->assertSee('€0,00');
    }

    public function test_a_list_of_problems_is_on_the_dashboard_only_while_there_is_one(): void
    {
        $this->assertFalse(ServicesToCheck::canView());
    }

    /** WHMCS's five cards, in its order (owner 2026-10-21), the two queues there at zero too. */
    public function test_five_cards_pending_orders_first_module_actions_last(): void
    {
        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());

        $this->assertSame(['Pending Orders', 'Revenue', 'Tickets', 'Services', 'Pending Module Actions'], array_map(fn ($stat) => $stat->getLabel(), array_values($stats)));
        $this->assertSame('gray', $stats[0]->getColor());
    }

    /** The chart at about half its height; the four lists two by two. */
    public function test_a_shorter_chart_and_the_lists_side_by_side(): void
    {
        $this->assertSame('160px', (fn () => $this->maxHeight)->call(new Revenue));
        foreach ([DomainRenewals::class, ServiceRenewals::class,
            RecordsToCheck::class, ServicesToCheck::class] as $widget) {
            $this->assertSame(['default' => 'full', 'lg' => 1], (fn () => $this->columnSpan)->call(new $widget), $widget);
        }
    }
}

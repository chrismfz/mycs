<?php

namespace Tests\Feature;

use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** B2's tiles and renewals (owner 2026-11-04): shorter cards with the empty ones dimmed, denser renewals. */
class DashboardTilesRenewalsTest extends TestCase
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

    /** @return array<string, Stat> */
    private function stats(): array
    {
        $stats = (fn () => $this->getStats())->call(Livewire::test(Overview::class)->instance());

        return collect($stats)->keyBy(fn (Stat $stat) => (string) $stat->getLabel())->all();
    }

    private function quiet(Stat $stat): bool
    {
        return str_contains((string) $stat->getExtraAttributeBag()->get('class'), 'mycs-stat-quiet');
    }

    public function test_a_card_with_nothing_in_it_is_dimmed_and_one_with_a_number_is_not(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $user = User::factory()->create();
        Ticket::query()->create(['client_id' => $user->client()->id, 'subject' => 'Help', 'status' => 'open', 'priority' => 'low']);

        $stats = $this->stats();

        $this->assertFalse($this->quiet($stats['Tickets']), 'a new ticket in the period');
        foreach (['Pending Orders', 'Revenue', 'Services', 'Clients with credit', 'Pending Cancellations', 'Pending Module Actions'] as $label) {
            $this->assertTrue($this->quiet($stats[$label]), $label);
        }
        Livewire::test(Overview::class)->assertSeeHtml('mycs-stat-quiet');
    }

    public function test_the_cards_are_greek_for_greek_staff(): void
    {
        app()->setLocale('el');

        $labels = array_keys($this->stats());

        $this->assertSame(['Παραγγελίες σε αναμονή', 'Έσοδα', 'Tickets', 'Υπηρεσίες', 'Πελάτες με πίστωση', 'Ακυρώσεις σε αναμονή', 'Εκκρεμείς ενέργειες modules'], $labels);
        Livewire::test(Overview::class)->assertSee('Καμία αλλαγή (τελευταίες 30 ημέρες)')->assertSee('Τίποτα σε αναμονή');
    }

    public function test_a_change_is_said_with_its_period(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        $this->travelTo('2026-09-20 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Before', 'status' => 'open', 'priority' => 'low']);
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Before 2', 'status' => 'open', 'priority' => 'low']);
        $this->travelTo('2026-11-04 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Now', 'status' => 'open', 'priority' => 'low']);

        $this->assertSame('Decreased by 50.0% (last 30 days)', $this->stats()['Tickets']->getDescription());
    }

    public function test_the_renewals_say_their_window_under_the_heading_without_a_row_of_active_filters(): void
    {
        foreach ([ServiceRenewals::class, DomainRenewals::class] as $widget) {
            Livewire::test($widget)->assertSee('Within 30 days')->assertDontSee('Active filters')
                ->set('tableFilters.within.value', '7')->assertSee('Within 7 days');
        }
    }

    /** Review of #640: a card with a number is not dimmed, nor one that dropped to nothing (that is news). */
    public function test_a_waiting_order_and_a_drop_to_nothing_are_not_dimmed(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        Order::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'status' => Order::STATUS_PENDING]);
        $this->travelTo('2026-09-20 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Before', 'status' => 'open', 'priority' => 'low']);
        $this->travelTo('2026-11-04 12:00:00');

        $stats = $this->stats();

        $this->assertFalse($this->quiet($stats['Pending Orders']));
        $this->assertSame('0', (string) $stats['Tickets']->getValue());
        $this->assertSame('Decreased by 100.0% (last 30 days)', $stats['Tickets']->getDescription());
        $this->assertFalse($this->quiet($stats['Tickets']), 'a drop to nothing');
    }

    /** Review of #640: the percentage as Greek writes it. */
    public function test_the_percentage_has_a_greek_decimal_comma(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        $this->travelTo('2026-09-20 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Before', 'status' => 'open', 'priority' => 'low']);
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Before 2', 'status' => 'open', 'priority' => 'low']);
        $this->travelTo('2026-11-04 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Now', 'status' => 'open', 'priority' => 'low']);
        app()->setLocale('el');

        $this->assertSame('Μείωση 50,0% (τελευταίες 30 ημέρες)', $this->stats()['Tickets']->getDescription());
    }

    /** Review of #640: the heading and the query read the window the same way, an odd value as 30 days. */
    public function test_an_odd_window_is_thirty_days_in_the_heading_and_the_query_alike(): void
    {
        $this->assertSame(7, DomainRenewals::windowDays('7'));
        foreach (['abc', '', null, '0', '45'] as $odd) {
            $this->assertSame(30, DomainRenewals::windowDays($odd), var_export($odd, true));
        }
        Livewire::test(ServiceRenewals::class)->set('tableFilters.within.value', 'abc')->assertSee('Within 30 days');
    }
}

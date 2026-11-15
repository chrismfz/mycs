<?php

namespace Tests\Feature;

use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
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

        $this->assertFalse($this->quiet($stats['Tickets to answer']), 'a ticket waiting on us');
        foreach (['Pending Orders', 'Revenue', 'New services', 'Clients with credit', 'Pending Cancellations', 'Pending Module Actions'] as $label) {
            $this->assertTrue($this->quiet($stats[$label]), $label);
        }
        Livewire::test(Overview::class)->assertSeeHtml('mycs-stat-quiet');
    }

    public function test_the_cards_are_greek_for_greek_staff(): void
    {
        app()->setLocale('el');

        $labels = array_keys($this->stats());

        $this->assertSame(['Παραγγελίες σε αναμονή', 'Έσοδα', 'Tickets προς απάντηση', 'Νέες υπηρεσίες', 'Πελάτες με πίστωση', 'Ακυρώσεις σε αναμονή', 'Εκκρεμείς ενέργειες modules'], $labels);
        Livewire::test(Overview::class)->assertSee('Καμία αλλαγή (τελευταίες 30 ημέρες)')->assertSee('Τίποτα σε αναμονή');
    }

    /**
     * Owner 2026-11-15: «11 Tickets» was the new ones of the period and read as 11 unanswered. The number is the ones
     * waiting on us (the «Awaiting us» tab and the menu's badge); the new ones of the period are said below.
     */
    public function test_the_tickets_card_counts_the_ones_waiting_on_us_and_says_the_new_ones_below(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        $this->travelTo('2026-09-20 12:00:00');
        Ticket::query()->create(['client_id' => $client->id, 'subject' => 'Old, waiting', 'status' => Ticket::STATUS_CUSTOMER_REPLY, 'priority' => 'low']);
        $this->travelTo('2026-11-04 12:00:00');
        foreach ([Ticket::STATUS_OPEN, Ticket::STATUS_ANSWERED, Ticket::STATUS_ON_HOLD, Ticket::STATUS_CLOSED] as $status) {
            Ticket::query()->create(['client_id' => $client->id, 'subject' => $status, 'status' => $status, 'priority' => 'low']);
        }

        $card = $this->stats()['Tickets to answer'];

        $this->assertSame('2', (string) $card->getValue(), 'open and customer reply, of any date');
        $this->assertSame('4 new (last 30 days)', $card->getDescription());
        $this->assertSame('warning', $card->getColor());
        $this->assertSame(4, array_sum($card->getChart()));
    }

    public function test_all_answered_is_zero_and_gray_though_new_ones_came(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        foreach (range(1, 3) as $i) {
            Ticket::query()->create(['client_id' => $client->id, 'subject' => "Done {$i}", 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'low']);
        }

        $card = $this->stats()['Tickets to answer'];

        $this->assertSame('0', (string) $card->getValue());
        $this->assertSame('gray', $card->getColor());
        $this->assertSame('3 new (last 30 days)', $card->getDescription());
        $this->assertFalse($this->quiet($card), 'new ones came: not dimmed');

        app()->setLocale('el');
        $this->assertSame('3 νέα (τελευταίες 30 ημέρες)', $this->stats()['Tickets προς απάντηση']->getDescription());
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
        Service::factory()->create(['client_id' => $client->id]);
        $this->travelTo('2026-11-04 12:00:00');

        $stats = $this->stats();

        $this->assertFalse($this->quiet($stats['Pending Orders']));
        $this->assertSame('0', (string) $stats['New services']->getValue());
        $this->assertSame('Decreased by 100.0% (last 30 days)', $stats['New services']->getDescription());
        $this->assertFalse($this->quiet($stats['New services']), 'a drop to nothing');
    }

    /** Review of #640: the percentage as Greek writes it. */
    public function test_the_percentage_has_a_greek_decimal_comma(): void
    {
        $this->travelTo('2026-11-04 12:00:00');
        $client = User::factory()->create()->client();
        $this->travelTo('2026-09-20 12:00:00');
        Service::factory()->count(2)->create(['client_id' => $client->id]);
        $this->travelTo('2026-11-04 12:00:00');
        Service::factory()->create(['client_id' => $client->id]);
        app()->setLocale('el');

        $this->assertSame('Μείωση 50,0% (τελευταίες 30 ημέρες)', $this->stats()['Νέες υπηρεσίες']->getDescription());
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

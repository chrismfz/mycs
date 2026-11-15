<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\DomainResource\Pages\ListDomains;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\ServiceResource\Pages\ListService;
use App\Admin\Resources\TicketResource\Pages\ListTickets;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-16: the lists' filters on the page as the tickets', and the ticket tabs' counts with their 0. */
class ListFiltersAndTicketTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    public function test_the_main_lists_show_their_filters_on_the_page_folded_not_in_a_popup(): void
    {
        foreach ([ListClients::class, ListService::class, ListDomains::class, ListInvoices::class] as $page) {
            $table = Livewire::test($page)->assertOk()->instance()->getTable();

            $this->assertSame(FiltersLayout::AboveContentCollapsible, $table->getFiltersLayout(), $page);
            $this->assertSame(2, $table->getFiltersFormColumns(), $page);
        }
    }

    public function test_the_ticket_tabs_count_their_tickets_zero_too_and_closed_none(): void
    {
        $client = User::factory()->create()->client();
        foreach ([Ticket::STATUS_OPEN, Ticket::STATUS_CUSTOMER_REPLY, Ticket::STATUS_ANSWERED, Ticket::STATUS_CLOSED, Ticket::STATUS_CLOSED] as $status) {
            Ticket::query()->create(['client_id' => $client->id, 'subject' => $status, 'status' => $status, 'priority' => 'low']);
        }

        $tabs = Livewire::test(ListTickets::class)->instance()->getTabs();

        $this->assertSame(['2', 'warning'], [$tabs['open']->getBadge(), $tabs['open']->getBadgeColor($tabs['open']->getBadge())]);
        $this->assertSame('1', $tabs['answered']->getBadge());
        $this->assertSame(['0', 'gray'], [$tabs['on_hold']->getBadge(), $tabs['on_hold']->getBadgeColor($tabs['on_hold']->getBadge())], 'a 0 is shown, in gray');
        $this->assertNull($tabs['closed']->getBadge());

        Ticket::query()->whereIn('status', Ticket::AWAITING_STAFF)->update(['status' => Ticket::STATUS_ANSWERED]);
        $tabs = Livewire::test(ListTickets::class)->instance()->getTabs();
        $this->assertSame(['0', 'gray'], [$tabs['open']->getBadge(), $tabs['open']->getBadgeColor($tabs['open']->getBadge())]);
    }
}

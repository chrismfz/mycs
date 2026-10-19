<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServicesToCheck;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin's density, closer to WHMCS (docs/admin-ux.md, «Πυκνότητα»). */
class AdminDensityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]));
    }

    public function test_the_panel_uses_the_whole_width_and_the_sidebar_folds_away(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame(Width::Full, $panel->getMaxContentWidth());
        $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
    }

    public function test_a_list_shows_50_rows_a_page(): void
    {
        $table = $this->tableOf(ListClients::class);

        $this->assertSame([25, 50, 100, 200], $table->getPaginationPageOptions());
        $this->assertSame(50, $table->getDefaultPaginationPageOption());
    }

    /** A table with its own options keeps its own default, never 50 where 50 is not offered. */
    public function test_a_table_with_its_own_options_keeps_its_default(): void
    {
        foreach ([ServicesToCheck::class => [10, 25], DomainRenewals::class => [10, 25, 50]] as $widget => $options) {
            $table = $this->tableOf($widget);

            $this->assertSame($options, $table->getPaginationPageOptions(), $widget);
            $this->assertSame(10, $table->getDefaultPaginationPageOption(), $widget);
        }
    }

    /** @param class-string $component */
    private function tableOf(string $component): Table
    {
        $instance = Livewire::test($component)->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }
}

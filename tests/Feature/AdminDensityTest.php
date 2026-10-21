<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServicesToCheck;
use App\Models\Client;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Components\ComponentManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
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
        // A list of problems mounts only while there is one: a service with no due date is one.
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'expires_at' => null, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        foreach ([ServicesToCheck::class => [10, 25], DomainRenewals::class => [10, 25, 50]] as $widget => $options) {
            $table = $this->tableOf($widget);

            $this->assertSame($options, $table->getPaginationPageOptions(), $widget);
            $this->assertSame(10, $table->getDefaultPaginationPageOption(), $widget);
        }
    }

    /**
     * Through real requests (review #212): the panel boots on a Livewire update too, so a list keeps its options after
     * the first click. Between the two requests the scoped Filament state is reset, as in a new request, so what the
     * update shows comes from its own boot.
     */
    public function test_a_livewire_update_keeps_the_options(): void
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
        $session = [Auth::guard('staff')->getName() => $staff->id];
        Client::factory()->count(3)->create();

        $html = (string) $this->withSession($session)->get('/sysadmin/clients')->assertOk()->getContent();
        $snapshot = $this->tableSnapshot($html);
        $this->assertSame(50, json_decode($snapshot, true)['data']['tableRecordsPerPage']);

        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();
        ComponentManager::resolveScoped();
        $this->assertSame([5, 10, 25, 50], Table::make($this->tableOf(ListClients::class)->getLivewire())->getPaginationPageOptions(), 'the reset did not clear the options');
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();
        ComponentManager::resolveScoped();

        $component = $this->withSession($session)->withHeaders(['X-Livewire' => '1'])
            ->postJson('/mycs/update', ['components' => [['snapshot' => $snapshot, 'updates' => ['tableRecordsPerPage' => 200], 'calls' => []]]])
            ->assertOk()->json('components.0');

        $this->assertSame(200, json_decode($component['snapshot'], true)['data']['tableRecordsPerPage']);
        $this->assertMatchesRegularExpression('/<option[^>]*value="200"/', $component['effects']['html']);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="10"/', $component['effects']['html']);
    }

    private function tableSnapshot(string $html): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);
            if (array_key_exists('tableRecordsPerPage', json_decode($snapshot, true)['data'] ?? [])) {
                return $snapshot;
            }
        }
        $this->fail('no table on the page');
    }

    /** @param class-string $component */
    private function tableOf(string $component): Table
    {
        $instance = Livewire::test($component)->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Pages\Dashboard;
use App\Admin\Resources\ServerResource;
use App\Admin\Widgets\Servers;
use App\Admin\Widgets\Support;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The dashboard's «Servers» (2026-11-04): accounts against «max accounts», as the servers list counts them. */
class DashboardServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actAs(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    private function server(string $name, ?int $max, bool $enabled = true): Server
    {
        return Server::query()->create(['name' => $name, 'extension' => 'CPanel', 'type' => 'server', 'enabled' => $enabled, 'max_accounts' => $max]);
    }

    private function accounts(Server $server, int $count, string $status = Service::STATUS_ACTIVE): void
    {
        $product = $this->createProduct();
        $client = User::factory()->create()->client();
        foreach (range(1, $count) as $i) {
            Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'server_id' => $server->id, 'status' => $status, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 1]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return Livewire::test(Servers::class)->viewData('rows');
    }

    public function test_it_counts_as_the_servers_list_and_puts_the_fullest_first(): void
    {
        $this->actAs(['*']);
        $roomy = $this->server('Roomy', 10);
        $full = $this->server('Full', 4);
        $unlimited = $this->server('Unlimited', null);
        $this->accounts($roomy, 2);
        $this->accounts($roomy, 1, Service::STATUS_SUSPENDED);
        $this->accounts($roomy, 1, Service::STATUS_CANCELLED);
        $this->accounts($full, 4);
        $this->accounts($unlimited, 5);

        $rows = collect($this->rows())->keyBy('name');

        $this->assertSame(['Full', 'Roomy', 'Unlimited'], array_column($this->rows(), 'name'));
        $this->assertSame([$roomy->accountsCount(), 10, 30, 'primary'], [$rows['Roomy']['used'], $rows['Roomy']['max'], $rows['Roomy']['percent'], $rows['Roomy']['color']]);
        $this->assertSame(3, $rows['Roomy']['used'], 'active and suspended, not cancelled');
        $this->assertSame([4, 100, 'danger'], [$rows['Full']['used'], $rows['Full']['percent'], $rows['Full']['color']]);
        $this->assertSame([5, null, null], [$rows['Unlimited']['used'], $rows['Unlimited']['max'], $rows['Unlimited']['percent']]);
    }

    public function test_amber_when_nearly_full_and_a_disabled_server_is_left_out(): void
    {
        $this->actAs(['*']);
        $this->accounts($this->server('Nearly', 4), 3);
        $this->accounts($this->server('Off', 4, enabled: false), 1);

        $rows = $this->rows();

        $this->assertSame(['Nearly'], array_column($rows, 'name'));
        $this->assertSame('warning', $rows[0]['color']);
        Livewire::test(Servers::class)->assertSee('Nearly')->assertSee('3')->assertSee('/ 4')->assertDontSee('Off');
    }

    /** One count for all the servers: the same queries for one server as for five. */
    public function test_one_count_for_all_the_servers(): void
    {
        $this->actAs(['*']);
        $this->accounts($this->server('A', 10), 1);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->rows();
            $queries = collect(DB::getQueryLog())->filter(fn (array $q) => preg_match('/from [`"]?services\b/', $q['query']) === 1)->count();
            DB::disableQueryLog();

            return $queries;
        };
        $one = $count();
        foreach (['B', 'C', 'D', 'E'] as $name) {
            $this->accounts($this->server($name, 10), 1);
        }

        $this->assertGreaterThan(0, $one, 'the count is seen (quotes differ between SQLite and MariaDB)');
        $this->assertSame($one, $count());
    }

    public function test_over_the_limit_comes_before_exactly_full_and_red_starts_at_ninety_percent(): void
    {
        $this->actAs(['*']);
        $this->accounts($this->server('Exactly', 10), 10);
        $this->accounts($this->server('Over', 4), 6);
        $this->accounts($this->server('Almost', 1000), 896);

        $rows = collect($this->rows())->keyBy('name');

        $this->assertSame(['Over', 'Exactly', 'Almost'], $rows->keys()->all());
        $this->assertSame([100, 'danger'], [$rows['Over']['percent'], $rows['Over']['color']]);
        $this->assertSame([90, 'warning'], [$rows['Almost']['percent'], $rows['Almost']['color']], '89.6 %: shown 90, still amber');
    }

    /** A staff member who may see servers but not edit them gets the names without links. */
    public function test_names_link_only_for_staff_who_may_edit(): void
    {
        $this->actAs(['admin.servers.viewAny']);
        $server = $this->server('Readonly', 10);

        $rows = $this->rows();

        $this->assertNull($rows[0]['url']);
        Livewire::test(Servers::class)->assertSee('Readonly')->assertDontSee(ServerResource::getUrl('edit', ['record' => $server]));
    }

    public function test_staff_without_the_servers_permission_do_not_see_it(): void
    {
        $this->actAs(['admin.widgets.support']);

        $this->assertFalse(Servers::canView());
        $this->assertNotContains(Servers::class, (new Dashboard)->layout()['right']);
    }

    public function test_it_tops_the_right_column(): void
    {
        $this->actAs(['admin.servers.viewAny', 'admin.widgets.support']);

        $this->assertSame([Servers::class, Support::class], array_slice((new Dashboard)->layout()['right'], 0, 2));
    }

    public function test_no_servers_says_so(): void
    {
        $this->actAs(['*']);

        Livewire::test(Servers::class)->assertSee(__('No servers yet'));
    }
}

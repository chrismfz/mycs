<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\DomainResource;
use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #401: the domain page's commands row and the client's domains. */
class DomainPageCompactAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->owner = User::factory()->create();
    }

    private function staff(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]), 'staff');
    }

    private function domain(string $fqdn, array $attributes = []): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);

        return Domain::factory()->create(['client_id' => $this->owner->clients()->firstOrFail()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, ...$attributes]);
    }

    /** ADV-1: the row's visibility is worked out once per render, not once per caller (main: 6 such queries a render). */
    public function test_the_commands_row_is_worked_out_once_per_render(): void
    {
        $this->staff(['*']);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => '.gr', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain('alpha.gr', ['registrar_connection_id' => $gr->id]);

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            $queries += str_contains($query->sql, 'domain_owner_changes') ? 1 : 0;
        });
        $this->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()->assertSee(__('Change holder'));

        // The header's own checks (main) plus at most one evaluation of the row; #401 evaluates it ~5 times (11).
        $this->assertLessThanOrEqual(7, $queries, "the commands row's visible() closures run {$queries} owner-change queries on one render");
    }

    /** ADV-2: staff who may not see clients are not offered a link to the client's Domains tab (the Client field is guarded). */
    public function test_no_link_to_the_clients_tab_without_clients_permission(): void
    {
        $this->staff(['admin.domains.viewAny']);
        $domain = $this->domain('alpha.gr');
        $this->domain('beta.gr');

        $this->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()
            ->assertSee(__("This client's domains"))
            ->assertDontSee(ClientResource::getUrl('domains', ['record' => $domain->client_id]), false);
    }

    /** Holds: a command opens the real action, and a staff without the permission is not shown it nor can mount it. */
    public function test_a_command_mounts_its_action_and_none_without_permission(): void
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => '.gr', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain('alpha.gr', ['registrar_connection_id' => $gr->id]);
        $this->domain('stranger-client.gr', ['client_id' => User::factory()->create()->clients()->firstOrFail()->id]);

        $this->staff(['*']);
        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->call('mountAction', 'nameservers')->assertActionMounted('nameservers');
        $this->get(DomainResource::getUrl('view', ['record' => $domain]))->assertOk()->assertDontSee('stranger-client.gr');

        $this->staff(['admin.domains.viewAny']);
        $page = Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()]);
        $this->assertSame([], $page->instance()->registrarCommands());
        $page->call('mountAction', 'nameservers')->assertActionNotMounted('nameservers');
    }
}

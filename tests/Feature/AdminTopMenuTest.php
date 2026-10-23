<?php

namespace Tests\Feature;

use App\Admin\TopMenu;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The WHMCS-like top menu follows the same permissions as the sidebar (docs/admin-ux.md). */
class AdminTopMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, list<string>> group => item labels */
    private function menuFor(Staff $user): array
    {
        $this->actingAs($user);

        return collect(TopMenu::groups())->mapWithKeys(fn ($g) => [$g['label'] => array_column($g['items'], 'label')])->all();
    }

    public function test_a_full_admin_gets_the_whole_whmcs_menu(): void
    {
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $menu = $this->menuFor($owner);

        $this->assertSame(['Clients', 'Orders', 'Billing', 'Support', 'Reports', 'Utilities', 'Setup'], array_keys($menu));
        $this->assertContains('Add New Client', $menu['Clients']);
        $this->assertContains('Create Invoice', $menu['Billing']);
        $this->assertContains('Open New Ticket', $menu['Support']);
    }

    public function test_a_support_role_sees_only_what_it_may_open(): void
    {
        $support = Staff::factory()->create(['role_id' => Role::create(['name' => 'Support', 'permissions' => [
            'admin.tickets.viewAny', 'admin.tickets.create', 'admin.clients.viewAny', 'admin.users.viewAny',
        ]])->id]);

        $menu = $this->menuFor($support);

        $this->assertSame(['Support Tickets', 'Open New Ticket', 'Ticket Mail Import Log'], $menu['Support']);
        // The accounts (Clients) and their logins (Users) apart since the split; each by its own permission.
        $this->assertSame(['View/Search Clients', 'Manage Users'], $menu['Clients']);
        $this->assertArrayNotHasKey('Billing', $menu);
        $this->assertArrayNotHasKey('Setup', $menu);
    }

    public function test_the_menu_renders_in_the_admin_topbar(): void
    {
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->actingAs($owner)->withSession($this->loginUser($owner))->get('/sysadmin')
            ->assertOk()
            ->assertSee('mycs-top-menu', false)
            ->assertSee('Add New Client');
    }
}

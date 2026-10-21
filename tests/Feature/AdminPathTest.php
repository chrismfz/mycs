<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin lives at /sysadmin, as in the company's other two systems (owner 2026-10-21); the old /admin leads there. */
class AdminPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_is_at_sysadmin_and_its_links_follow(): void
    {
        $this->assertSame('sysadmin', AdminPanelProvider::PATH);
        $this->assertSame('sysadmin', Filament::getPanel('admin')->getPath());
        $this->assertStringEndsWith('/sysadmin/login', Filament::getPanel('admin')->getLoginUrl());
        $this->get('/sysadmin')->assertRedirect('/sysadmin/login');
    }

    public function test_the_old_admin_leads_to_the_same_page_with_its_query(): void
    {
        $this->get('/admin')->assertStatus(301)->assertRedirect('/sysadmin');
        $this->get('/admin/')->assertRedirect('/sysadmin');
        $this->get('/admin/clients/12/edit?tab=services&page=2')->assertStatus(301)->assertRedirect('/sysadmin/clients/12/edit?tab=services&page=2');
    }

    public function test_the_redirect_stays_on_this_site(): void
    {
        $target = $this->get('/admin//evil.example/x')->headers->get('Location');

        $this->assertSame(url('/sysadmin//evil.example/x'), $target);
        $this->assertSame(parse_url(url('/'), PHP_URL_HOST), parse_url((string) $target, PHP_URL_HOST));
    }

    public function test_staff_land_in_the_panel_there(): void
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->actingAs($staff, 'staff')->get('/sysadmin')->assertOk();
        $this->actingAs($staff, 'staff')->get('/admin/clients')->assertRedirect('/sysadmin/clients');
        $this->actingAs($staff, 'staff')->get('/sysadmin/clients')->assertOk();
    }

    public function test_back_in_the_admin_a_login_as_client_ends(): void
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->actingAs($staff, 'staff')->withSession(['impersonating' => 5])->get('/sysadmin')->assertOk()->assertSessionMissing('impersonating');
    }
}

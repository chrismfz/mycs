<?php

namespace Tests\Feature;

use App\Admin\Resources\RoleResource;
use App\Admin\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review of #854: since Filament 5.11.2 the edit page asks the policy, not RoleResource::canEdit. The full admin role
 * (#1) must stay read-only even for a staff member who holds `*`, on the page and on a direct save.
 */
class Adv854RoleOneTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Role
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Role::findOrFail(1);
        $this->assertContains('*', (array) $admin->permissions);
        $this->actingAs(Staff::factory()->create(['role_id' => $admin->id]), 'staff');

        return $admin;
    }

    public function test_the_admin_role_edit_page_is_not_found_even_for_the_owner(): void
    {
        $admin = $this->owner();

        $this->get(RoleResource::getUrl('edit', ['record' => $admin]))->assertNotFound();
        Livewire::test(EditRole::class, ['record' => $admin->getRouteKey()])->assertNotFound();
    }

    public function test_another_role_is_still_editable_by_the_owner(): void
    {
        $this->owner();
        $ops = Role::create(['name' => 'Ops', 'permissions' => ['admin.tickets.viewAny']]);

        $this->get(RoleResource::getUrl('edit', ['record' => $ops]))->assertOk();
    }
}

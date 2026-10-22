<?php

namespace Tests\Feature;

use App\Admin\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #358 (Passport removed). */
class Adv358Test extends TestCase
{
    use RefreshDatabase;

    /**
     * deploy/update.sh runs under `set -Eeuo pipefail` and still calls `passport:keys` on any host without
     * storage/oauth-private.key (a new instance, e.g. myip): the command no longer exists, so the update stops after
     * migrate and leaves the app in maintenance mode. Every artisan command the script names must exist.
     */
    public function test_every_artisan_command_in_the_deploy_script_exists(): void
    {
        $script = (string) file_get_contents(base_path('deploy/update.sh'));
        preg_match_all('/^\s*(?:if\s+!?\s*)?\$ART\s+([a-z][a-z0-9:-]*)/m', $script, $m);
        $this->assertNotEmpty($m[1]);
        $known = array_keys(Artisan::all());
        foreach (array_unique($m[1]) as $command) {
            $this->assertContains($command, $known, "deploy/update.sh runs `artisan {$command}`, which does not exist");
        }
    }

    /** A role saved before #358 still carries admin.oauth_clients.*: editing it must still save. */
    public function test_a_role_with_a_removed_permission_can_still_be_edited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]), 'staff');
        $role = Role::create(['name' => 'Ops', 'permissions' => ['admin.tickets.viewAny', 'admin.oauth_clients.viewAny']]);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['name' => 'Ops 2'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Ops 2', $role->fresh()->name);
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ekdosi's two choices of the account (owner 2026-10-24): staff set them on the client's page; kept for the bridge. */
class EkdosiClientFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_set_them_on_the_clients_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $this->assertSame([false, false], [$client->fresh()->ignore_invoicing, $client->fresh()->immediate_invoicing], 'off by default');
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertSee('Fiscal invoicing (Ekdosi)')
            ->fillForm(['ignore_invoicing' => true, 'immediate_invoicing' => true])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([true, true], [$client->fresh()->ignore_invoicing, $client->fresh()->immediate_invoicing]);
    }
}

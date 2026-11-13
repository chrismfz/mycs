<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clients' and users' lists (owner 2026-11-13): «Last login», the latest sign-in among its logins (a user's among
 * its accounts), sorted to find the quiet ones; never signed in sorts as the oldest.
 */
class ClientsLastLoginColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_and_users_sort_by_their_last_login(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        [$recent, $old, $never] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        ClientUser::query()->where('user_id', $recent->id)->update(['last_login_at' => '2026-11-12 10:00:00']);
        ClientUser::query()->where('user_id', $old->id)->update(['last_login_at' => '2025-01-01 10:00:00']);
        // A second login of the old one's account, more recent: the account's last login is that one.
        $old->client()->grants()->create(['user_id' => $never->id, 'owner' => false, 'last_login_at' => '2026-06-01 10:00:00']);
        [$a, $b, $c] = [$recent->client(), $old->client(), $never->client()];
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.users.viewAny'])->create();

        $this->livewireAs($staff)->test(ListClients::class)
            ->assertTableColumnExists('last_login_at')
            ->sortTable('last_login_at', 'desc')->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
            ->sortTable('last_login_at', 'asc')->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
        // A login removed from the account: its sign-in no longer counts, as in the Summary.
        $c->grants()->create(['user_id' => $recent->id, 'owner' => false, 'last_login_at' => '2026-11-13 09:00:00', 'revoked_at' => '2026-11-13 09:30:00']);
        $this->livewireAs($staff)->test(ListClients::class)
            ->sortTable('last_login_at', 'desc')->assertCanSeeTableRecords([$a, $b, $c], inOrder: true);

        $this->livewireAs($staff)->test(ListUsers::class)
            ->sortTable('last_login_at', 'desc')->assertCanSeeTableRecords([$recent, $never, $old], inOrder: true);
    }
}

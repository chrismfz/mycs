<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Pre-launch audit, flow 3 (docs/prelaunch-audit.md): auth, permissions, external surface. `test_bug_*` fail on `main`
 * as reviewed (147b9496) and describe a real issue.
 *
 * AUD3-1: a member of staff with only «Update Clients» (admin.clients.update) gives a customer login of their own every
 * permission on any account (Users tab: «Add existing user», «Invite user», «Make owner»). Logged in to the client area
 * with it, they then sign in to the customer's panels (`productsso`: in the admin «Log in to panel», which needs
 * admin.services.sso and admin.services.update, ADV-2 of #465) and manage, cancel or open the console of the services
 * (`manageproducts`: admin.services.update), though their role has neither. «Login as client» (SA-5) refuses them both.
 */
class AuditFlow3Test extends TestCase
{
    use RefreshDatabase;

    /** What «Login as client» would refuse a role without admin.services.sso / admin.services.update (Actor::STAFF_PERMISSION_AS). */
    private const BEYOND_THE_ROLE = ['productsso', 'manageproducts'];

    private function clientsUpdateOnly(): Staff
    {
        return Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update'])->create();
    }

    private function usersTab(Staff $staff, Client $client)
    {
        return $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);
    }

    /** The permissions beyond the staff member's role that $user ends up with on $client. @return list<string> */
    private function beyondTheRole(Client $client, User $user): array
    {
        $grant = ClientUser::query()->where('client_id', $client->id)->where('user_id', $user->id)->whereNull('revoked_at')->first();

        return $grant === null ? [] : array_values(array_filter(self::BEYOND_THE_ROLE, fn (string $p) => $grant->allows($p)));
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            // A refusal is the fix.
        }
    }

    public function test_bug_aud3_1_clients_update_adds_own_login_with_sso_and_manage(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = $this->clientsUpdateOnly();
        $own = User::factory()->withoutClient()->create(); // the staff member's own customer login, registered on the site

        $this->attempt(fn () => $this->usersTab($staff, $client)->callTableAction('add', data: ['user_id' => $own->id, 'all' => true]));

        $this->assertSame([], $this->beyondTheRole($client, $own),
            'Update Clients alone gave a login the client-area permissions that need Log in to panel / Update Services in the admin.');
    }

    public function test_bug_aud3_1_clients_update_invites_own_address_with_sso_and_manage(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = $this->clientsUpdateOnly();
        $own = User::factory()->withoutClient()->create();

        $this->attempt(fn () => $this->usersTab($staff, $client)->callTableAction('invite', data: ['email' => $own->email, 'all' => true]));
        $invite = ClientInvite::query()->where('client_id', $client->id)->where('email', strtolower($own->email))->latest('id')->first();
        if ($invite !== null && $invite->isPending()) {
            $invite->accept($own); // the link mailed to their own address
        }

        $this->assertSame([], $this->beyondTheRole($client, $own),
            'Update Clients alone invited a login with the client-area permissions that need Log in to panel / Update Services in the admin.');
    }

    public function test_bug_aud3_1_clients_update_makes_own_login_the_owner(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = $this->clientsUpdateOnly();
        $own = User::factory()->withoutClient()->create();
        // A grant within the role (no panel sign-in, no managing services), then «Make owner».
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $own->id, 'permissions' => ['tickets'], 'granted_at' => now()]);

        $this->attempt(fn () => $this->usersTab($staff, $client)->callTableAction('owner', $grant));

        $this->assertSame([], $this->beyondTheRole($client, $own),
            'Update Clients alone made a login the owner, with every client-area permission.');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Client\Users;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #519 (AUD3-1): the client area's «Users» page, used by staff through «Login as client», is a
 * second way to give a login every permission past the staff member's role.
 */
class Adv519Test extends TestCase
{
    use RefreshDatabase;

    /** Update Clients and Login as client, but no «Log in to panel», no Update Services, no Create Orders. */
    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update',
            'admin.users.viewAny', 'admin.users.impersonate'])->create();
    }

    /** Staff logged in as the account's owner. */
    private function loginAsClient(Staff $staff, User $owner, Client $client): void
    {
        $this->actingAs($owner)->withSession($this->loginUser($owner, ['impersonating' => $owner->id, CurrentClient::SESSION_KEY => $client->id]));
        $this->actingAs($staff, 'staff');
    }

    /** ADV-519-1: an existing limited login of the staff member's own is raised to every permission. */
    public function test_adv_519_1_login_as_client_users_page_raises_a_grant_past_the_role(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $staff = $this->staff();
        $mine = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $mine->id, 'permissions' => ['tickets'], 'granted_at' => now()]);

        $this->loginAsClient($staff, $owner, $client);
        $this->livewireAs($owner)->test(Users::class)
            ->call('edit', $grant->id)->set('editAll', true)->call('savePermissions');

        $permissions = $grant->fresh()->permissions;
        $this->assertNotNull($permissions, 'raised to all permissions (productsso, manageproducts, orders) past the role');
        $this->assertNotContains('productsso', $permissions);
    }

    /** ADV-519-2: an invitation to the staff member's own email with every permission, then accepted. */
    public function test_adv_519_2_login_as_client_invite_gives_every_permission_past_the_role(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.mail_must_verify' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create();
        $client = $owner->client();
        $staff = $this->staff();

        $this->loginAsClient($staff, $owner, $client);
        $this->livewireAs($owner)->test(Users::class)
            ->set('email', 'mine@example.test')->set('allPermissions', true)->call('invite');

        $invite = ClientInvite::query()->where('client_id', $client->id)->first();
        $this->assertTrue($invite === null || $invite->permissions !== null,
            'staff without «Log in to panel»/Update Services/Create Orders sent an all-permissions invitation');
    }
}

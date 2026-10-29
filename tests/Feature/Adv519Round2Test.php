<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #519, round 2: staff on the admin's Users tab give a login of their own permissions that, once
 * they log in with it (not impersonating), skip the admin checks «Login as client» makes.
 */
class Adv519Round2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions = []): Staff
    {
        return Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update', ...$permissions])->create();
    }

    private function grant(Client $client, User $user): ?ClientUser
    {
        return ClientUser::query()->where('client_id', $client->id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
    }

    /**
     * ADV-519-R2-1: `managedomains` maps to nothing, because the domain pages check admin.domains.* only while
     * impersonating (Domains\Show::staffMay). On a login of their own those checks are skipped: EPP code, nameservers,
     * lock, registrar contacts, all without any domain permission.
     */
    public function test_adv_519_r2_1_staff_without_domain_permissions_give_their_own_login_managedomains(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff();
        $ownLogin = User::factory()->withoutClient()->create(['email' => 'staff-own@example.test']);

        $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
            ->callTableAction('add', data: ['user_id' => $ownLogin->id, 'all' => false, 'permissions' => ['domains', 'managedomains']]);

        $this->assertNotContains('managedomains', $this->grant($client, $ownLogin)?->permissions ?? [],
            'a role without admin.domains.update/manage/epp gave its own login managedomains');
    }

    /** ADV-519-R2-1, the «all» toggle: a role with the service and order permissions, none of the domains', gives all. */
    public function test_adv_519_r2_1b_all_permissions_without_domain_permissions(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff(['admin.services.sso', 'admin.services.update', 'admin.orders.create', 'admin.invoices.update']);
        $ownLogin = User::factory()->withoutClient()->create();

        $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
            ->callTableAction('add', data: ['user_id' => $ownLogin->id, 'all' => true]);

        $grant = $this->grant($client, $ownLogin);
        $this->assertTrue($grant === null || ($grant->permissions !== null && !in_array('managedomains', $grant->permissions, true)),
            'all permissions (managedomains among them) given by a role with no domain permission');
    }

    /**
     * ADV-519-R2-2: the read permissions map to nothing «as any staff in Login as client could», but Login as client
     * takes admin.users.impersonate. A role with neither it nor admin.email_logs.view gives its own login `emails` and
     * reads the account's mail bodies (password resets, welcome mails with service logins).
     */
    public function test_adv_519_r2_2_staff_without_impersonate_or_mail_logs_give_their_own_login_emails(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff();
        $ownLogin = User::factory()->withoutClient()->create();

        $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
            ->callTableAction('add', data: ['user_id' => $ownLogin->id, 'all' => false, 'permissions' => ['emails']]);

        $this->assertNotContains('emails', $this->grant($client, $ownLogin)?->permissions ?? [],
            'a role that can neither log in as the client nor see mail logs gave its own login the account\'s mails');
    }
}

<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Livewire\Client\Users as ClientAreaUsers;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fixes of AUD3-1 and AUD3-2 (pre-launch audit, flow 3, docs/prelaunch-audit.md): the refusal stops at the role, and
 * a role that has the permissions is not refused; custom code and trusted proxies are a full admin's.
 */
class ClientUsersPastTheRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): Staff
    {
        return Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update', ...$permissions])->create();
    }

    private function usersTab(Staff $staff, Client $client)
    {
        return $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);
    }

    private function grant(Client $client, User $user): ?ClientUser
    {
        return ClientUser::query()->where('client_id', $client->id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
    }

    public function test_a_role_that_could_log_in_to_the_panel_and_manage_services_gives_every_permission(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff(['admin.services.sso', 'admin.services.update', 'admin.orders.create']);
        $login = User::factory()->withoutClient()->create();

        $this->usersTab($staff, $client)->callTableAction('add', data: ['user_id' => $login->id, 'all' => true]);

        $this->assertNull($this->grant($client, $login)?->permissions, 'all permissions');
        $this->assertNotNull($this->grant($client, $login));
    }

    public function test_a_role_without_them_gives_what_it_could_use_itself(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff([]);
        $login = User::factory()->withoutClient()->create();

        $this->usersTab($staff, $client)->callTableAction('add', data: ['user_id' => $login->id, 'all' => false, 'permissions' => ['tickets', 'invoices', 'profile']]);

        $this->assertEqualsCanonicalizing(['tickets', 'invoices', 'profile'], $this->grant($client, $login)->permissions);
    }

    public function test_a_permission_the_login_has_is_kept_when_another_is_added(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff([]);
        $login = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $login->id, 'permissions' => ['manageproducts'], 'granted_at' => now()]);

        $this->usersTab($staff, $client)->callTableAction('permissions', $grant, data: ['all' => false, 'permissions' => ['manageproducts', 'tickets']]);

        $this->assertEqualsCanonicalizing(['manageproducts', 'tickets'], $this->grant($client, $login)->permissions);
    }

    public function test_a_permission_past_the_role_is_refused_on_an_existing_login_and_nothing_changes(): void
    {
        $client = User::factory()->create()->client();
        $staff = $this->staff([]);
        $login = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $login->id, 'permissions' => ['tickets'], 'granted_at' => now()]);

        $this->usersTab($staff, $client)->callTableAction('permissions', $grant, data: ['all' => false, 'permissions' => ['tickets', 'productsso']]);

        $this->assertSame(['tickets'], $this->grant($client, $login)->permissions);
    }

    public function test_restoring_a_login_with_all_permissions_needs_a_role_that_could_use_them(): void
    {
        $client = User::factory()->create()->client();
        $login = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $login->id, 'permissions' => null, 'granted_at' => now(), 'revoked_at' => now()]);

        $this->usersTab($this->staff([]), $client)->filterTable('revoked', true)->callTableAction('restore', $grant);
        $this->assertNull($this->grant($client, $login), 'restored past the role');

        $this->usersTab($this->staff(['admin.services.sso', 'admin.services.update', 'admin.orders.create']), $client)->filterTable('revoked', true)->callTableAction('restore', $grant->fresh());
        $this->assertNotNull($this->grant($client, $login));
    }

    /** «Login as client» on $client's owner, as staff with these permissions besides impersonating. @param  list<string>  $permissions */
    private function loginAsClient(Client $client, array $permissions): void
    {
        $owner = $client->grants()->whereNotNull('owner_of_client_id')->sole()->user;
        session(['impersonating' => $owner->id, CurrentClient::SESSION_KEY => $client->id, CurrentClient::IMPERSONATING_KEY => $client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate', ...$permissions])->create());
        Auth::guard('web')->setUser($owner);
        app(CurrentClient::class)->forget();
    }

    public function test_in_login_as_client_an_invite_past_the_role_is_refused(): void
    {
        $client = User::factory()->create()->client();
        $this->loginAsClient($client, ['admin.clients.update']);

        Livewire::test(ClientAreaUsers::class)->set('email', 'mine@example.test')->set('allPermissions', true)->call('invite');

        $this->assertSame(0, ClientInvite::query()->where('client_id', $client->id)->count(), 'Update Clients alone invited a login with every permission');
    }

    public function test_in_login_as_client_widening_a_login_past_the_role_is_refused(): void
    {
        $client = User::factory()->create()->client();
        $login = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $login->id, 'permissions' => ['tickets'], 'granted_at' => now()]);
        $this->loginAsClient($client, ['admin.clients.update']);

        Livewire::test(ClientAreaUsers::class)->call('edit', $grant->id)->set('editAll', true)->call('savePermissions');

        $this->assertSame(['tickets'], $grant->fresh()->permissions);
    }

    public function test_in_login_as_client_a_role_that_could_use_them_invites_with_every_permission(): void
    {
        $client = User::factory()->create()->client();
        $this->loginAsClient($client, ['admin.clients.update', 'admin.services.sso', 'admin.services.update', 'admin.orders.create']);

        Livewire::test(ClientAreaUsers::class)->set('email', 'mine@example.test')->set('allPermissions', true)->call('invite');

        $invite = ClientInvite::query()->where('client_id', $client->id)->sole();
        $this->assertNull($invite->permissions);
    }

    /** @return list<string> */
    private function fullAdminOnly(): array
    {
        return ['theme_custom_header', 'trusted_proxies'];
    }

    public function test_custom_code_and_trusted_proxies_are_a_full_admins_alone(): void
    {
        // A role that had the old «custom code» permission, and settings.update.
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => [
            'admin.settings.view', 'admin.settings.update', 'admin.settings.custom_code',
        ]])->id]);

        $page = $this->livewireAs($staff)->test(Settings::class);
        foreach ($this->fullAdminOnly() as $name) {
            $field = collect($page->instance()->form->getFlatComponents(withHidden: true))
                ->first(fn ($component) => $component instanceof Field && $component->getName() === $name);
            $this->assertTrue($field->isDisabled(), $name . ' is not a full admin\'s alone');
        }
        $page->set('data.theme_custom_header', '<script>steal()</script>')->set('data.trusted_proxies', ['0.0.0.0/1'])->call('save');

        $stored = fn (string $key) => Setting::query()->whereNull('settingable_type')->where('key', $key)->value('value') ?: null;
        $this->assertNull($stored('theme_custom_header'));
        $this->assertNull($stored('trusted_proxies'));

        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $this->livewireAs($owner)->test(Settings::class)->set('data.theme_custom_header', '<p>hi</p>')->call('save')->assertHasNoErrors();
        $this->assertSame('<p>hi</p>', $stored('theme_custom_header'));
    }
}

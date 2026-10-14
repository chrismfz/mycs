<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Exceptions\DisplayException;
use App\Livewire\Client\AcceptInvite;
use App\Livewire\Client\Accounts;
use App\Livewire\Client\Users;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Several logins on one account (docs/accounts.md, PR 2b): the WHMCS permissions of a grant gate the client area;
 * the owner invites, sets permissions and takes access away; staff do the same and make another login the owner; a
 * login with several accounts switches between them.
 */
class ClientUsersTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, string>|null  $permissions */
    private function member(Client $client, ?array $permissions): User
    {
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => $permissions, 'granted_at' => now()]);

        return $user;
    }

    /** The member works on $client in the client area. */
    private function workOn(User $user, Client $client): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    private function serviceOf(Client $client): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    public function test_a_grant_opens_only_the_areas_it_allows(): void
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => 'open']);
        $service = $this->serviceOf($client);
        $billing = $this->member($client, ['invoices']);

        $this->workOn($billing, $client)->get(route('invoices'))->assertOk();
        $this->workOn($billing, $client)->get(route('invoices.show', $invoice))->assertOk();
        $this->workOn($billing, $client)->get(route('tickets'))->assertForbidden();
        $this->workOn($billing, $client)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->workOn($billing, $client)->get(route('services'))->assertForbidden();
        $this->workOn($billing, $client)->get(route('services.show', $service))->assertForbidden();
        $this->workOn($billing, $client)->get(route('account.credits'))->assertSuccessful();
    }

    public function test_viewing_services_is_not_managing_them(): void
    {
        $client = User::factory()->create()->client();
        $service = $this->serviceOf($client);
        $viewer = $this->member($client, ['products']);
        $manager = $this->member($client, ['products', 'manageproducts']);

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $service));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $service));
        $this->workOn($viewer, $client)->get(route('services.upgrade', $service))->assertForbidden();
        $this->assertTrue(Gate::forUser($manager)->allows('update', $service));
    }

    public function test_the_owner_and_a_grant_without_a_list_have_every_area(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $all = $this->member($client, null);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        foreach ([$owner, $all] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('view', $invoice));
            $this->workOn($user, $client)->get(route('tickets'))->assertOk();
        }
    }

    public function test_the_owner_invites_and_the_invitee_accepts_once(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create();
        $client = $owner->client();

        $this->workOn($owner, $client);
        $this->livewireAs($owner)->test(Users::class)
            ->set('email', 'Helper@Example.test')->set('allPermissions', false)->set('permissions', ['tickets', 'invoices'])
            ->call('invite')->assertHasNoErrors();

        $invite = ClientInvite::query()->sole();
        $this->assertSame('helper@example.test', $invite->email);
        $this->assertSame(['invoices', 'tickets'], $invite->permissions, 'stored in the WHMCS order');
        $this->assertSame(1, EmailLog::query()->where('to', 'helper@example.test')->where('client_id', $client->id)->count());

        // The link is the proof; a token for the test, since only its hash is stored.
        [$invite, $token] = ClientInvite::issue($client, 'helper@example.test', ['tickets'], $owner);
        $helper = User::factory()->create();
        $this->actingAs($helper)->withSession($this->loginUser($helper));
        $this->livewireAs($helper)->test(AcceptInvite::class, ['token' => $token])->call('accept');

        $grant = ClientUser::query()->where('client_id', $client->id)->where('user_id', $helper->id)->sole();
        $this->assertSame(['tickets'], $grant->permissions);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertSame(1, ClientInvite::query()->whereNotNull('revoked_at')->count(), 'the earlier invitation to the same email was replaced');

        $this->expectException(DisplayException::class);
        $invite->fresh()->accept(User::factory()->create());
    }

    public function test_an_expired_or_cancelled_invitation_gives_nothing(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [$expired, $expiredToken] = ClientInvite::issue($client, 'a@example.test', null, $owner);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        [$cancelled, $cancelledToken] = ClientInvite::issue($client, 'b@example.test', null, $owner);
        $cancelled->forceFill(['revoked_at' => now()])->save();
        $user = User::factory()->create();
        $this->actingAs($user)->withSession($this->loginUser($user));

        foreach ([$expiredToken, $cancelledToken, 'not-a-token'] as $token) {
            $this->livewireAs($user)->test(AcceptInvite::class, ['token' => $token])->call('accept');
        }

        $this->assertFalse($user->clients()->whereKey($client->id)->exists());
    }

    public function test_accepting_never_lowers_a_live_grant_and_brings_a_revoked_one_back(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, null);
        [$invite] = ClientInvite::issue($client, 'x@example.test', ['tickets'], $owner);
        $invite->accept($member);
        $this->assertNull(ClientUser::query()->where('user_id', $member->id)->value('permissions'));

        $client->revoke(ClientUser::query()->where('user_id', $member->id)->sole());
        [$again] = ClientInvite::issue($client, 'x@example.test', ['tickets'], $owner);
        $again->accept($member);
        $grant = ClientUser::query()->where('user_id', $member->id)->sole();
        $this->assertNull($grant->revoked_at);
        $this->assertSame(['tickets'], $grant->permissions);

        // The owner accepting an invitation to their own account stays the owner.
        [$own] = ClientInvite::issue($client, 'o@example.test', ['tickets'], $owner);
        $own->accept($owner);
        $this->assertTrue($client->fresh()->owner()->is($owner));
    }

    public function test_only_the_owner_manages_the_users_and_never_removes_themselves(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, null);

        $this->workOn($member, $client)->get(route('account.users'))->assertForbidden();

        $this->workOn($owner, $client);
        $page = $this->livewireAs($owner)->test(Users::class);
        $memberGrant = ClientUser::query()->where('user_id', $member->id)->sole();
        $page->call('edit', $memberGrant->id)->set('editAll', false)->set('editPermissions', ['invoices'])->call('savePermissions');
        $this->assertSame(['invoices'], $memberGrant->fresh()->permissions);

        $page->call('revoke', $memberGrant->id);
        $this->assertNotNull($memberGrant->fresh()->revoked_at);
        $this->assertFalse($member->clients()->whereKey($client->id)->exists());

        $ownerGrant = $client->ownerGrant;
        try {
            $this->livewireAs($owner)->test(Users::class)->call('revoke', $ownerGrant->id);
        } catch (\Throwable) {
        }
        $this->assertNull($ownerGrant->fresh()->revoked_at);
    }

    public function test_a_login_switches_only_to_its_own_accounts(): void
    {
        $user = User::factory()->create();
        $own = $user->client();
        $granted = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $granted->id, 'user_id' => $user->id, 'granted_at' => now()]);
        $foreign = User::factory()->create()->client();

        $this->workOn($user, $own);
        $this->livewireAs($user)->test(Accounts::class)->call('switch', $granted->id);
        $this->assertSame($granted->id, Session::get(CurrentClient::SESSION_KEY));

        $this->livewireAs($user)->test(Accounts::class)->call('switch', $foreign->id);
        $this->assertSame($granted->id, Session::get(CurrentClient::SESSION_KEY));
    }

    public function test_staff_make_another_login_the_owner(): void
    {
        $old = User::factory()->create();
        $client = $old->client();
        $member = $this->member($client, ['tickets']);

        $client->transferOwnership($member, Staff::factory()->admin()->create());

        $this->assertTrue($client->fresh()->owner()->is($member));
        $oldGrant = ClientUser::query()->where('user_id', $old->id)->where('client_id', $client->id)->sole();
        $this->assertFalse($oldGrant->owner);
        $this->assertNull($oldGrant->permissions, 'the old owner keeps every permission');
        $this->assertNull(ClientUser::query()->where('user_id', $member->id)->value('permissions'));
        $this->assertSame(1, ClientUser::query()->where('client_id', $client->id)->whereNotNull('owner_of_client_id')->count());
    }

    public function test_staff_add_set_and_revoke_access_from_the_account(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $other = User::factory()->withoutClient()->create();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update'])->create();
        $page = fn () => $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);

        $page()->callTableAction('add', data: ['user_id' => $other->id, 'all' => false, 'permissions' => ['invoices']])->assertHasNoTableActionErrors();
        $grant = ClientUser::query()->where('user_id', $other->id)->sole();
        $this->assertSame(['invoices'], $grant->permissions);
        $this->assertSame($staff->id, (int) $grant->granted_by);

        $page()->callTableAction('revoke', $grant);
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_staff_without_the_permission_see_no_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny'])->create();

        $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->assertTableActionHidden('add')
            ->assertTableActionHidden('invite');
    }
}

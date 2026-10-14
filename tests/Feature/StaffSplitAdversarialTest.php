<?php

namespace Tests\Feature;

use App\Admin\Resources\ApiResource\Pages\ManageApis;
use App\Admin\Resources\StaffResource\Pages\EditStaff;
use App\Admin\Resources\UserResource\Pages\EditUser;
use App\Livewire\Tickets\Show as ClientTicketShow;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Actor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Adversarial review of the staff split (docs/accounts.md, PR 1). Tests named test_holds_* pin guarantees that were
 * attacked and held; the others are findings (A0-A8), all fixed in this PR, and stay as regression tests. Findings table:
 * PR comment.
 */
class StaffSplitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** The session of a browser logged in to the admin only, as the staff guard stores it (no actingAs). */
    private function staffSession(Staff $staff, array $extra = []): array
    {
        return [Auth::guard('staff')->getName() => $staff->id] + $extra;
    }

    private function apiKey(?Staff $owner, array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::query()->create([
            'name' => 'adv', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => $permissions, 'staff_id' => $owner?->id,
        ]);

        return $token;
    }

    /** A customer and a member of staff with the same id. */
    private function twins(array $permissions = ['*']): array
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions($permissions)->create();
        DB::table('staff')->where('id', $staff->id)->update(['id' => $customer->id]);

        return [$customer, Staff::query()->findOrFail($customer->id)];
    }

    // ---------------------------------------------------------------- findings (fixed)

    /**
     * A0 (fixed in ccfd3717, kept as a regression test): «Login as client» did not work in a real request. `auth` is a priority middleware, sorted before
     * ImpersonateMiddleware (web group), so it finds no customer on the web guard and redirects to /login; the
     * existing StaffSplitTest is green only because actingAs($staff) leaves the default guard on `staff`.
     */
    public function test_login_as_client_opens_the_client_area_in_a_real_request(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();

        $this->withSession($this->staffSession($staff, ['impersonating' => $customer->id]))->get('/dashboard')->assertOk();
    }

    /**
     * A1 (fixed in ccfd3717, kept as a regression test): the admin API took `staff_id` from the request. A key of a support member signs a ticket reply as anyone
     * on the staff (the owner, a disabled member): the reply, its notification to the customer and DATA-3 history
     * all carry a forged author. The reply should be the key owner's (or refused).
     */
    public function test_api_ticket_reply_cannot_be_signed_as_another_staff_member(): void
    {
        $support = Staff::factory()->withPermissions(['admin.tickets.viewAny', 'admin.tickets.update'])->create();
        $owner = Staff::factory()->admin()->create(['first_name' => 'Owner']);
        $ticket = Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id]);

        $this->withToken($this->apiKey($support, ['admin.ticket_messages.create']))
            ->postJson('/api/v1/admin/ticket-messages', ['message' => 'Refund approved', 'ticket_id' => $ticket->id, 'staff_id' => $owner->id]);

        $forged = TicketMessage::query()->where('ticket_id', $ticket->id)->where('staff_id', $owner->id)->exists();
        $this->assertFalse($forged, 'a support key wrote a reply signed by the owner');
    }

    /**
     * A2: a key without an owner (staff_id null: keys made before keys had a creator, which the migration keeps) is
     * never clipped: it keeps every stored permission for ever, whoever leaves or is disabled. Before the split such a
     * key could at least not touch staff; now nothing bounds it. It should be refused (or disabled by the migration).
     */
    public function test_api_key_without_an_owner_is_not_unbounded(): void
    {
        User::factory()->create();

        $status = $this->withToken($this->apiKey(null, ['admin.users.view']))->getJson('/api/v1/admin/users')->status();

        $this->assertContains($status, [401, 403], 'an ownerless key still reads every customer');
    }

    /**
     * A3 (fixed in ccfd3717, kept as a regression test): session validation for the admin («ip_admin») was only in
     * the panel's non-persistent middleware. Every admin
     * action is a Livewire request to /paymenter/update, where only the web group's LockSession runs, before
     * UseStaffGuard, on the web guard: it sees nobody and checks nothing. A stolen admin cookie works from any IP for
     * Livewire requests (in main the web-group LockSession saw the admin, who was a User). Regression.
     */
    public function test_admin_session_lock_applies_to_admin_livewire_requests(): void
    {
        Config::set('settings.session_validation', 'ip_admin');
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny'])->create();

        $html = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withSession($this->staffSession($staff))
            ->get('/admin/users')
            ->assertOk()
            ->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = null;
        foreach ($m[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES);
            if (str_starts_with((string) (json_decode($decoded, true)['memo']['path'] ?? ''), 'admin/users')) {
                $snapshot = $decoded;
                break;
            }
        }
        $this->assertNotNull($snapshot, 'no admin component on the page');

        // The same session cookie, from another address.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Livewire' => '1'])
            ->postJson('/paymenter/update', ['components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => []]]]);

        $this->assertNotSame(200, $response->status(), 'the admin session answered from another IP');
    }

    /**
     * A4 (fixed in ccfd3717, kept as a regression test): «Back in the admin: the staff member is themselves again»
     * never happened. ImpersonateMiddleware is in the web
     * group, which the panel's routes do not use, so its /admin branch is dead: the flag stays, and the next visit to
     * the client area (Exit Admin, hours later) is still as the customer.
     */
    public function test_returning_to_the_admin_ends_login_as_client(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();

        $this->withSession($this->staffSession($staff, ['impersonating' => $customer->id]))->get('/admin');

        $this->assertFalse(Session::has('impersonating'), 'still logged in as the client after going back to the admin');
    }

    /**
     * A5: while logged in as a client, a reply the staff member writes in the customer's ticket is stored as the
     * customer's (user_id = customer, staff_id null): the thread shows the customer saying what staff wrote, and it
     * counts as a customer reply (status, notifications). Actor (audits) says staff; the ticket says customer.
     */
    public function test_a_ticket_reply_written_while_logged_in_as_client_is_the_staff_members(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();
        $ticket = Ticket::factory()->create(['client_id' => $customer->client()->id]);

        // A browser with the staff login and «Login as client»; the component's requests go through the web group
        // (ResolveUserSession, ImpersonateMiddleware), so the customer's session is a real one, not a guard set by hand.
        $this->withSession($this->loginUser($customer, $this->staffSession($staff, ['impersonating' => $customer->id])));

        Livewire::actingAs($customer)->test(ClientTicketShow::class, ['ticket' => $ticket])
            ->set('message', 'Please cancel my service')
            ->call('save');

        $message = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame($staff->id, $message->staff_id, 'stored as the customer\'s own words');
    }

    /**
     * A6 (nit): API keys belong to a staff member now, but what a key does is audited with no causer (Actor knows only
     * session guards): «who changed this customer» has no answer for API changes.
     */
    public function test_admin_api_changes_are_audited_as_the_keys_staff_member(): void
    {
        Config::set('audit.console', true);
        $owner = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.update'])->create();
        $customer = User::factory()->create(['first_name' => 'Before']);

        $this->withToken($this->apiKey($owner, ['admin.users.update']))
            ->putJson('/api/v1/admin/users/' . $customer->id, ['first_name' => 'After'])
            ->assertSuccessful();

        $audit = Audit::query()->where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)
            ->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($owner->getMorphClass(), $audit->user_type);
        $this->assertSame($owner->id, (int) $audit->user_id);
    }

    /**
     * A7 (nit): every other staff/role/key rule is «nobody acts on more power than they have» (outranks/canGrant),
     * but deleting an API key is not: a member with api_keys.delete removes the full admin's integration keys.
     */
    public function test_a_member_cannot_delete_an_api_key_beyond_their_own_permissions(): void
    {
        $support = Staff::factory()->withPermissions(['admin.api_keys.viewAny', 'admin.api_keys.delete'])->create();
        $owner = Staff::factory()->admin()->create();
        $this->apiKey($owner, ['admin.users.delete', 'admin.invoices.update']);
        $key = ApiKey::query()->where('staff_id', $owner->id)->firstOrFail();

        $this->assertFalse(Gate::forUser($support)->allows('delete', $key));
    }

    // ---------------------------------------------------------------- guarantees that held

    public function test_holds_a_customer_session_never_opens_the_admin_even_with_a_staff_members_id(): void
    {
        [$customer] = $this->twins(['*']);

        $this->withSession($this->loginUser($customer))->get('/admin')->assertRedirect('/admin/login');
        $this->assertFalse(Auth::guard('staff')->check());
    }

    public function test_holds_a_staff_session_never_opens_the_client_area_of_the_customer_with_its_id(): void
    {
        [$customer, $staff] = $this->twins(['*']);
        $invoice = Invoice::factory()->create(['client_id' => $customer->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        $this->withSession($this->staffSession($staff))->get(route('invoices.show', $invoice))->assertRedirect(route('login'));
        $this->withSession($this->staffSession($staff))->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_holds_a_disabled_member_with_an_open_session_is_shut_out(): void
    {
        $staff = Staff::factory()->admin()->create();
        $staff->update(['active' => false]);

        $this->withSession($this->staffSession($staff))->get('/admin')->assertForbidden();
    }

    public function test_holds_login_as_client_ends_when_the_member_is_disabled(): void
    {
        $own = User::factory()->create();
        $victim = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create(['active' => false]);

        // A member who is also a customer (two accounts, as the migration makes them), disabled while logged in as a client.
        $this->withSession($this->loginUser($own, $this->staffSession($staff, [Auth::guard('web')->getName() => $own->id, 'impersonating' => $victim->id])))
            ->get('/dashboard')->assertOk();

        $this->assertFalse(Session::has('impersonating'));
        $this->assertSame($own->id, Auth::guard('web')->id());
    }

    public function test_holds_the_login_as_client_action_needs_the_permission_even_when_called_directly(): void
    {
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.update'])->create();

        try {
            $this->livewireAs($staff)->test(EditUser::class, ['record' => $customer->getRouteKey()])->callAction('impersonate');
        } catch (\Throwable) {
        }

        $this->assertFalse(Session::has('impersonating'));
    }

    public function test_holds_a_new_staff_password_ends_their_other_sessions(): void
    {
        $staff = Staff::factory()->admin()->create();

        $this->withSession($this->staffSession($staff))->get('/admin')->assertOk();
        $staff->forceFill(['password' => Hash::make('a-brand-new-password')])->save();
        $this->app['auth']->forgetGuards();

        $this->get('/admin')->assertRedirect();
        $this->assertFalse(Session::has(Auth::guard('staff')->getName()), 'still logged in after the password changed');
    }

    /**
     * A8 (nit): a member whose session ends because their password changed (AuthenticateSession) is sent to the
     * customers' login page, not the admin's.
     */
    public function test_a_staff_session_ended_by_a_new_password_goes_to_the_admin_login(): void
    {
        $staff = Staff::factory()->admin()->create();

        $this->withSession($this->staffSession($staff))->get('/admin')->assertOk();
        $staff->forceFill(['password' => Hash::make('a-brand-new-password')])->save();
        $this->app['auth']->forgetGuards();

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_holds_admin_session_lock_applies_to_admin_pages(): void
    {
        Config::set('settings.session_validation', 'ip_admin');
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny'])->create();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withSession($this->staffSession($staff))->get('/admin/users')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/admin/users')->assertRedirect('/');
    }

    public function test_holds_a_customers_reset_token_does_not_reset_staff_with_the_same_email(): void
    {
        $customer = User::factory()->create(['email' => 'both@example.test']);
        $staff = Staff::factory()->admin()->create(['email' => 'both@example.test', 'password' => Hash::make('old-password-123')]);

        $token = Password::broker('users')->createToken($customer);

        $this->assertFalse(Password::broker('staff')->tokenExists($staff, $token));
        $status = Password::broker('staff')->reset(
            ['email' => 'both@example.test', 'token' => $token, 'password' => 'hijacked-pass-123', 'password_confirmation' => 'hijacked-pass-123'],
            fn (Staff $s, string $p) => $s->forceFill(['password' => Hash::make($p)])->save(),
        );
        $this->assertSame(Password::INVALID_TOKEN, $status);
        $this->assertTrue(Hash::check('old-password-123', $staff->fresh()->password));
    }

    public function test_holds_api_key_shrinks_with_its_owner(): void
    {
        User::factory()->create();
        $owner = Staff::factory()->withPermissions(['admin.users.viewAny'])->create();
        $token = $this->apiKey($owner, ['admin.users.view', 'admin.invoices.view']);

        $this->withToken($token)->getJson('/api/v1/admin/users')->assertOk();
        // invoices.view was never the owner's: clipped.
        $this->withToken($token)->getJson('/api/v1/admin/invoices')->assertForbidden();

        $owner->role->update(['permissions' => []]);
        $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden();

        $owner->role->update(['permissions' => ['admin.users.viewAny']]);
        $owner->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_holds_a_member_cannot_give_themselves_a_bigger_role(): void
    {
        $support = Staff::factory()->withPermissions(['admin.staff.viewAny', 'admin.staff.update'])->create();
        $mine = $support->role_id;
        $admin = Role::query()->create(['name' => 'Everything', 'permissions' => ['*']]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs($support)->test(EditStaff::class, ['record' => $support->getRouteKey()])
            ->set('data.role_id', $admin->id)
            ->set('data.active', true)
            ->call('save');

        $this->assertSame($mine, $support->fresh()->role_id);
    }

    public function test_holds_in_the_client_area_without_login_as_client_the_actor_is_the_customer(): void
    {
        [$customer, $staff] = $this->twins(['*']);
        Auth::guard('staff')->setUser($staff);
        Auth::guard('web')->setUser($customer);
        Auth::shouldUse('web');

        $actor = Actor::current();
        $this->assertInstanceOf(User::class, $actor);
        $this->assertSame($customer->id, $actor->id);
    }

    public function test_holds_an_api_key_made_in_the_admin_is_the_staff_members_not_the_customer_with_its_id(): void
    {
        [$customer, $staff] = $this->twins(['*']);
        $other = Staff::factory()->admin()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs($other)->test(ManageApis::class)
            ->callAction('create', data: ['name' => 'integration', 'permissions' => ['admin.users.view']])
            ->assertHasNoActionErrors();

        $this->assertSame($other->id, (int) ApiKey::query()->where('name', 'integration')->value('staff_id'));
    }

    public function test_holds_the_migration_keeps_the_admins_2fa(): void
    {
        $clients = require database_path('migrations/2026_10_28_000000_split_clients_from_users.php');
        $clients->down();
        $migration = require database_path('migrations/2026_10_26_000000_split_staff_from_users.php');
        $migration->down();

        $role = Role::query()->create(['name' => 'Old admin', 'permissions' => ['*']]);
        $id = DB::table('users')->insertGetId([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test', 'password' => Hash::make('pw'),
            'role_id' => $role->id, 'tfa_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $clients->up();

        $this->assertSame('JBSWY3DPEHPK3PXP', Staff::query()->findOrFail($id)->getAppAuthenticationSecret());
    }
}

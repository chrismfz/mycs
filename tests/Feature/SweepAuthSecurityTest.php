<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentClient;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Sweep 2026-11-07, area auth-security: adversarial verification of the finder's candidates. Fake data only. */
class SweepAuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AUTH-SECURITY-1: the one-click unsubscribe POST (RFC 8058) carries no session token. Its route excludes
     * ValidateCsrfToken, a deprecated subclass of PreventRequestForgery, so the web group's PreventRequestForgery stays.
     */
    public function test_auth_security_1_one_click_unsubscribe_post_skips_the_csrf_check(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $user->id]);

        $route = Route::getRoutes()->match(Request::create($url, 'POST'));
        $this->assertNotContains(PreventRequestForgery::class, app('router')->gatherRouteMiddleware($route),
            'the signed one-click unsubscribe POST still runs the CSRF middleware');
    }

    /** The same, end to end: the CSRF check as it runs outside unit tests answers a mail provider's POST with 419. */
    public function test_auth_security_1_a_mail_providers_one_click_post_is_not_refused_with_419(): void
    {
        $this->app->bind(PreventRequestForgery::class, StrictPreventRequestForgery::class);
        $user = User::factory()->create();
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $user->id]);

        $response = $this->call('POST', $url, ['List-Unsubscribe' => 'One-Click']);

        $this->assertNotSame(419, $response->status(), 'a mail provider\'s one-click unsubscribe is refused as CSRF');
    }

    private function impersonate(User $owner, array $permissions): Staff
    {
        $staff = Staff::factory()->withPermissions(array_merge(['admin.users.impersonate'], $permissions))->create();
        $this->actingAs($staff, 'staff');
        $this->actingAs($owner)->withSession($this->loginUser($owner, ['impersonating' => $owner->id,
            CurrentClient::SESSION_KEY => $owner->client()->id, CurrentClient::IMPERSONATING_KEY => $owner->client()->id]));
        session(['impersonating' => $owner->id, CurrentClient::SESSION_KEY => $owner->client()->id, CurrentClient::IMPERSONATING_KEY => $owner->client()->id]);
        Auth::guard('staff')->setUser($staff);
        Auth::guard('web')->setUser($owner);

        return $staff;
    }

    /**
     * AUTH-SECURITY-2: staff with no ticket permission in the admin (no admin.tickets.update) reply as staff and close
     * the ticket through «Login as client» (SA-5: only what they may do in the admin).
     */
    public function test_auth_security_2_staff_without_ticket_rights_do_not_reply_or_close_through_login_as_client(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $owner->client()->id, 'status' => Ticket::STATUS_OPEN]);
        $staff = $this->impersonate($owner, ['admin.clients.update']);
        $this->assertFalse($staff->hasPermission('admin.tickets.update'));

        try {
            Livewire::test(TicketShow::class, ['ticket' => $ticket])->set('message', 'A reply staff may not write')->call('save');
        } catch (\Throwable) {
        }
        try {
            Livewire::test(TicketShow::class, ['ticket' => $ticket])->call('closeTicket');
        } catch (\Throwable) {
        }

        $this->assertFalse(TicketMessage::query()->where('ticket_id', $ticket->id)->where('staff_id', $staff->id)->exists(),
            'staff without admin.tickets.update replied as staff through «Login as client»');
        $this->assertNotSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status, 'staff without admin.tickets.update closed the ticket');
    }

    /** AUTH-SECURITY-2, the other side: staff holding admin.tickets.update still reply as themselves and close. */
    public function test_auth_security_2_staff_with_ticket_rights_still_reply_and_close_through_login_as_client(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $owner->client()->id, 'status' => Ticket::STATUS_OPEN]);
        $staff = $this->impersonate($owner, ['admin.tickets.update']);

        Livewire::test(TicketShow::class, ['ticket' => $ticket])->set('message', 'A staff reply')->call('save')->assertHasNoErrors();
        Livewire::test(TicketShow::class, ['ticket' => $ticket])->call('closeTicket');

        $this->assertTrue(TicketMessage::query()->where('ticket_id', $ticket->id)->where('staff_id', $staff->id)->exists());
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
    }

    /** AUTH-SECURITY-2, opening: staff without admin.tickets.create open no ticket through «Login as client». */
    public function test_auth_security_2_staff_without_create_rights_open_no_ticket_through_login_as_client(): void
    {
        $owner = User::factory()->create();
        $this->impersonate($owner, ['admin.tickets.update']);

        $department = TicketDepartment::query()->offered()->first() ?? TicketDepartment::create(['name' => 'Support', 'sort' => 0, 'hidden' => false]);
        Livewire::test(TicketCreate::class)->set('department', $department->id)->set('subject', 'Not theirs')->set('message', 'Refused')
            ->set('priority', 'low')->call('create')->assertForbidden();

        $this->assertFalse(Ticket::query()->where('subject', 'Not theirs')->exists());
    }

    /** A customer's own ticket stays the customer's words. */
    public function test_auth_security_3_a_customers_own_ticket_is_their_message(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->withSession($this->loginUser($owner));

        $department = TicketDepartment::query()->offered()->first() ?? TicketDepartment::create(['name' => 'Support', 'sort' => 0, 'hidden' => false]);
        Livewire::test(TicketCreate::class)->set('department', $department->id)->set('subject', 'Mine')->set('message', 'Words of the customer')
            ->set('priority', 'low')->call('create')->assertHasNoErrors();

        $message = TicketMessage::query()->where('message', 'Words of the customer')->sole();
        $this->assertSame($owner->id, (int) $message->user_id);
        $this->assertNull($message->staff_id);
    }

    /** AUTH-SECURITY-3: a ticket staff open through «Login as client» is the staff member's words, never the customer's. */
    public function test_auth_security_3_a_ticket_opened_by_staff_as_the_client_is_not_the_customers_message(): void
    {
        $owner = User::factory()->create();
        $staff = $this->impersonate($owner, ['admin.tickets.create', 'admin.tickets.update']);

        $department = TicketDepartment::query()->offered()->first() ?? TicketDepartment::create(['name' => 'Support', 'sort' => 0, 'hidden' => false]);
        Livewire::test(TicketCreate::class)->set('department', $department->id)->set('subject', 'Opened by staff')->set('message', 'Words of staff')
            ->set('priority', 'low')->call('create')->assertHasNoErrors();

        $message = TicketMessage::query()->where('message', 'Words of staff')->sole();
        $this->assertNull($message->user_id, 'the opening message is recorded as the customer\'s');
        $this->assertSame($staff->id, (int) $message->staff_id);
    }

    /**
     * AUTH-SECURITY-4: a login whose only open account's grant is revoked falls back to its closed account and works
     * on it (add funds, tickets), where a login with only closed accounts may not even sign in (WG-8).
     */
    public function test_auth_security_4_after_a_revoke_the_client_area_does_not_fall_back_to_a_closed_account(): void
    {
        $user = User::factory()->create();
        $own = $user->clients()->sole();
        $other = Client::factory()->ownedBy(User::factory()->create())->create();
        $grant = $other->grantTo($user, null);
        $own->update(['closed_at' => now()]);
        $this->assertFalse($user->fresh()->closedOut());

        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $other->id]));
        $this->get(route('dashboard'))->assertOk();

        $other->revoke($grant);
        app(CurrentClient::class)->forget();

        $this->get(route('dashboard'));
        $this->assertNotSame($own->id, CurrentClient::id(), 'the client area now works on the closed account');
    }

    /** AUTH-SECURITY-4, at the root: a revoke that leaves only closed accounts ends the sessions; one that does not, keeps them. */
    public function test_auth_security_4_a_revoke_ends_sessions_only_when_it_leaves_closed_accounts_only(): void
    {
        $closedOut = User::factory()->create();
        $closedOut->clients()->sole()->update(['closed_at' => now()]);
        $stillOpen = User::factory()->create();
        $other = Client::factory()->ownedBy(User::factory()->create())->create();
        $grants = [$other->grantTo($closedOut, null), $other->grantTo($stillOpen, null)];
        foreach ([$closedOut, $stillOpen] as $user) {
            UserSession::create(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
                'last_activity' => now(), 'expires_at' => now()->addDays(30)]);
        }

        $other->revoke($grants[0]);
        $other->revoke($grants[1]);

        $this->assertFalse(UserSession::query()->where('user_id', $closedOut->id)->exists());
        $this->assertTrue(UserSession::query()->where('user_id', $stillOpen->id)->exists());
    }

    /** AUTH-SECURITY-5, the other side: an API update without a password keeps the customer's sessions. */
    public function test_auth_security_5_an_admin_api_update_without_a_password_keeps_the_sessions(): void
    {
        $role = Role::create(['name' => 'Sweep staff', 'permissions' => ['admin.users.viewAny', 'admin.users.update']]);
        $staff = Staff::factory()->create(['role_id' => $role->id]);
        $customer = User::factory()->create();
        UserSession::create(['user_id' => $customer->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'last_activity' => now(), 'expires_at' => now()->addDays(30)]);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'sweep', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.users.update'], 'staff_id' => $staff->id]);

        $this->withToken($token)->putJson(route('api.v1.admin.users.update', $customer), ['first_name' => 'Renamed'])->assertOk();

        $this->assertTrue(UserSession::query()->where('user_id', $customer->id)->exists());
    }

    /** AUTH-SECURITY-6, the other side: wrong passwords still lock the login after 5. */
    public function test_auth_security_6_wrong_passwords_still_throttle_the_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Right-Pass-1'), 'tfa_secret' => 'JBSWY3DPEHPK3PXP']);
        request()->server->set('REMOTE_ADDR', '203.0.113.51');

        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Wrong-Pass-' . $i)->call('submit');
        }
        session()->forget('2fa');
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')->call('submit')->assertHasErrors('email');
        $this->assertNull(session('2fa'));
    }

    /**
     * AUTH-SECURITY-5: a password set through the admin API (as in the admin page, the reset and the command) ends the
     * customer's sessions and «Remember me» (AUTH-18).
     */
    public function test_auth_security_5_a_password_set_through_the_admin_api_ends_the_customers_sessions(): void
    {
        $role = Role::create(['name' => 'Sweep staff', 'permissions' => ['admin.users.viewAny', 'admin.users.update']]);
        $staff = Staff::factory()->create(['role_id' => $role->id]);
        $customer = User::factory()->create();
        UserSession::create(['user_id' => $customer->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'last_activity' => now(), 'expires_at' => now()->addDays(30)]);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'sweep', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'permissions' => ['admin.users.update'], 'staff_id' => $staff->id]);

        $this->withToken($token)->putJson(route('api.v1.admin.users.update', $customer), ['password' => 'NewPass12345'])->assertOk();

        $this->assertTrue(Hash::check('NewPass12345', $customer->fresh()->password));
        $this->assertFalse(UserSession::query()->where('user_id', $customer->id)->exists(),
            'the customer\'s session and «Remember me» outlive a password set through the admin API');
    }

    /** AUTH-SECURITY-6: correct sign-ins of a 2FA login are not counted towards its 5-in-15-minutes limit. */
    public function test_auth_security_6_correct_passwords_of_a_2fa_login_do_not_throttle_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Right-Pass-1'), 'tfa_secret' => 'JBSWY3DPEHPK3PXP']);
        request()->server->set('REMOTE_ADDR', '203.0.113.50');

        for ($i = 1; $i <= 6; $i++) {
            session()->forget('2fa');
            $test = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')->call('submit');
            $this->assertSame([], $test->errors()->toArray(), 'correct password #' . $i);
            $this->assertNotNull(session('2fa'), 'correct password #' . $i . ' of a 2FA login did not reach the 2FA step');
        }
    }
}

/** The CSRF middleware as it runs in production: not skipped for unit tests. */
class StrictPreventRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

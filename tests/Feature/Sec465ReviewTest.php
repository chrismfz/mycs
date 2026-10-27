<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Security review of #465 (after its independent review and two adversarial rounds). test_secN_* fail until fixed.
 *
 * Common thread: «Login as client» runs on the staff member's session, but in the client area only
 * ImpersonateMiddleware looks at that session (staff guard + `admin.users.impersonate`). The admin's own session checks
 * (LockSession's admin rules, AuthenticateSession's password hash) run in the admin only. Since #465 the impersonation
 * stays on while staff keep working in the admin (D1), so a staff cookie carries a live `impersonating` for hours, and
 * those checks are what would stop a stolen one.
 */
class Sec465ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['settings.default_currency' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = User::factory()->create();
    }

    private function impersonatingStaff(array $session = [], ?Staff $staff = null): Staff
    {
        $staff ??= Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate'])->create();
        $this->actingAs($staff, 'staff');
        $client = $this->owner->client();
        $this->withSession($session + [CurrentClient::SESSION_KEY => $client->id, 'impersonating' => $this->owner->id,
            CurrentClient::IMPERSONATING_KEY => $client->id]);

        return $staff;
    }

    /**
     * SEC-1: the IP lock of admin sessions («Session validation»: ip_admin, ip_both, and the user-agent modes alike) does
     * not cover staff in «Login as client». The admin refuses the staff session from another address (shown first: it
     * logs it out), but the client area runs LockSession before ImpersonateMiddleware, when the web guard has nobody, so
     * it skips the check; and even after it, it would apply the client's rules, not the admin's. A staff cookie stolen
     * while an impersonation is on (now: for as long as staff work in the admin, D1) acts as the customer from anywhere,
     * with the staff member's rights there (pay with the customer's saved card under `admin.invoices.update`, sign into
     * cPanel under `admin.services.sso`), as long as the thief never opens an admin page.
     *
     * Fix: when ImpersonateMiddleware puts the customer on, check the session with the admin's rules (LockSession's
     * admin modes, against the `login_ip`/`login_ua` the admin stored), and on a mismatch log the staff member out as the
     * admin does; or have LockSession treat a session with `impersonating` as an admin one.
     */
    public function test_sec1_the_admin_session_ip_lock_also_covers_staff_logged_in_as_a_client(): void
    {
        $this->storeSettings(['session_validation' => 'ip_admin']);

        // Control: the admin, from another address than the one it stored, logs the staff session out.
        $this->impersonatingStaff(['login_ip' => '198.51.100.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get(ClientResource::getUrl('view', ['record' => $this->owner->client()]))->assertRedirect('/');
        $this->assertGuest('staff');

        // The same kind of session, from the same other address, in the client area instead.
        $this->flushSession();
        $this->impersonatingStaff(['login_ip' => '198.51.100.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/dashboard');

        $this->assertNotSame($this->owner->id, Auth::guard('web')->id(), 'a staff session from another address acted as the customer');
        $this->assertFalse(Session::has('impersonating'), 'the impersonation outlived the admin session lock');
    }

    /**
     * SEC-2: a new staff password does not end «Login as client». The admin ends every other session of the member on
     * its next request (Filament's AuthenticateSession compares `password_hash_staff`), which is what an admin does on a
     * suspected stolen session; the client area never compares it, so the impersonation goes on there, and with D1 for as
     * long as the session is used. (Ending the session from «Sessions», #375, does end it: the staff guard checks that.)
     *
     * Fix: in ImpersonateMiddleware, also require the session's `password_hash_staff` to match the staff member's
     * current password (Auth::guard('staff')->hashPasswordForCookie(...), as AuthenticateSession validates it), else
     * forget `impersonating` (or log the staff member out).
     */
    public function test_sec2_a_new_staff_password_ends_login_as_client_too(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate'])->create();
        $hash = Auth::guard('staff')->hashPasswordForCookie($staff->getAuthPassword());
        $this->impersonatingStaff(['password_hash_staff' => $hash], $staff);

        // Sanity: before the change the client area is the customer.
        $this->get('/dashboard')->assertOk();
        $this->assertSame($this->owner->id, Auth::guard('web')->id());

        // An admin resets the member's password (a suspected stolen session).
        $staff->update(['password' => Hash::make('a-new-Password-123')]);
        Auth::guard('web')->forgetUser();

        $this->get('/dashboard');
        $this->assertNotSame($this->owner->id, Auth::guard('web')->id(), 'the impersonation outlived the staff password change');
    }

    /** Review of #469: staff who change their own password keep their «Login as client» (Filament stores the raw hash then). */
    public function test_their_own_new_password_keeps_login_as_client(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate'])->create();
        $staff->update(['password' => Hash::make('a-new-Password-123')]);
        $this->impersonatingStaff(['password_hash_staff' => $staff->refresh()->getAuthPassword()], $staff);

        $this->get('/dashboard')->assertOk();
        $this->assertSame($this->owner->id, Auth::guard('web')->id());
        $this->assertAuthenticatedAs($staff, 'staff');
    }
}

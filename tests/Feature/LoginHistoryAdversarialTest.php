<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Models\LoginAttempt;
use App\Models\Staff;
use App\Models\StaffSession;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CallerIp;
use App\Support\StaffSessions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #409 (Login History). */
class LoginHistoryAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.captcha' => 'disabled']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return list<string> */
    private function results(string $area): array
    {
        return LoginAttempt::query()->where('area', $area)->orderBy('id')->pluck('result')->all();
    }

    /** Control: the overridden rateLimit keeps Filament's key, so the 6th attempt is still blocked, even with the right password. */
    public function test_the_sixth_staff_attempt_is_still_blocked(): void
    {
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test', 'password' => 'Staff-Pass-1']);
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(StaffLogin::class)->set('data.email', 'ghost@myip.test')->set('data.password', 'Wrong-Pass-1')->call('authenticate');
        }
        $this->assertSame(5, RateLimiter::attempts('livewire-rate-limiter:' . sha1(StaffLogin::class . '|authenticate|' . CallerIp::key())));

        Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Staff-Pass-1')->call('authenticate');

        $this->assertGuest('staff');
        $this->assertSame(LoginAttempt::THROTTLED, LoginAttempt::query()->latest('id')->value('result'));
        $this->assertSame($staff->id, LoginAttempt::query()->latest('id')->value('staff_id'));
    }

    /**
     * FINDING: ending one's other sessions while this browser is remembered re-logs it in with the new token
     * (StaffSessions::end, Auth::guard('staff')->login($staff, true)): the Login listener writes a sign-in nobody made.
     */
    public function test_ending_ones_other_sessions_is_not_a_sign_in(): void
    {
        $staff = Staff::factory()->admin()->create(['password' => Hash::make('Right-Pass-123'), 'remember_token' => 'old-token']);
        Auth::guard('staff')->login($staff, true); // the real sign-in: one row
        $other = StaffSession::query()->create(['staff_id' => $staff->id, 'session_id' => 'other-session-id', 'ip_address' => '198.51.100.9',
            'user_agent' => 'Other Browser', 'remembered' => false, 'created_at' => now()->subHour(), 'last_seen_at' => now()->subMinute()]);

        StaffSessions::end($staff, [$other], $staff, request());

        $this->assertSame([LoginAttempt::SUCCESS], $this->results(LoginAttempt::STAFF), 'ending a session wrote a login row');
    }

    /**
     * FINDING (design): every request held back by the limits writes a row. Staff login has no captcha, so one address
     * past Filament's 5/min can write a row per request without bound (90 days of them): one row per address and
     * window is enough to show it was held back.
     */
    public function test_blocked_requests_do_not_each_write_a_row(): void
    {
        for ($i = 0; $i < 40; $i++) {
            Livewire::test(StaffLogin::class)->set('data.email', 'ghost@myip.test')->set('data.password', 'Wrong-Pass-1')->call('authenticate');
        }

        $this->assertLessThanOrEqual(1, LoginAttempt::query()->where('result', LoginAttempt::THROTTLED)->count(),
            'one row per blocked request: an unauthenticated flood of the table');
    }

    /** FINDING: Filament's own 2FA challenge limit (per member, across addresses) holds a request back unrecorded. */
    public function test_a_staff_2fa_challenge_held_back_is_kept(): void
    {
        $staff = Staff::factory()->admin()->create(['email' => 'chris@myip.test', 'password' => 'Staff-Pass-1']); // with 2FA
        $page = Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Staff-Pass-1')->call('authenticate');
        // The member's challenge limit reached (codes tried from other addresses).
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('filament:auth:multi_factor_challenge:rate_limit:' . Filament::getUserScopedAuthIdentifier($staff));
        }

        $page->set('data.multiFactor.app.code', '000000')->call('authenticate');

        $this->assertGuest('staff');
        $this->assertSame([LoginAttempt::THROTTLED], $this->results(LoginAttempt::STAFF));
    }

    /** FINDING: a customer back by «Remember me» is never in the history (staff's is, as Remembered). */
    public function test_a_customer_back_by_remember_me_is_kept(): void
    {
        config(['session.secure' => true, 'session.domain' => null]);
        $user = User::factory()->create();
        $remembered = UserSession::create(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'last_activity' => now(), 'expires_at' => now()->addDays(30)]);

        $this->withCookie('__Host-mycs_remember', $remembered->ulid)->get('/dashboard');
        $this->assertAuthenticatedAs($user, 'web');

        $this->assertSame([LoginAttempt::REMEMBERED], $this->results(LoginAttempt::CLIENT));
    }

    /** Control: a staff member back by «Remember me» is one Remembered row, not one per request. */
    public function test_a_staff_member_back_by_remember_me_is_one_row(): void
    {
        $staff = Staff::factory()->admin()->create(['remember_token' => 'tok-' . str_repeat('a', 56)]);
        $guard = Auth::guard('staff');
        $cookie = $staff->id . '|' . $staff->remember_token . '|' . $staff->getAuthPassword();

        $this->withCookie($guard->getRecallerName(), $cookie)->get(Filament::getPanel('admin')->getUrl());
        $this->get(Filament::getPanel('admin')->getUrl());

        $this->assertSame([LoginAttempt::REMEMBERED], $this->results(LoginAttempt::STAFF));
    }
}

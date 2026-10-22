<?php

namespace Tests\Feature;

use App\Admin\Auth\Login;
use App\Models\EmailLog;
use App\Models\Staff;
use App\Support\CallerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The console's way back in for a member of staff locked out of the admin (after 2FA became required). */
class StaffUnlockCommandTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->staff = Staff::factory()->admin()->create(['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@example.test', 'password' => Hash::make('Old-Pass-123'), 'remember_token' => 'tok']);
        $this->staff->saveAppAuthenticationRecoveryCodes(['a', 'b']);
    }

    private function lockOut(string $ip = '203.0.113.7'): string
    {
        for ($i = 0; $i < Login::ACCOUNT_FAILURES; $i++) {
            RateLimiter::hit('staff-login-account:' . $this->staff->id, 3600);
        }
        $addressKey = 'livewire-rate-limiter:' . sha1(Login::class . '|authenticate|' . CallerIp::bucket($ip));
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($addressKey, 60);
        }

        return $addressKey;
    }

    public function test_it_clears_the_login_limit_and_leaves_2fa_and_password_alone(): void
    {
        $this->lockOut();

        $this->artisan('app:staff:unlock', ['email' => 'Admin@Example.test', '--force' => true])->assertSuccessful();

        $this->assertSame(0, RateLimiter::attempts('staff-login-account:' . $this->staff->id));
        $fresh = $this->staff->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertTrue(Hash::check('Old-Pass-123', $fresh->password));
        $this->assertSame('tok', $fresh->remember_token);
        $this->assertSame(1, EmailLog::query()->where('subject', 'A member of staff was unlocked from the console')->count());
    }

    public function test_it_clears_an_addresss_limit_when_asked(): void
    {
        $addressKey = $this->lockOut('2001:db8:1:2::5');

        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--ip' => ['2001:db8:1:2::99'], '--force' => true])->assertSuccessful();

        $this->assertSame(0, RateLimiter::attempts($addressKey), 'the same /64');
    }

    public function test_it_resets_2fa_and_ends_remember_me(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--reset-2fa' => true, '--force' => true])->assertSuccessful();

        $fresh = $this->staff->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull($fresh->app_authentication_recovery_codes);
        $this->assertNull($fresh->remember_token);
    }

    public function test_it_sets_a_new_password_asked_twice(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--password' => true, '--force' => true])
            ->expectsQuestion('New password?', 'New-Pass-4567')
            ->expectsQuestion('Again?', 'New-Pass-4567')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('New-Pass-4567', $this->staff->fresh()->password));
        $this->assertNull($this->staff->fresh()->remember_token);
    }

    public function test_two_different_passwords_change_nothing(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--password' => true, '--force' => true])
            ->expectsQuestion('New password?', 'New-Pass-4567')
            ->expectsQuestion('Again?', 'Other-Pass-4567')
            ->assertFailed();

        $this->assertTrue(Hash::check('Old-Pass-123', $this->staff->fresh()->password));
    }

    public function test_it_asks_first_and_a_no_changes_nothing(): void
    {
        $this->lockOut();

        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--reset-2fa' => true])
            ->expectsConfirmation('Ada Admin <admin@example.test> (#' . $this->staff->id . '): login limit cleared, 2FA reset. Go ahead?', 'no')
            ->assertFailed();

        $this->assertTrue($this->staff->fresh()->hasTwoFactor());
        $this->assertSame(Login::ACCOUNT_FAILURES, RateLimiter::attempts('staff-login-account:' . $this->staff->id));
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_a_lookalike_or_unknown_email_unlocks_no_one(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'nobody@example.test', '--force' => true])->assertFailed();
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--ip' => ['not-an-ip'], '--force' => true])->assertFailed();
    }
}

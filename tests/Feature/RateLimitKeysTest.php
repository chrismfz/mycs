<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Livewire\Auth\Login;
use App\Models\Staff;
use App\Models\User;
use App\Support\CallerIp;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A throttle counts the caller as it reaches us, never an address it names (X-Forwarded-For), an IPv6 caller by its
 * /64, and a login across every address (security audit AUTHN-2).
 */
class RateLimitKeysTest extends TestCase
{
    use RefreshDatabase;

    private string $remote = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Every request Livewire::test() makes comes from $this->remote (review of #333: setting the current request's
        // address alone does not reach them).
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $this->remote));
    }

    private function caller(string $remote, ?string $forwarded = null): void
    {
        $this->remote = $remote;
        request()->server->set('REMOTE_ADDR', $remote);
        $forwarded === null ? request()->headers->remove('X-Forwarded-For') : request()->headers->set('X-Forwarded-For', $forwarded);
    }

    public function test_an_ipv6_caller_is_one_by_its_slash_64(): void
    {
        $this->assertSame(CallerIp::bucket('2001:db8:1:2::1'), CallerIp::bucket('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(CallerIp::bucket('2001:db8:1:2::1'), CallerIp::bucket('2001:db8:1:3::1'));
        $this->assertSame('203.0.113.7', CallerIp::bucket('203.0.113.7'));
    }

    public function test_a_forged_forwarded_address_gets_no_fresh_count_with_trust_all_proxies(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $first = null;
        foreach (['198.51.100.1', '198.51.100.2'] as $forged) {
            $this->caller('203.0.113.7', $forged);
            $first ??= CallerIp::key();
            $this->assertSame($first, CallerIp::key(), 'the caller names its own address');
        }
    }

    public function test_a_customer_login_is_limited_across_addresses(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Right-Pass-1')]);
        config(['settings.captcha' => 'disabled']);

        for ($i = 0; $i < 20; $i++) {
            $this->caller('203.0.113.' . ($i + 1));
            Livewire::test(Login::class)->set('email', $user->email)->set('password', 'guess-' . $i)->call('submit');
        }
        $this->caller('203.0.113.250');
        $page = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')->call('submit')->assertHasErrors('email');
        $this->assertStringStartsWith(strtok(__('auth.throttle', ['seconds' => 1]), '1'), (string) $page->errors()->first('email'));
        $this->assertFalse(auth('web')->check());
    }

    public function test_a_login_with_an_old_hash_is_a_failed_login_not_an_error(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => md5('legacy')]);
        config(['settings.captcha' => 'disabled']);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'legacy')->call('submit')
            ->assertHasErrors(['email' => __('auth.failed')]);
    }

    public function test_the_staff_login_counts_the_caller_not_a_forwarded_address(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create(['password' => Hash::make('Right-Pass-1')]);

        for ($i = 0; $i < 5; $i++) {
            $this->caller('203.0.113.7', '198.51.100.' . ($i + 1));
            Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'guess-' . $i)->call('authenticate');
        }
        $this->caller('203.0.113.7', '198.51.100.99');
        Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'Right-Pass-1')->call('authenticate');

        $this->assertFalse(auth('staff')->check(), 'a sixth try from the same caller, another forwarded address: still limited');
    }
}
